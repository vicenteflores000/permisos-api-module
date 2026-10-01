<?php

namespace Tests\Feature;

use App\Models\LogSistema;
use App\Models\PermisoSaldo;
use App\Models\PermisoSolicitud;
use App\Models\PermisoTrazabilidadFirma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermisosApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $apiKey = 'test_api_secret_key_insamu';

    protected function headers(): array
    {
        return [
            'X-API-SECRET-KEY' => $this->apiKey,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Valida que la API esté protegida con la clave simétrica compartida API_SECRET_KEY.
     */
    public function test_autenticacion_requiere_clave_simetrica(): void
    {
        // 1. Sin cabecera -> 401
        $response = $this->getJson('/api/ping');
        $response->assertStatus(401);

        // 2. Con clave errónea -> 401
        $response = $this->withHeaders(['X-API-SECRET-KEY' => 'clave_incorrecta'])
            ->getJson('/api/ping');
        $response->assertStatus(401);

        // 3. Con cabecera X-API-SECRET-KEY válida -> 200
        $response = $this->withHeaders(['X-API-SECRET-KEY' => $this->apiKey])
            ->getJson('/api/ping');
        $response->assertStatus(200)
            ->assertJsonPath('status', 'online');

        // 4. Con Authorization Bearer válido -> 200
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->getJson('/api/ping');
        $response->assertStatus(200);
    }

    /**
     * Valida la creación de solicitud, verificación y descuento de saldo,
     * generación de trazabilidad con token de deep linking y auditoría.
     */
    public function test_creacion_solicitud_y_descuento_de_saldo(): void
    {
        // Inicializar saldo de 6 días para el año 2026
        PermisoSaldo::create([
            'insamu_user_id' => 'USR_100',
            'anio' => 2026,
            'dias_totales' => 6.0,
            'dias_usados' => 0.0,
        ]);

        $payload = [
            'insamu_user_id' => 'USR_100',
            'nombre_solicitante' => 'Juan Pérez',
            'rut_solicitante' => '12.345.678-9',
            'cargo_solicitante' => 'Analista Clínico',
            'unidad_solicitante' => 'Laboratorio Central',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2026-05-10',
            'fecha_fin' => '2026-05-11',
            'dias_solicitados' => 2.0,
            'insamu_visador_id' => 'VIS_JEFE_01',
            'nombre_visador' => 'Dra. María González',
            'rut_visador' => '9.876.543-2',
            'cargo_visador' => 'Jefa de Laboratorio',
            'motivo' => 'Asuntos personales familiares',
        ];

        $response = $this->withHeaders($this->headers())->postJson('/api/permisos', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.estado', 'pendiente_visatura')
            ->assertJsonPath('data.dias_solicitados', 2);

        $solicitudId = $response->json('data.id');

        // Verificar descuento en saldo
        $saldo = PermisoSaldo::where('insamu_user_id', 'USR_100')->where('anio', 2026)->first();
        $this->assertEquals(2.0, $saldo->dias_usados);
        $this->assertEquals(4.0, $saldo->dias_disponibles);

        // Verificar creación de firma inicial
        $this->assertDatabaseHas('permiso_trazabilidad_firmas', [
            'permiso_id' => $solicitudId,
            'insamu_visador_id' => 'VIS_JEFE_01',
            'rol_firma' => 'Jefatura',
            'estado_firma' => 'pendiente',
            'es_subrogante' => 0,
        ]);

        $firma = PermisoTrazabilidadFirma::where('permiso_id', $solicitudId)->first();
        $this->assertNotEmpty($firma->token_correo);

        // Verificar log de auditoría
        $this->assertDatabaseHas('logs_sistema', [
            'accion' => 'CREACION_SOLICITUD',
            'entidad_id' => $solicitudId,
            'insamu_user_id' => 'USR_100',
        ]);
    }

    /**
     * Valida que no se pueda solicitar más días de los disponibles.
     */
    public function test_creacion_falla_por_saldo_insuficiente(): void
    {
        PermisoSaldo::create([
            'insamu_user_id' => 'USR_200',
            'anio' => 2026,
            'dias_totales' => 6.0,
            'dias_usados' => 5.0, // Solo queda 1 día
        ]);

        $payload = [
            'insamu_user_id' => 'USR_200',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-06-02',
            'dias_solicitados' => 2.0, // Pide 2
            'insamu_visador_id' => 'VIS_01',
        ];

        $response = $this->withHeaders($this->headers())->postJson('/api/permisos', $payload);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['dias_solicitados']]);
    }

    /**
     * Valida el flujo completo de Firma Electrónica Simple (FES):
     * Aprobación de Jefatura y avance a Dirección.
     */
    public function test_firma_fes_aprobacion_y_avance_de_estado(): void
    {
        PermisoSaldo::create([
            'insamu_user_id' => 'USR_300',
            'anio' => 2026,
            'dias_totales' => 6.0,
            'dias_usados' => 1.0,
        ]);

        $solicitud = PermisoSolicitud::create([
            'insamu_user_id' => 'USR_300',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-07-01',
            'dias_solicitados' => 1.0,
            'estado' => PermisoSolicitud::ESTADO_PENDIENTE_VISATURA,
        ]);

        $tokenJefe = PermisoTrazabilidadFirma::generarToken();
        $firmaJefe = PermisoTrazabilidadFirma::create([
            'permiso_id' => $solicitud->id,
            'insamu_visador_id' => 'VIS_JEFE_02',
            'rol_firma' => 'Jefatura',
            'estado_firma' => 'pendiente',
            'es_subrogante' => false,
            'token_correo' => $tokenJefe,
        ]);

        // Jefatura confirma PIN en INSAMU -> INSAMU llama a la API
        $payload = [
            'token_correo' => $tokenJefe,
            'accion' => 'aprobar',
            'nombre_firmante' => 'Dra. María González',
            'rut_firmante' => '9.876.543-2',
            'cargo_firmante' => 'Jefa de Departamento',
            'siguiente_visador_id' => 'VIS_DIR_01',
            'siguiente_nombre_visador' => 'Dr. Carlos Director',
            'siguiente_cargo_visador' => 'Director de Hospital',
        ];

        $response = $this->withHeaders($this->headers())->postJson('/api/firmas/confirmar', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.nuevo_estado', 'pendiente_direccion');

        // La firma de Jefatura quedó aprobada
        $firmaJefe->refresh();
        $this->assertEquals('aprobado', $firmaJefe->estado_firma);
        $this->assertNotNull($firmaJefe->fecha_accion);

        // Se creó la firma para Dirección en estado pendiente
        $this->assertDatabaseHas('permiso_trazabilidad_firmas', [
            'permiso_id' => $solicitud->id,
            'insamu_visador_id' => 'VIS_DIR_01',
            'rol_firma' => 'Dirección',
            'estado_firma' => 'pendiente',
        ]);

        // Ahora Dirección aprueba su firma
        $firmaDir = PermisoTrazabilidadFirma::where('permiso_id', $solicitud->id)
            ->where('rol_firma', 'Dirección')
            ->first();

        $payloadDir = [
            'token_correo' => $firmaDir->token_correo,
            'accion' => 'aprobar',
            'nombre_firmante' => 'Dr. Carlos Director',
            'rut_firmante' => '8.765.432-1',
            'cargo_firmante' => 'Director de Hospital',
        ];

        $responseDir = $this->withHeaders($this->headers())->postJson('/api/firmas/confirmar', $payloadDir);
        $responseDir->assertStatus(200)
            ->assertJsonPath('data.nuevo_estado', 'en_rrhh');
    }

    /**
     * Valida que el rechazo de la firma FES pase la solicitud a 'rechazado'
     * y restituya automáticamente los días al usuario.
     */
    public function test_firma_fes_rechazo_restituye_saldo(): void
    {
        $saldo = PermisoSaldo::create([
            'insamu_user_id' => 'USR_400',
            'anio' => 2026,
            'dias_totales' => 6.0,
            'dias_usados' => 3.0,
        ]);

        $solicitud = PermisoSolicitud::create([
            'insamu_user_id' => 'USR_400',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2026-08-03',
            'dias_solicitados' => 3.0,
            'estado' => PermisoSolicitud::ESTADO_PENDIENTE_VISATURA,
        ]);

        $token = PermisoTrazabilidadFirma::generarToken();
        PermisoTrazabilidadFirma::create([
            'permiso_id' => $solicitud->id,
            'insamu_visador_id' => 'VIS_JEFE_03',
            'rol_firma' => 'Jefatura',
            'estado_firma' => 'pendiente',
            'es_subrogante' => false,
            'token_correo' => $token,
        ]);

        $payload = [
            'token_correo' => $token,
            'accion' => 'rechazar',
            'motivo_rechazo' => 'Falta de cobertura médica en el turno solicitado',
            'nombre_firmante' => 'Dra. María González',
            'rut_firmante' => '9.876.543-2',
        ];

        $response = $this->withHeaders($this->headers())->postJson('/api/firmas/confirmar', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.nuevo_estado', 'rechazado');

        // Verificar restitución de saldo
        $saldo->refresh();
        $this->assertEquals(0.0, $saldo->dias_usados);
        $this->assertEquals(6.0, $saldo->dias_disponibles);

        // Verificar auditoría
        $this->assertDatabaseHas('logs_sistema', [
            'accion' => 'FIRMA_ELECTRONICA_RECHAZADA',
            'entidad_id' => $solicitud->id,
        ]);
    }

    /**
     * Subrogancia Autogestionada:
     * Al cambiar de visador en estado pendiente, el token original debe quedar
     * inmediatamente invalidado y generarse un nuevo token para el subrogante con es_subrogante = true.
     */
    public function test_subrogancia_autogestionada_invalida_token_original(): void
    {
        $solicitud = PermisoSolicitud::create([
            'insamu_user_id' => 'USR_500',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2026-09-01',
            'fecha_fin' => '2026-09-01',
            'dias_solicitados' => 1.0,
            'estado' => PermisoSolicitud::ESTADO_PENDIENTE_VISATURA,
        ]);

        $tokenOriginal = PermisoTrazabilidadFirma::generarToken();
        $firmaOriginal = PermisoTrazabilidadFirma::create([
            'permiso_id' => $solicitud->id,
            'insamu_visador_id' => 'VIS_TITULAR',
            'nombre_visador' => 'Dr. Titular Ausente',
            'cargo_visador' => 'Jefe de Servicio',
            'rol_firma' => 'Jefatura',
            'estado_firma' => 'pendiente',
            'es_subrogante' => false,
            'token_correo' => $tokenOriginal,
        ]);

        // El funcionario edita la solicitud para asignar subrogante
        $subroganciaPayload = [
            'nuevo_visador_id' => 'VIS_SUBROGANTE',
            'nuevo_nombre_visador' => 'Dra. Reemplazo Subrogante',
            'nuevo_rut_visador' => '11.222.333-4',
            'nuevo_cargo_visador' => 'Jefa de Servicio',
        ];

        $response = $this->withHeaders($this->headers())
            ->postJson("/api/permisos/{$solicitud->id}/subrogar-visador", $subroganciaPayload);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.firma_subrogante.es_subrogante', true)
            ->assertJsonPath('data.firma_subrogante.insamu_visador_id', 'VIS_SUBROGANTE');

        $nuevoToken = $response->json('data.nuevo_token');
        $this->assertNotEmpty($nuevoToken);
        $this->assertNotEquals($tokenOriginal, $nuevoToken);

        // 1. El token del visador titular DEBE estar invalidado (null o estado subrogado)
        $firmaOriginal->refresh();
        $this->assertNull($firmaOriginal->token_correo);
        $this->assertEquals('subrogado', $firmaOriginal->estado_firma);

        // 2. Intentar usar el token original mediante Deep Linking DEBE fallar
        $verifyOld = $this->withHeaders($this->headers())
            ->getJson("/api/firmas/verificar-token/{$tokenOriginal}");
        $verifyOld->assertStatus(404);

        // 3. Intentar firmar con el token original DEBE fallar
        $signOld = $this->withHeaders($this->headers())->postJson('/api/firmas/confirmar', [
            'token_correo' => $tokenOriginal,
            'accion' => 'aprobar',
        ]);
        $signOld->assertStatus(422);

        // 4. Verificar el nuevo token del subrogante DEBE tener éxito
        $verifyNew = $this->withHeaders($this->headers())
            ->getJson("/api/firmas/verificar-token/{$nuevoToken}");
        $verifyNew->assertStatus(200)
            ->assertJsonPath('valido', true)
            ->assertJsonPath('data.es_subrogante', true);

        // 5. El subrogante puede firmar con su nuevo token
        $signNew = $this->withHeaders($this->headers())->postJson('/api/firmas/confirmar', [
            'token_correo' => $nuevoToken,
            'accion' => 'aprobar',
            'nombre_firmante' => 'Dra. Reemplazo Subrogante',
            'rut_firmante' => '11.222.333-4',
            'cargo_firmante' => 'Jefa de Servicio',
        ]);
        $signNew->assertStatus(200);

        // Verificar que la firma del subrogante tiene es_subrogante = true y estado aprobado
        $this->assertDatabaseHas('permiso_trazabilidad_firmas', [
            'permiso_id' => $solicitud->id,
            'insamu_visador_id' => 'VIS_SUBROGANTE',
            'es_subrogante' => 1,
            'estado_firma' => 'aprobado',
        ]);
    }

    /**
     * Lógica de Anulación y Bloqueo de PDF:
     * El usuario solicita anulación -> cambia a pendiente_anulacion y BLOQUEA la descarga de PDF.
     */
    public function test_solicitud_anulacion_y_bloqueo_de_pdf(): void
    {
        $solicitud = PermisoSolicitud::create([
            'insamu_user_id' => 'USR_600',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2026-10-01',
            'fecha_fin' => '2026-10-01',
            'dias_solicitados' => 1.0,
            'estado' => PermisoSolicitud::ESTADO_EN_RRHH,
        ]);

        // Solicitar anulación
        $response = $this->withHeaders($this->headers())
            ->postJson("/api/permisos/{$solicitud->id}/solicitar-anulacion", [
                'motivo' => 'Ya no requiero el día administrativo',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.estado', 'pendiente_anulacion');

        $solicitud->refresh();
        $this->assertEquals(PermisoSolicitud::ESTADO_PENDIENTE_ANULACION, $solicitud->estado);
        $this->assertEquals(PermisoSolicitud::ESTADO_EN_RRHH, $solicitud->estado_previo_anulacion);

        // Intentar descargar el PDF DEBE ESTAR BLOQUEADO con 403 Forbidden
        $pdfResponse = $this->withHeaders($this->headers())
            ->getJson("/api/permisos/{$solicitud->id}/pdf");

        $pdfResponse->assertStatus(403)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error', 'Descarga de PDF bloqueada: la solicitud se encuentra en estado pendiente_anulacion.');
    }

    /**
     * RRHH: Aprobar anulación restituye el saldo en permisos_saldos y pasa a 'anulado'.
     */
    public function test_rrhh_aprueba_anulacion_y_restituye_saldo(): void
    {
        $saldo = PermisoSaldo::create([
            'insamu_user_id' => 'USR_700',
            'anio' => 2026,
            'dias_totales' => 6.0,
            'dias_usados' => 2.0,
        ]);

        $solicitud = PermisoSolicitud::create([
            'insamu_user_id' => 'USR_700',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2026-10-15',
            'fecha_fin' => '2026-10-16',
            'dias_solicitados' => 2.0,
            'estado' => PermisoSolicitud::ESTADO_PENDIENTE_ANULACION,
            'estado_previo_anulacion' => PermisoSolicitud::ESTADO_DECRETADO,
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson("/api/rrhh/permisos/{$solicitud->id}/aprobar-anulacion", [
                'insamu_rrhh_user_id' => 'RRHH_01',
                'observaciones' => 'Anulación confirmada y saldo revertido.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.estado', 'anulado');

        $saldo->refresh();
        $this->assertEquals(0.0, $saldo->dias_usados);
        $this->assertEquals(6.0, $saldo->dias_disponibles);

        $this->assertDatabaseHas('logs_sistema', [
            'accion' => 'APROBACION_ANULACION',
            'entidad_id' => $solicitud->id,
        ]);
    }

    /**
     * RRHH: Rechazar anulación (si ya fue decretado en otra plataforma externa) restaura el estado previo.
     */
    public function test_rrhh_rechaza_anulacion_restaura_estado(): void
    {
        $solicitud = PermisoSolicitud::create([
            'insamu_user_id' => 'USR_800',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2026-11-01',
            'fecha_fin' => '2026-11-01',
            'dias_solicitados' => 1.0,
            'estado' => PermisoSolicitud::ESTADO_PENDIENTE_ANULACION,
            'estado_previo_anulacion' => PermisoSolicitud::ESTADO_DECRETADO,
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson("/api/rrhh/permisos/{$solicitud->id}/rechazar-anulacion", [
                'insamu_rrhh_user_id' => 'RRHH_01',
                'motivo' => 'Ya fue decretado en plataforma externa SIRH',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.estado', 'decretado');

        $this->assertDatabaseHas('logs_sistema', [
            'accion' => 'RECHAZO_ANULACION',
            'entidad_id' => $solicitud->id,
        ]);
    }

    /**
     * Generación de PDF:
     * Genera PDF inalterable e incluye la estampilla visual con la glosa requerida:
     * "Documento firmado electrónicamente mediante autenticación de PIN en INSAMU por [Nombre], RUT [RUT] con fecha [DD/MM/AAAA] a las [HH:MM]."
     * Y si el visador era subrogante, debe decir explícitamente "[Cargo] Subrogante".
     */
    public function test_generacion_pdf_con_estampilla_y_glosa_exacta(): void
    {
        $solicitud = PermisoSolicitud::create([
            'insamu_user_id' => 'USR_900',
            'nombre_solicitante' => 'Pedro Pascal',
            'rut_solicitante' => '15.987.654-3',
            'cargo_solicitante' => 'Médico Cirujano',
            'unidad_solicitante' => 'Urgencias',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2026-12-01',
            'fecha_fin' => '2026-12-01',
            'dias_solicitados' => 1.0,
            'estado' => PermisoSolicitud::ESTADO_DECRETADO,
            'decreto_numero' => 'DEC-2026-445',
            'fecha_decreto' => '2026-12-01',
        ]);

        // Firma 1: Jefatura (no subrogante)
        PermisoTrazabilidadFirma::create([
            'permiso_id' => $solicitud->id,
            'insamu_visador_id' => 'VIS_JEFE_99',
            'nombre_visador' => 'Dra. Andrea Jefa',
            'rut_visador' => '10.111.222-3',
            'cargo_visador' => 'Jefa de Urgencias',
            'rol_firma' => 'Jefatura',
            'estado_firma' => 'aprobado',
            'es_subrogante' => false,
            'fecha_accion' => '2026-12-01 10:15:00',
        ]);

        // Firma 2: Dirección (SUBROGANTE)
        PermisoTrazabilidadFirma::create([
            'permiso_id' => $solicitud->id,
            'insamu_visador_id' => 'VIS_DIR_SUB',
            'nombre_visador' => 'Dr. Roberto Subrogante',
            'rut_visador' => '12.333.444-5',
            'cargo_visador' => 'Director de Hospital',
            'rol_firma' => 'Dirección',
            'estado_firma' => 'aprobado',
            'es_subrogante' => true,
            'fecha_accion' => '2026-12-01 11:30:00',
        ]);

        // 1. Validar descarga del PDF vía endpoint
        $response = $this->withHeaders($this->headers())
            ->get("/api/permisos/{$solicitud->id}/pdf");

        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('content-type'));

        // 2. Renderizar la vista Blade directamente y comprobar el texto y glosa exacta
        $solicitud->load(['firmasAprobadas']);
        $html = view('pdf.permiso', ['solicitud' => $solicitud])->render();

        // Verificar glosa para firma 1
        $glosaEsperada1 = "Documento firmado electrónicamente mediante autenticación de PIN en INSAMU por Dra. Andrea Jefa, RUT 10.111.222-3 con fecha 01/12/2026 a las 10:15.";
        $this->assertStringContainsString($glosaEsperada1, $html);
        $this->assertStringContainsString("Jefa de Urgencias", $html);

        // Verificar glosa para firma 2 (Subrogante)
        $glosaEsperada2 = "Documento firmado electrónicamente mediante autenticación de PIN en INSAMU por Dr. Roberto Subrogante, RUT 12.333.444-5 con fecha 01/12/2026 a las 11:30.";
        $this->assertStringContainsString($glosaEsperada2, $html);

        // Verificar que diga explícitamente "[Cargo] Subrogante"
        $this->assertStringContainsString("Director de Hospital Subrogante", $html);

        // Verificar que se haya registrado el log de descarga
        $this->assertDatabaseHas('logs_sistema', [
            'accion' => 'DESCARGA_PDF',
            'entidad_id' => $solicitud->id,
        ]);
    }

    /**
     * Valida el listado y filtrado de permisos.
     */
    public function test_listado_y_filtrado_de_permisos(): void
    {
        PermisoSolicitud::create([
            'insamu_user_id' => 'USR_FILTER_1',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2026-03-01',
            'fecha_fin' => '2026-03-01',
            'dias_solicitados' => 1.0,
            'estado' => PermisoSolicitud::ESTADO_PENDIENTE_VISATURA,
        ]);

        PermisoSolicitud::create([
            'insamu_user_id' => 'USR_FILTER_2',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2026-04-01',
            'fecha_fin' => '2026-04-01',
            'dias_solicitados' => 1.0,
            'estado' => PermisoSolicitud::ESTADO_DECRETADO,
        ]);

        // Filtrar por usuario
        $resUser = $this->withHeaders($this->headers())
            ->getJson('/api/permisos?insamu_user_id=USR_FILTER_1');
        $resUser->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.insamu_user_id', 'USR_FILTER_1');

        // Filtrar por estado
        $resEstado = $this->withHeaders($this->headers())
            ->getJson('/api/permisos?estado=decretado');
        $resEstado->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.estado', 'decretado');
    }

    /**
     * Valida la gestión de saldos y decreto por parte de RRHH.
     */
    public function test_saldos_y_decreto_rrhh(): void
    {
        // 1. Configurar saldo inicial
        $saldoRes = $this->withHeaders($this->headers())->postJson('/api/saldos', [
            'insamu_user_id' => 'USR_DECRETO',
            'anio' => 2026,
            'dias_totales' => 6.0,
        ]);
        $saldoRes->assertStatus(200)
            ->assertJsonPath('data.dias_totales', 6);

        // 2. Consultar saldo
        $queryRes = $this->withHeaders($this->headers())
            ->getJson('/api/saldos/USR_DECRETO?anio=2026');
        $queryRes->assertStatus(200)
            ->assertJsonPath('data.0.dias_disponibles', 6);

        // 3. Crear solicitud
        $solicitud = PermisoSolicitud::create([
            'insamu_user_id' => 'USR_DECRETO',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-05-01',
            'dias_solicitados' => 1.0,
            'estado' => PermisoSolicitud::ESTADO_EN_RRHH,
        ]);

        // 4. Decretar solicitud por RRHH
        $decretoRes = $this->withHeaders($this->headers())
            ->postJson("/api/rrhh/permisos/{$solicitud->id}/decretar", [
                'decreto_numero' => 'DEC-EXENTO-999',
                'fecha_decreto' => '2026-05-02',
                'insamu_user_id' => 'RRHH_OFFICER',
            ]);
        $decretoRes->assertStatus(200)
            ->assertJsonPath('data.estado', 'decretado')
            ->assertJsonPath('data.decreto_numero', 'DEC-EXENTO-999');

        // 5. Consultar logs de auditoría
        $logsRes = $this->withHeaders($this->headers())
            ->getJson("/api/logs?entidad_id={$solicitud->id}");
        $logsRes->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }
}
