<?php

namespace Tests\Feature;

use App\Models\PermisoSaldo;
use App\Models\PermisoSolicitud;
use App\Models\PermisoTrazabilidadFirma;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
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
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->apiKey])
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
        $glosaEsperada1 = 'Documento firmado electrónicamente mediante autenticación de PIN en INSAMU por Dra. Andrea Jefa, RUT 10.111.222-3 con fecha 01/12/2026 a las 10:15.';
        $this->assertStringContainsString($glosaEsperada1, $html);
        $this->assertStringContainsString('Jefa de Urgencias', $html);

        // Verificar glosa para firma 2 (Subrogante)
        $glosaEsperada2 = 'Documento firmado electrónicamente mediante autenticación de PIN en INSAMU por Dr. Roberto Subrogante, RUT 12.333.444-5 con fecha 01/12/2026 a las 11:30.';
        $this->assertStringContainsString($glosaEsperada2, $html);

        // Verificar que diga explícitamente "[Cargo] Subrogante"
        $this->assertStringContainsString('Director de Hospital Subrogante', $html);

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

    /**
     * Valida la inicialización individual de saldos por RRHH (Feriado Legal y opcional Compensación).
     */
    public function test_rrhh_inicializacion_individual_de_saldos(): void
    {
        $payload = [
            'insamu_user_id' => 'USR_RRHH_INDIV',
            'anio' => 2026,
            'dias_feriado_legal' => 20.0,
            'horas_compensacion' => 8.5,
            'insamu_rrhh_user_id' => 'RRHH_OFFICER_01',
        ];

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/rrhh/saldos/inicializar', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('permisos_saldos', [
            'insamu_user_id' => 'USR_RRHH_INDIV',
            'anio' => 2026,
            'tipo_permiso' => PermisoSaldo::TIPO_FERIADO_LEGAL,
            'dias_totales' => 20.0,
            'unidad' => PermisoSaldo::UNIDAD_DIAS,
        ]);

        $this->assertDatabaseHas('permisos_saldos', [
            'insamu_user_id' => 'USR_RRHH_INDIV',
            'anio' => 2026,
            'tipo_permiso' => PermisoSaldo::TIPO_COMPENSACION_TIEMPO,
            'dias_totales' => 8.5,
            'unidad' => PermisoSaldo::UNIDAD_HORAS,
        ]);

        $this->assertDatabaseHas('logs_sistema', [
            'accion' => 'INICIALIZACION_SALDOS_INDIVIDUAL',
            'insamu_user_id' => 'RRHH_OFFICER_01',
        ]);
    }

    /**
     * Valida la carga masiva de saldos por RRHH vía JSON y archivo CSV.
     */
    public function test_rrhh_carga_masiva_via_json_y_csv(): void
    {
        // 1. Carga masiva mediante JSON
        $payloadJson = [
            'insamu_rrhh_user_id' => 'RRHH_BULK',
            'saldos' => [
                [
                    'insamu_user_id' => 'USR_MASS_1',
                    'anio' => 2026,
                    'dias_feriado_legal' => 15.0,
                    'horas_compensacion' => 4.0,
                ],
                [
                    'insamu_user_id' => 'USR_MASS_2',
                    'anio' => 2026,
                    'dias_feriado_legal' => 25.0,
                    'horas_compensacion' => 0.0,
                ],
            ],
        ];

        $resJson = $this->withHeaders($this->headers())
            ->postJson('/api/rrhh/saldos/carga-masiva', $payloadJson);

        $resJson->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('resumen.total_procesados', 2);

        $this->assertDatabaseHas('permisos_saldos', [
            'insamu_user_id' => 'USR_MASS_1',
            'tipo_permiso' => PermisoSaldo::TIPO_FERIADO_LEGAL,
            'dias_totales' => 15.0,
        ]);
        $this->assertDatabaseHas('permisos_saldos', [
            'insamu_user_id' => 'USR_MASS_2',
            'tipo_permiso' => PermisoSaldo::TIPO_FERIADO_LEGAL,
            'dias_totales' => 25.0,
        ]);

        // 2. Carga masiva mediante archivo CSV
        $csvContent = "insamu_user_id,anio,dias_feriado_legal,horas_compensacion\n"
                    ."CSV_USER_A,2026,15.0,2.5\n"
                    ."CSV_USER_B,2026,20.0,6.0\n";

        $csvFile = UploadedFile::fake()->createWithContent('planilla_saldos.csv', $csvContent);

        $resCsv = $this->withHeaders($this->headers())
            ->post('/api/rrhh/saldos/carga-masiva', [
                'archivo_csv' => $csvFile,
                'anio_defecto' => 2026,
                'insamu_rrhh_user_id' => 'RRHH_CSV_ADMIN',
            ]);

        $resCsv->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('resumen.total_procesados', 2);

        $this->assertDatabaseHas('permisos_saldos', [
            'insamu_user_id' => 'CSV_USER_A',
            'tipo_permiso' => PermisoSaldo::TIPO_FERIADO_LEGAL,
            'dias_totales' => 15.0,
        ]);
        $this->assertDatabaseHas('permisos_saldos', [
            'insamu_user_id' => 'CSV_USER_B',
            'tipo_permiso' => PermisoSaldo::TIPO_COMPENSACION_TIEMPO,
            'dias_totales' => 6.0,
        ]);
    }

    /**
     * Valida la inyección mensual transaccional de compensación de tiempo (acumulativo).
     */
    public function test_rrhh_inyectar_compensacion_transaccional(): void
    {
        $payload1 = [
            'insamu_user_id' => 'USR_COMP_EXTRA',
            'anio' => 2026,
            'horas' => 5.5,
            'motivo' => 'Turno de noche sábado',
            'insamu_rrhh_user_id' => 'RRHH_JEFE_TURNO',
        ];

        $res1 = $this->withHeaders($this->headers())
            ->postJson('/api/rrhh/saldos/inyectar-compensacion', $payload1);

        $res1->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.dias_totales', 5.5);

        // Segunda inyección mensual debe sumarse transaccionalmente sin sobrescribir
        $payload2 = [
            'insamu_user_id' => 'USR_COMP_EXTRA',
            'anio' => 2026,
            'horas' => 3.0,
            'motivo' => 'Turno de refuerzo festivo',
            'insamu_rrhh_user_id' => 'RRHH_JEFE_TURNO',
        ];

        $res2 = $this->withHeaders($this->headers())
            ->postJson('/api/rrhh/saldos/inyectar-compensacion', $payload2);

        $res2->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.dias_totales', 8.5)
            ->assertJsonPath('data.dias_disponibles', 8.5);

        $this->assertDatabaseHas('logs_sistema', [
            'accion' => 'INYECCION_COMPENSACION_RRHH',
            'insamu_user_id' => 'RRHH_JEFE_TURNO',
        ]);
    }

    /**
     * Valida el bloqueo preventivo y la validación estricta de cupo sin valores fijos.
     */
    public function test_bloqueo_preventivo_y_validacion_estricta_de_cupo(): void
    {
        // 1. Consultar estado sin haber inicializado el saldo
        $verificarRes = $this->withHeaders($this->headers())
            ->getJson('/api/saldos/USR_BLOQUEO/verificar?anio=2026&tipo_permiso=feriado_legal');

        $verificarRes->assertStatus(200)
            ->assertJsonPath('data.configurado', false)
            ->assertJsonPath('data.bloqueo_preventivo', true)
            ->assertJsonPath('data.mensaje', 'Saldo anual no configurado. Por favor, regularice su situación con Recursos Humanos antes de solicitar este permiso.');

        // 2. Intentar solicitar permiso sin tener saldo configurado debe ser bloqueado con 422
        $solicitudPayload = [
            'insamu_user_id' => 'USR_BLOQUEO',
            'tipo_permiso' => 'Feriado Legal',
            'fecha_inicio' => '2026-11-01',
            'fecha_fin' => '2026-11-05',
            'dias_solicitados' => 5.0,
            'insamu_visador_id' => 'VIS_01',
        ];

        $crearRes = $this->withHeaders($this->headers())
            ->postJson('/api/permisos', $solicitudPayload);

        $crearRes->assertStatus(422)
            ->assertJsonPath('errors.saldo.0', 'Saldo anual no configurado. Por favor, regularice su situación con Recursos Humanos antes de solicitar este permiso.');

        // 3. Inicializar saldo por RRHH y comprobar que el bloqueo preventivo se desactiva
        $this->withHeaders($this->headers())
            ->postJson('/api/rrhh/saldos/inicializar', [
                'insamu_user_id' => 'USR_BLOQUEO',
                'anio' => 2026,
                'dias_feriado_legal' => 15.0,
            ]);

        $verificarPostRes = $this->withHeaders($this->headers())
            ->getJson('/api/saldos/USR_BLOQUEO/verificar?anio=2026&tipo_permiso=feriado_legal');

        $verificarPostRes->assertStatus(200)
            ->assertJsonPath('data.configurado', true)
            ->assertJsonPath('data.bloqueo_preventivo', false)
            ->assertJsonPath('data.mensaje', null);
        $this->assertEquals(15.0, $verificarPostRes->json('data.cantidad_disponible'));

        // 4. Ahora sí permite crear la solicitud y descuenta el saldo
        $crearExitoRes = $this->withHeaders($this->headers())
            ->postJson('/api/permisos', $solicitudPayload);

        $crearExitoRes->assertStatus(201);
        $this->assertEquals(10.0, PermisoSaldo::where('insamu_user_id', 'USR_BLOQUEO')->where('anio', 2026)->where('tipo_permiso', PermisoSaldo::TIPO_FERIADO_LEGAL)->first()->cantidad_disponible);
    }

    /**
     * Valida el Cron Job de asignación anual de días administrativos (yearlyOn(1, 1, '00:00'))
     * y el vencimiento estricto al 31 de diciembre sin acumulación ni traspaso.
     */
    public function test_cron_asignar_administrativos_anuales_y_vencimiento_estricto(): void
    {
        // 1. Verificar configuración del Task Scheduler: yearlyOn(1, 1, '00:00') -> '0 0 1 1 *'
        $schedule = app(Schedule::class);
        $eventos = collect($schedule->events());
        $eventoCron = $eventos->first(fn ($e) => str_contains($e->command, 'permisos:asignar-administrativos-anuales'));

        $this->assertNotNull($eventoCron, 'El comando permisos:asignar-administrativos-anuales debe estar programado en el Scheduler');
        $this->assertEquals('0 0 1 1 *', $eventoCron->expression);

        // 2. Simular funcionarios activos en el sistema
        PermisoSaldo::create([
            'insamu_user_id' => 'DOC_ACTIVO_1',
            'anio' => 2026,
            'tipo_permiso' => PermisoSaldo::TIPO_FERIADO_LEGAL,
            'dias_totales' => 15.0,
        ]);
        PermisoSaldo::create([
            'insamu_user_id' => 'DOC_ACTIVO_2',
            'anio' => 2026,
            'tipo_permiso' => PermisoSaldo::TIPO_ADMINISTRATIVO,
            'dias_totales' => 6.0,
            'dias_usados' => 2.0, // le sobraron 4 días en 2026
        ]);

        // 3. Ejecutar comando de asignación anual automática para el año 2027
        $exitCode = Artisan::call('permisos:asignar-administrativos-anuales', [
            '--anio' => 2027,
        ]);
        $this->assertEquals(0, $exitCode);

        // 4. Verificar que se crearon registros de saldo para 2027 con exactamente 6 días totales y 0 usados
        $saldo2027User1 = PermisoSaldo::where('insamu_user_id', 'DOC_ACTIVO_1')
            ->where('anio', 2027)
            ->where('tipo_permiso', PermisoSaldo::TIPO_ADMINISTRATIVO)
            ->first();

        $this->assertNotNull($saldo2027User1);
        $this->assertEquals(6.0, $saldo2027User1->dias_totales);
        $this->assertEquals(0.0, $saldo2027User1->dias_usados);
        $this->assertEquals(6.0, $saldo2027User1->dias_disponibles);

        // 5. Vencimiento estricto: Los 4 días sobrantes de 2026 de DOC_ACTIVO_2 NO se traspasan ni se suman a 2027
        $saldo2027User2 = PermisoSaldo::where('insamu_user_id', 'DOC_ACTIVO_2')
            ->where('anio', 2027)
            ->where('tipo_permiso', PermisoSaldo::TIPO_ADMINISTRATIVO)
            ->first();

        $this->assertNotNull($saldo2027User2);
        $this->assertEquals(6.0, $saldo2027User2->dias_totales, 'Los días del año anterior no se suman a los 6 nuevos');
        $this->assertEquals(0.0, $saldo2027User2->dias_usados);

        // 6. Auditoría generada por el Cron Job
        $this->assertDatabaseHas('logs_sistema', [
            'accion' => 'ASIGNACION_AUTOMATICA_ADMINISTRATIVOS_ANUAL',
            'insamu_user_id' => 'SISTEMA_CRON',
        ]);

        // 7. Vencimiento estricto en solicitudes: Intentar solicitar permiso administrativo para un año concluido falla
        $resVencido = $this->withHeaders($this->headers())->postJson('/api/permisos', [
            'insamu_user_id' => 'DOC_ACTIVO_2',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2025-11-10',
            'fecha_fin' => '2025-11-10',
            'dias_solicitados' => 1.0,
            'insamu_visador_id' => 'VIS_01',
        ]);

        $resVencido->assertStatus(422)
            ->assertJsonPath('errors.fecha_inicio.0', 'El saldo de días administrativos del año 2025 caducó el 31 de diciembre de dicho año. Los días no utilizados no se traspasan ni pueden ser solicitados.');
    }

    /**
     * Cálculo Simple de Fechas: Se descarta la integración de calendarios de feriados nacionales.
     * La API confía plenamente en la cantidad enviada por el frontend (dias_solicitados)
     * y los descuenta linealmente del saldo del funcionario.
     */
    public function test_calculo_simple_de_fechas_descuento_lineal(): void
    {
        PermisoSaldo::create([
            'insamu_user_id' => 'USR_CALC_FECHAS',
            'anio' => 2026,
            'tipo_permiso' => PermisoSaldo::TIPO_ADMINISTRATIVO,
            'dias_totales' => 6.0,
            'dias_usados' => 0.0,
        ]);

        // Solicita 3 días en un rango que abarca fin de semana; la API no altera los días solicitados
        $payload = [
            'insamu_user_id' => 'USR_CALC_FECHAS',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2026-05-15',
            'fecha_fin' => '2026-05-18',
            'dias_solicitados' => 3.0,
            'insamu_visador_id' => 'VIS_01',
        ];

        $response = $this->withHeaders($this->headers())->postJson('/api/permisos', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.dias_solicitados', 3);

        $saldo = PermisoSaldo::where('insamu_user_id', 'USR_CALC_FECHAS')->where('anio', 2026)->first();
        $this->assertEquals(3.0, $saldo->dias_usados);
        $this->assertEquals(3.0, $saldo->dias_disponibles);
    }

    /**
     * Validación Condicional de Adjuntos:
     * El campo 'archivo' es required si tipo_permiso es:
     * fallecimiento_familiar, nacimiento_hijo o capacitacion_autogestionada.
     * Para otros tipos (como administrativo o feriado legal) es nullable.
     */
    public function test_validacion_condicional_de_adjuntos_obligatorios(): void
    {
        // 1. Fallecimiento familiar sin archivo -> 422 con error en 'archivo'
        $resFallecimiento = $this->withHeaders($this->headers())->postJson('/api/permisos', [
            'insamu_user_id' => 'USR_ADJUNTO_TEST',
            'tipo_permiso' => 'fallecimiento_familiar',
            'fecha_inicio' => '2026-06-10',
            'fecha_fin' => '2026-06-12',
            'dias_solicitados' => 3.0,
            'insamu_visador_id' => 'VIS_01',
        ]);
        $resFallecimiento->assertStatus(422)
            ->assertJsonPath('errors.archivo.0', 'El archivo adjunto es obligatorio para solicitudes de tipo fallecimiento familiar, nacimiento de hijo o capacitación autogestionada.');

        // 2. Nacimiento de hijo sin archivo -> 422
        $resNacimiento = $this->withHeaders($this->headers())->postJson('/api/permisos', [
            'insamu_user_id' => 'USR_ADJUNTO_TEST',
            'tipo_permiso' => 'nacimiento_hijo',
            'fecha_inicio' => '2026-06-10',
            'fecha_fin' => '2026-06-15',
            'dias_solicitados' => 5.0,
            'insamu_visador_id' => 'VIS_01',
        ]);
        $resNacimiento->assertStatus(422)
            ->assertJsonPath('errors.archivo.0', 'El archivo adjunto es obligatorio para solicitudes de tipo fallecimiento familiar, nacimiento de hijo o capacitación autogestionada.');

        // 3. Capacitación autogestionada sin archivo -> 422
        $resCapacitacion = $this->withHeaders($this->headers())->postJson('/api/permisos', [
            'insamu_user_id' => 'USR_ADJUNTO_TEST',
            'tipo_permiso' => 'capacitacion_autogestionada',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-07-02',
            'dias_solicitados' => 2.0,
            'insamu_visador_id' => 'VIS_01',
        ]);
        $resCapacitacion->assertStatus(422)
            ->assertJsonPath('errors.archivo.0', 'El archivo adjunto es obligatorio para solicitudes de tipo fallecimiento familiar, nacimiento de hijo o capacitación autogestionada.');

        // 4. Permiso Administrativo sin archivo -> Pasa validación sin error (nullable)
        PermisoSaldo::create([
            'insamu_user_id' => 'USR_ADJUNTO_TEST',
            'anio' => 2026,
            'tipo_permiso' => PermisoSaldo::TIPO_ADMINISTRATIVO,
            'dias_totales' => 6.0,
            'dias_usados' => 0.0,
        ]);

        $resAdmin = $this->withHeaders($this->headers())->postJson('/api/permisos', [
            'insamu_user_id' => 'USR_ADJUNTO_TEST',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2026-08-01',
            'dias_solicitados' => 1.0,
            'insamu_visador_id' => 'VIS_01',
        ]);
        $resAdmin->assertStatus(201);
    }

    /**
     * Endpoint de Archivos (Storage):
     * Recepción, renombrado seguro (UUID) para evitar path traversal, almacenamiento y descarga.
     */
    public function test_almacenamiento_seguro_y_endpoint_archivos_adjuntos(): void
    {
        $disco = config('filesystems.default', 'local');
        Storage::fake($disco);

        // 1. Crear permiso obligatorio con archivo adjunto (fallecimiento_familiar con certificado de defunción)
        $archivoCertificado = UploadedFile::fake()->create('certificado_defuncion_peligroso_../evil.pdf', 150, 'application/pdf');

        $response = $this->withHeaders($this->headers())->post('/api/permisos', [
            'insamu_user_id' => 'USR_STORAGE_TEST',
            'tipo_permiso' => 'fallecimiento_familiar',
            'fecha_inicio' => '2026-09-01',
            'fecha_fin' => '2026-09-03',
            'dias_solicitados' => 3.0,
            'insamu_visador_id' => 'VIS_JEFE_01',
            'archivo' => $archivoCertificado,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success');

        $solicitudId = $response->json('data.id');
        $solicitud = PermisoSolicitud::findOrFail($solicitudId);

        // Verificar que archivo_adjunto_url existe y NO contiene el nombre malicioso del cliente
        $this->assertNotNull($solicitud->archivo_adjunto_url);
        $this->assertStringNotContainsString('evil.pdf', $solicitud->archivo_adjunto_url);
        $this->assertStringStartsWith('adjuntos_permisos/', $solicitud->archivo_adjunto_url);
        $this->assertStringContainsString('adjunto_', $solicitud->archivo_adjunto_url);

        // Verificar existencia física en el disco seguro
        Storage::disk($disco)->assertExists($solicitud->archivo_adjunto_url);

        // 2. Descargar archivo adjunto mediante endpoint GET /api/permisos/{id}/adjunto
        $resDescarga = $this->withHeaders($this->headers())
            ->get("/api/permisos/{$solicitud->id}/adjunto");

        $resDescarga->assertStatus(200);
        $this->assertStringContainsString('pdf', $resDescarga->headers->get('content-type'));

        // 3. Subir / actualizar archivo adjunto mediante endpoint dedicado POST /api/permisos/{id}/adjunto
        $nuevoArchivo = UploadedFile::fake()->image('constancia_adicional.png');
        $resSubida = $this->withHeaders($this->headers())
            ->post("/api/permisos/{$solicitud->id}/adjunto", [
                'archivo' => $nuevoArchivo,
            ]);

        $resSubida->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $solicitud->refresh();
        $this->assertStringEndsWith('.png', $solicitud->archivo_adjunto_url);
        Storage::disk($disco)->assertExists($solicitud->archivo_adjunto_url);

        // 4. Intentar descargar adjunto de una solicitud que no tiene archivo
        $solicitudSinAdjunto = PermisoSolicitud::create([
            'insamu_user_id' => 'USR_SIN_ADJUNTO',
            'tipo_permiso' => 'Permiso Administrativo',
            'fecha_inicio' => '2026-10-01',
            'fecha_fin' => '2026-10-01',
            'dias_solicitados' => 1.0,
            'estado' => PermisoSolicitud::ESTADO_PENDIENTE_VISATURA,
        ]);

        $res404 = $this->withHeaders($this->headers())
            ->getJson("/api/permisos/{$solicitudSinAdjunto->id}/adjunto");
        $res404->assertStatus(404)
            ->assertJsonPath('message', 'La solicitud no cuenta con un archivo adjunto.');
    }
}
