<?php

namespace App\Services;

use App\Models\LogSistema;
use App\Models\PermisoSaldo;
use App\Models\PermisoSolicitud;
use App\Models\PermisoTrazabilidadFirma;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PermisoService
{
    /**
     * Determina si el tipo de permiso corresponde a Sin Goce de Remuneraciones.
     */
    public static function esSinGoce(?string $tipoPermiso): bool
    {
        if (empty($tipoPermiso)) {
            return false;
        }

        $lower = mb_strtolower(trim($tipoPermiso));

        return str_contains($lower, 'sin_goce') || str_contains($lower, 'sin goce');
    }

    /**
     * Calcula los días usados y disponibles de Permiso Sin Goce de Remuneraciones
     * (tope 30 días mensuales y 90 días anuales).
     *
     * @return array{tope_mensual: float, usados_mes: float, dias_mes_disponibles: float, tope_anual: float, usados_anio: float, dias_anio_disponibles: float}
     */
    public static function calcularUsoSinGoce(string $userId, int $anio, ?int $mes = null): array
    {
        $userLower = strtolower(trim($userId));
        $mesEvaluar = $mes ?? (int) now()->month;

        $solicitudesActivas = PermisoSolicitud::query()
            ->whereRaw('LOWER(insamu_user_id) = ?', [$userLower])
            ->whereYear('fecha_inicio', $anio)
            ->whereNotIn('estado', [
                PermisoSolicitud::ESTADO_RECHAZADO,
                PermisoSolicitud::ESTADO_ANULADO,
            ])
            ->get();

        $usadosMes = 0.0;
        $usadosAnio = 0.0;

        foreach ($solicitudesActivas as $sol) {
            if (! self::esSinGoce($sol->tipo_permiso)) {
                continue;
            }

            $dias = (float) ($sol->dias_solicitados ?? 0.0);
            $fechaInicio = Carbon::parse($sol->fecha_inicio);

            if ((int) $fechaInicio->year === $anio) {
                $usadosAnio += $dias;
                if ((int) $fechaInicio->month === $mesEvaluar) {
                    $usadosMes += $dias;
                }
            }
        }

        return [
            'tope_mensual' => 30.0,
            'usados_mes' => round($usadosMes, 1),
            'dias_mes_disponibles' => max(0.0, round(30.0 - $usadosMes, 1)),
            'tope_anual' => 90.0,
            'usados_anio' => round($usadosAnio, 1),
            'dias_anio_disponibles' => max(0.0, round(90.0 - $usadosAnio, 1)),
        ];
    }

    /**
     * Busca el registro de saldo de un usuario de forma insensible a mayúsculas/minúsculas.
     */
    protected function buscarSaldoUsuario(string $userId, int $anio, string $tipoSaldo): ?PermisoSaldo
    {
        $userLower = strtolower(trim($userId));

        return PermisoSaldo::query()
            ->whereRaw('LOWER(insamu_user_id) = ?', [$userLower])
            ->where('anio', $anio)
            ->where('tipo_permiso', $tipoSaldo)
            ->first();
    }

    /**
     * Crear una nueva solicitud de permiso administrativo previa validación de saldo.
     */
    public function crearSolicitud(array $datos): PermisoSolicitud
    {
        $fechaInicio = Carbon::parse($datos['fecha_inicio']);
        $fechaFin = Carbon::parse($datos['fecha_fin']);
        $anio = $fechaInicio->year;
        $mes = $fechaInicio->month;
        $userId = trim((string) $datos['insamu_user_id']);
        $datos['insamu_user_id'] = $userId;

        $tipoPermisoRaw = (string) ($datos['tipo_permiso'] ?? 'administrativo');
        $tipoSaldo = PermisoSaldo::normalizarTipoPermiso($tipoPermisoRaw);
        $esSinGoce = self::esSinGoce($tipoPermisoRaw);

        // Vencimiento estricto: Los días administrativos caducan el 31 de diciembre.
        if ($tipoSaldo === PermisoSaldo::TIPO_ADMINISTRATIVO) {
            if ($anio < now()->year) {
                throw ValidationException::withMessages([
                    'fecha_inicio' => [
                        "El saldo de días administrativos del año {$anio} caducó el 31 de diciembre de dicho año. Los días no utilizados no se traspasan ni pueden ser solicitados.",
                    ],
                ]);
            }

            if ($fechaFin->year !== $anio) {
                throw ValidationException::withMessages([
                    'fecha_fin' => [
                        'Los permisos administrativos no pueden extenderse entre distintos años calendario debido al vencimiento estricto al 31 de diciembre.',
                    ],
                ]);
            }
        }

        $diasSolicitados = (float) ($datos['dias_solicitados'] ?? 0.0);
        $horasSolicitadas = isset($datos['horas_solicitadas']) ? (float) $datos['horas_solicitadas'] : null;

        if ($tipoSaldo === PermisoSaldo::TIPO_COMPENSACION_TIEMPO && $horasSolicitadas === null) {
            $horasSolicitadas = $diasSolicitados;
        }

        $cantidadADescontar = ($tipoSaldo === PermisoSaldo::TIPO_COMPENSACION_TIEMPO)
            ? ($horasSolicitadas ?? 0.0)
            : $diasSolicitados;

        $unidad = ($tipoSaldo === PermisoSaldo::TIPO_COMPENSACION_TIEMPO) ? 'horas' : 'días';
        $jornada = (string) ($datos['jornada'] ?? ($diasSolicitados == 0.5 ? 'Mañana' : (! empty($horasSolicitadas) ? 'Horas' : 'Completa')));

        // Validar que no existan solapamientos incompatibles con solicitudes activas previas
        $this->validarSolapamientoFechas(
            $userId,
            $datos['rut_solicitante'] ?? null,
            (string) $datos['fecha_inicio'],
            (string) $datos['fecha_fin'],
            $jornada,
            $diasSolicitados,
            $horasSolicitadas,
            $datos['hora_desde'] ?? null,
            $datos['hora_hasta'] ?? null
        );

        return DB::transaction(function () use ($datos, $userId, $anio, $mes, $tipoSaldo, $esSinGoce, $diasSolicitados, $horasSolicitadas, $cantidadADescontar, $unidad) {
            // 1. Validar topes de Permiso Sin Goce de Remuneraciones (30 días/mes, 90 días/año)
            if ($esSinGoce) {
                $usoSinGoce = self::calcularUsoSinGoce($userId, $anio, $mes);

                if ($diasSolicitados > $usoSinGoce['dias_mes_disponibles']) {
                    throw ValidationException::withMessages([
                        'dias_solicitados' => [
                            "Excede el tope mensual de 30 días para Permiso Sin Goce de Remuneraciones. Disponibles este mes: {$usoSinGoce['dias_mes_disponibles']} días.",
                        ],
                    ]);
                }

                if ($diasSolicitados > $usoSinGoce['dias_anio_disponibles']) {
                    throw ValidationException::withMessages([
                        'dias_solicitados' => [
                            "Excede el tope anual de 90 días para Permiso Sin Goce de Remuneraciones. Disponibles este año: {$usoSinGoce['dias_anio_disponibles']} días.",
                        ],
                    ]);
                }
            }

            // 2. Validar estrictamente existencia de saldo y cupo disponible si el permiso requiere saldo
            if ($tipoSaldo !== null) {
                $saldo = $this->buscarSaldoUsuario($userId, $anio, $tipoSaldo);

                // Automatización 1 de enero: Días administrativos siempre disponibles con 6 días por defecto
                if (! $saldo && $tipoSaldo === PermisoSaldo::TIPO_ADMINISTRATIVO) {
                    $saldo = PermisoSaldo::obtenerOCrear($userId, $anio, PermisoSaldo::TIPO_ADMINISTRATIVO, 6.0);
                }

                if (! $saldo) {
                    throw ValidationException::withMessages([
                        'saldo' => [
                            'Saldo anual no configurado. Por favor, regularice su situación con Recursos Humanos antes de solicitar este permiso.',
                        ],
                    ]);
                }

                if (! $saldo->tieneSaldoSuficiente($cantidadADescontar)) {
                    $campoError = ($tipoSaldo === PermisoSaldo::TIPO_COMPENSACION_TIEMPO) ? 'horas_solicitadas' : 'dias_solicitados';
                    throw ValidationException::withMessages([
                        $campoError => [
                            "Saldo insuficiente de {$saldo->tipo_permiso} para el año {$anio}. {$unidad} disponibles: {$saldo->cantidad_disponible}, solicitadas: {$cantidadADescontar}.",
                        ],
                    ]);
                }

                $saldo->descontarCantidad($cantidadADescontar);
            }

            // 3. Normalizar circuito completo de visadores
            $visadoresInput = is_array($datos['visadores'] ?? null) ? array_values($datos['visadores']) : [];
            $tieneCircuitoExplicito = ! empty($visadoresInput);
            $visadoresCircuit = [];

            if ($tieneCircuitoExplicito) {
                foreach ($visadoresInput as $idx => $v) {
                    $rol = (string) ($v['rol'] ?? ($idx === 0 ? ($datos['rol_firma'] ?? 'visador') : 'direccion_desam'));
                    $rolLabel = (string) ($v['rol_label'] ?? match ($rol) {
                        'visador_adicional' => 'Visador Adicional',
                        'direccion_cesfam' => 'Dirección CESFAM',
                        'direccion_desam' => 'Dirección DESAM',
                        default => 'Visador',
                    });

                    $visadoresCircuit[] = [
                        'orden' => $idx + 1,
                        'rol' => $rol,
                        'rol_label' => $rolLabel,
                        'email' => trim((string) ($v['email'] ?? '')),
                        'nombre' => (string) ($v['nombre'] ?? 'Visador'),
                        'rut' => (string) ($v['rut'] ?? ''),
                        'cargo' => (string) ($v['cargo'] ?? $rolLabel),
                        'es_subrogante' => (bool) ($v['es_subrogante'] ?? false),
                        'firmado' => false,
                        'fecha_firma' => null,
                    ];
                }

                $primerVisador = $visadoresCircuit[0];
                $totalPasos = count($visadoresCircuit);
                $esUnicoPasoDireccion = ($totalPasos === 1) && (
                    in_array(strtolower($primerVisador['rol']), ['dirección', 'direccion', 'direccion_desam'], true)
                    || str_contains(strtolower($primerVisador['cargo']), 'director')
                );

                $estadoInicial = $esUnicoPasoDireccion
                    ? PermisoSolicitud::ESTADO_PENDIENTE_DIRECCION
                    : PermisoSolicitud::ESTADO_PENDIENTE_VISATURA;
            } else {
                $esDireccionInicial = in_array(strtolower((string) ($datos['rol_firma'] ?? '')), ['dirección', 'direccion', 'direccion_cesfam', 'direccion_desam'], true)
                    || str_contains(strtolower((string) ($datos['cargo_visador'] ?? '')), 'director')
                    || str_contains(strtolower((string) ($datos['cargo_visador'] ?? '')), 'dirección');

                $estadoInicial = $esDireccionInicial ? PermisoSolicitud::ESTADO_PENDIENTE_DIRECCION : PermisoSolicitud::ESTADO_PENDIENTE_VISATURA;
                $rolFirmaInicial = $esDireccionInicial ? 'Dirección' : ($datos['rol_firma'] ?? 'Jefatura');
            }

            // 4. Crear registro de solicitud con todos los metadatos
            $solicitud = PermisoSolicitud::create([
                'insamu_user_id' => $userId,
                'nombre_solicitante' => $datos['nombre_solicitante'] ?? null,
                'rut_solicitante' => $datos['rut_solicitante'] ?? null,
                'cargo_solicitante' => $datos['cargo_solicitante'] ?? null,
                'unidad_solicitante' => $datos['unidad_solicitante'] ?? null,
                'tipo_permiso' => $datos['tipo_permiso'] ?? 'Permiso Administrativo',
                'fecha_inicio' => $datos['fecha_inicio'],
                'fecha_fin' => $datos['fecha_fin'],
                'dias_solicitados' => $diasSolicitados,
                'horas_solicitadas' => $horasSolicitadas,
                'jornada' => $datos['jornada'] ?? ($diasSolicitados == 0.5 ? 'Mañana' : 'Completa'),
                'hora_desde' => $datos['hora_desde'] ?? null,
                'hora_hasta' => $datos['hora_hasta'] ?? null,
                'parentesco_fallecimiento' => $datos['parentesco_fallecimiento'] ?? null,
                'estado' => $estadoInicial,
                'motivo' => $datos['motivo'] ?? null,
                'archivo_adjunto_url' => $datos['archivo_adjunto_url'] ?? null,
                'archivo_adjunto_nombre' => $datos['archivo_adjunto_nombre'] ?? null,
                'visadores' => $tieneCircuitoExplicito ? $visadoresCircuit : null,
            ]);

            // 5. Crear registros de trazabilidad de firma
            $primeraFirma = null;
            if ($tieneCircuitoExplicito) {
                foreach ($visadoresCircuit as $paso) {
                    $firmaCreada = PermisoTrazabilidadFirma::create([
                        'permiso_id' => $solicitud->id,
                        'orden' => (int) $paso['orden'],
                        'insamu_visador_id' => $paso['email'] ?: ($datos['insamu_visador_id'] ?? 'visador'),
                        'nombre_visador' => $paso['nombre'] ?: null,
                        'rut_visador' => $paso['rut'] ?: null,
                        'cargo_visador' => $paso['cargo'] ?: $paso['rol_label'],
                        'rol_firma' => $paso['rol'],
                        'estado_firma' => 'pendiente',
                        'es_subrogante' => (bool) ($paso['es_subrogante'] ?? false),
                        'token_correo' => PermisoTrazabilidadFirma::generarToken(),
                    ]);

                    if ($primeraFirma === null) {
                        $primeraFirma = $firmaCreada;
                    }
                }
            } else {
                $primeraFirma = PermisoTrazabilidadFirma::create([
                    'permiso_id' => $solicitud->id,
                    'orden' => 1,
                    'insamu_visador_id' => trim((string) $datos['insamu_visador_id']),
                    'nombre_visador' => $datos['nombre_visador'] ?? null,
                    'rut_visador' => $datos['rut_visador'] ?? null,
                    'cargo_visador' => $datos['cargo_visador'] ?? ($esDireccionInicial ? 'Dirección' : 'Jefatura Directa'),
                    'rol_firma' => $rolFirmaInicial,
                    'estado_firma' => 'pendiente',
                    'es_subrogante' => (bool) ($datos['es_subrogante'] ?? false),
                    'token_correo' => PermisoTrazabilidadFirma::generarToken(),
                ]);
            }

            // 6. Registrar log de auditoría
            LogSistema::registrar(
                'CREACION_SOLICITUD',
                $solicitud->insamu_user_id,
                'permisos_solicitudes',
                $solicitud->id,
                [
                    'dias_solicitados' => $diasSolicitados,
                    'horas_solicitadas' => $horasSolicitadas,
                    'jornada' => $solicitud->jornada,
                    'fecha_inicio' => $solicitud->fecha_inicio,
                    'fecha_fin' => $solicitud->fecha_fin,
                    'visador_inicial_id' => $primeraFirma?->insamu_visador_id,
                    'total_visadores' => $tieneCircuitoExplicito ? count($visadoresCircuit) : 1,
                    'token_correo' => $primeraFirma?->token_correo,
                    'tiene_adjunto' => ! empty($solicitud->archivo_adjunto_url),
                ]
            );

            return $solicitud->load('firmas');
        });
    }

    /**
     * Determina la etapa jerárquica de un rol de firma:
     * Etapa 1: Visadores / Jefaturas directas (pueden firmar en cualquier orden entre sí).
     * Etapa 2: Dirección CESFAM (requiere que toda la Etapa 1 haya firmado).
     * Etapa 3: Dirección DESAM / Dirección General (requiere que Etapas 1 y 2 hayan firmado).
     */
    public static function resolverEtapaRol(?string $rol): int
    {
        $norm = mb_strtolower(trim((string) $rol));

        if (in_array($norm, [
            'direccion_cesfam',
            'direccion_cesfam_donihue',
            'direccion_cesfam_lo_miranda',
            'dirección cesfam',
            'direccion cesfam',
            'dirección cesfam doñihue',
            'dirección cesfam lo miranda',
        ], true)) {
            return 2;
        }

        if (in_array($norm, ['direccion_desam', 'dirección desam', 'direccion desam', 'direccion', 'dirección', 'direccion_general', 'direccion_comunal'], true)) {
            return 3;
        }

        return 1;
    }

    /**
     * Procesar la confirmación de Firma Electrónica Simple (FES) vía PIN validado en INSAMU.
     */
    public function confirmarFirma(array $datos): PermisoSolicitud
    {
        return DB::transaction(function () use ($datos) {
            $firma = null;

            if (! empty($datos['token_correo'])) {
                $firma = PermisoTrazabilidadFirma::query()
                    ->where('estado_firma', 'pendiente')
                    ->where('token_correo', $datos['token_correo'])
                    ->orderBy('orden', 'asc')
                    ->orderBy('id', 'asc')
                    ->first();

                if ($firma) {
                    $etapaActivaToken = PermisoTrazabilidadFirma::query()
                        ->where('permiso_id', $firma->permiso_id)
                        ->where('estado_firma', 'pendiente')
                        ->get()
                        ->map(fn (PermisoTrazabilidadFirma $f) => self::resolverEtapaRol($f->rol_firma))
                        ->min();

                    if ($etapaActivaToken !== null && self::resolverEtapaRol($firma->rol_firma) !== $etapaActivaToken) {
                        throw new Exception('No se puede firmar esta etapa mientras existan visaturas previas pendientes.');
                    }
                }
            }

            if (! $firma && ! empty($datos['permiso_id'])) {
                $permisoId = (int) $datos['permiso_id'];
                $visId = trim((string) ($datos['insamu_visador_id'] ?? ''));
                $visIdLower = strtolower($visId);
                $cleanRut = strtolower(preg_replace('/[^0-9kK]/', '', (string) ($datos['rut_firmante'] ?? $visId)));

                $pendientes = PermisoTrazabilidadFirma::query()
                    ->where('permiso_id', $permisoId)
                    ->where('estado_firma', 'pendiente')
                    ->orderBy('orden', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

                if ($pendientes->isNotEmpty()) {
                    $etapaActiva = $pendientes
                        ->map(fn (PermisoTrazabilidadFirma $f) => self::resolverEtapaRol($f->rol_firma))
                        ->min();

                    $pendientesEtapa = $pendientes->filter(
                        fn (PermisoTrazabilidadFirma $f) => self::resolverEtapaRol($f->rol_firma) === $etapaActiva
                    );

                    $coincideConFirmante = function (PermisoTrazabilidadFirma $f) use ($visId, $visIdLower, $cleanRut): bool {
                        $fVisId = trim((string) $f->insamu_visador_id);
                        $fVisLower = strtolower($fVisId);
                        $fRutClean = strtolower(preg_replace('/[^0-9kK]/', '', (string) $f->rut_visador));

                        if ($visIdLower !== '' && ($fVisId === $visId || $fVisLower === $visIdLower)) {
                            return true;
                        }

                        if ($cleanRut !== '' && $fRutClean !== '' && $fRutClean === $cleanRut) {
                            return true;
                        }

                        if (in_array($fVisLower, ['direccion_general', 'direccion_comunal'], true)) {
                            return true;
                        }

                        return false;
                    };

                    $firma = $pendientesEtapa->first($coincideConFirmante);

                    if (! $firma && ! empty($datos['es_subrogante']) && (bool) $datos['es_subrogante'] === true && $pendientesEtapa->count() === 1) {
                        $firma = $pendientesEtapa->first();
                    }

                    if (! $firma) {
                        $firmaEnEtapaPosterior = $pendientes->first($coincideConFirmante);
                        if ($firmaEnEtapaPosterior) {
                            throw new Exception('No se puede firmar esta etapa mientras existan visaturas previas pendientes.');
                        }

                        throw new Exception('El usuario indicado no corresponde a un visador autorizado en la etapa activa de esta solicitud.');
                    }
                }
            }

            if (! $firma) {
                throw new Exception('No se encontró una firma pendiente válida para esta solicitud.');
            }

            if (in_array($firma->insamu_visador_id, ['direccion_general', 'direccion_comunal'], true) && ! empty($datos['insamu_visador_id'])) {
                $firma->insamu_visador_id = trim((string) $datos['insamu_visador_id']);
            }

            $solicitud = $firma->solicitud;

            $solicitanteIdLower = strtolower(trim((string) $solicitud->insamu_user_id));
            $solicitanteRutClean = strtolower(preg_replace('/[^0-9kK]/', '', (string) $solicitud->rut_solicitante));
            $firmanteIdLower = strtolower(trim((string) ($datos['insamu_visador_id'] ?? $firma->insamu_visador_id ?? '')));
            $firmanteRutClean = strtolower(preg_replace('/[^0-9kK]/', '', (string) ($datos['rut_firmante'] ?? $firma->rut_visador ?? '')));

            if (
                ($firmanteIdLower !== '' && $solicitanteIdLower !== '' && $firmanteIdLower === $solicitanteIdLower)
                || ($firmanteRutClean !== '' && $solicitanteRutClean !== '' && $firmanteRutClean === $solicitanteRutClean)
            ) {
                throw new Exception('El funcionario solicitante no puede visar ni firmar su propia solicitud de permiso.');
            }

            if (in_array($solicitud->estado, [
                PermisoSolicitud::ESTADO_ANULADO,
                PermisoSolicitud::ESTADO_RECHAZADO,
                PermisoSolicitud::ESTADO_PENDIENTE_ANULACION,
            ], true)) {
                throw new Exception("La solicitud se encuentra en estado '{$solicitud->estado}' y no permite firmas.");
            }

            $accion = strtolower($datos['accion'] ?? 'aprobar');
            $datosVisador = [
                'nombre' => $datos['nombre_firmante'] ?? $firma->nombre_visador,
                'rut' => $datos['rut_firmante'] ?? $firma->rut_visador,
                'cargo' => $datos['cargo_firmante'] ?? $firma->cargo_visador,
                'observaciones' => $datos['observaciones'] ?? null,
            ];

            if (array_key_exists('es_subrogante', $datos)) {
                $datosVisador['es_subrogante'] = (bool) $datos['es_subrogante'];
            }

            if ($accion === 'aprobar') {
                $firma->registrarAprobacion($datosVisador);

                $visadores = is_array($solicitud->visadores) ? $solicitud->visadores : [];
                if (! empty($visadores)) {
                    $actualizadoEnArray = false;
                    foreach ($visadores as &$v) {
                        $coincideOrden = isset($v['orden']) && (int) $v['orden'] === (int) $firma->orden;
                        $coincideEmail = ! empty($v['email']) && strtolower(trim((string) $v['email'])) === strtolower(trim((string) $firma->insamu_visador_id));
                        $coincideRol = ! empty($v['rol']) && $v['rol'] === $firma->rol_firma;

                        if (empty($v['firmado']) && ($coincideOrden || $coincideEmail || $coincideRol)) {
                            $v['firmado'] = true;
                            $v['fecha_firma'] = ($firma->fecha_accion ?? now())->toIso8601String();
                            $v['firmado_por_nombre'] = $firma->nombre_visador;
                            $v['firmado_por_email'] = $firma->insamu_visador_id;
                            $v['firmado_por_rut'] = $firma->rut_visador;
                            $v['es_subrogante'] = (bool) $firma->es_subrogante;
                            if (! empty($firma->observaciones)) {
                                $v['observaciones'] = $firma->observaciones;
                            }
                            $actualizadoEnArray = true;
                            break;
                        }
                    }
                    unset($v);

                    if (! $actualizadoEnArray) {
                        foreach ($visadores as &$v) {
                            if (empty($v['firmado'])) {
                                $v['firmado'] = true;
                                $v['fecha_firma'] = ($firma->fecha_accion ?? now())->toIso8601String();
                                $v['firmado_por_nombre'] = $firma->nombre_visador;
                                $v['firmado_por_email'] = $firma->insamu_visador_id;
                                $v['firmado_por_rut'] = $firma->rut_visador;
                                $v['es_subrogante'] = (bool) $firma->es_subrogante;
                                break;
                            }
                        }
                        unset($v);
                    }

                    $solicitud->visadores = $visadores;
                }

                // Verificar si quedan más firmas pendientes en el circuito
                $firmasPendientesRestantes = PermisoTrazabilidadFirma::query()
                    ->where('permiso_id', $solicitud->id)
                    ->where('estado_firma', 'pendiente')
                    ->orderBy('orden', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

                if ($firmasPendientesRestantes->isEmpty()) {
                    $esFirmaDireccion = self::resolverEtapaRol($firma->rol_firma) === 3
                        || in_array(strtolower((string) $firma->insamu_visador_id), ['direccion_general', 'direccion_comunal'], true);

                    if (empty($visadores) && $solicitud->estado === PermisoSolicitud::ESTADO_PENDIENTE_VISATURA && ! $esFirmaDireccion) {
                        $solicitud->estado = PermisoSolicitud::ESTADO_PENDIENTE_DIRECCION;
                        PermisoTrazabilidadFirma::create([
                            'permiso_id' => $solicitud->id,
                            'orden' => ((int) $firma->orden) + 1,
                            'insamu_visador_id' => trim((string) ($datos['siguiente_visador_id'] ?? 'direccion_general')),
                            'nombre_visador' => $datos['siguiente_nombre_visador'] ?? 'Dirección General',
                            'rut_visador' => $datos['siguiente_rut_visador'] ?? null,
                            'cargo_visador' => $datos['siguiente_cargo_visador'] ?? 'Director(a)',
                            'rol_firma' => 'Dirección',
                            'estado_firma' => 'pendiente',
                            'es_subrogante' => false,
                            'token_correo' => PermisoTrazabilidadFirma::generarToken(),
                        ]);
                    } else {
                        $solicitud->estado = PermisoSolicitud::ESTADO_EN_RRHH;
                    }
                } else {
                    $etapaSiguiente = $firmasPendientesRestantes
                        ->map(fn (PermisoTrazabilidadFirma $f) => self::resolverEtapaRol($f->rol_firma))
                        ->min();

                    if ($etapaSiguiente === 3) {
                        $solicitud->estado = PermisoSolicitud::ESTADO_PENDIENTE_DIRECCION;
                    } else {
                        $solicitud->estado = PermisoSolicitud::ESTADO_PENDIENTE_VISATURA;
                    }
                }

                $solicitud->save();

                LogSistema::registrar(
                    'FIRMA_ELECTRONICA_APROBADA',
                    $firma->insamu_visador_id,
                    'permisos_solicitudes',
                    $solicitud->id,
                    [
                        'orden' => $firma->orden,
                        'rol_firma' => $firma->rol_firma,
                        'es_subrogante' => $firma->es_subrogante,
                        'observaciones' => $firma->observaciones,
                        'firmas_pendientes_restantes' => $firmasPendientesRestantes->count(),
                        'nuevo_estado' => $solicitud->estado,
                    ]
                );
            } elseif ($accion === 'rechazar') {
                $motivo = $datos['motivo_rechazo'] ?? 'Rechazado sin motivo especificado';
                $firma->registrarRechazo($motivo, $datosVisador);

                PermisoTrazabilidadFirma::query()
                    ->where('permiso_id', $solicitud->id)
                    ->where('estado_firma', 'pendiente')
                    ->update(['token_correo' => null]);

                $solicitud->estado = PermisoSolicitud::ESTADO_RECHAZADO;
                $solicitud->save();

                $tipoSaldo = $solicitud->tipo_saldo;
                if ($tipoSaldo !== null) {
                    $saldo = $this->buscarSaldoUsuario($solicitud->insamu_user_id, $solicitud->anio_imputacion, $tipoSaldo);
                    $saldo?->restituirCantidad($solicitud->cantidad_solicitada);
                }

                LogSistema::registrar(
                    'FIRMA_ELECTRONICA_RECHAZADA',
                    $firma->insamu_visador_id,
                    'permisos_solicitudes',
                    $solicitud->id,
                    [
                        'rol_firma' => $firma->rol_firma,
                        'motivo_rechazo' => $motivo,
                        'cantidad_restituida' => $solicitud->cantidad_solicitada,
                        'tipo_saldo' => $tipoSaldo,
                    ]
                );
            } else {
                throw new Exception("Acción no reconocida: {$accion}. Use 'aprobar' o 'rechazar'.");
            }

            return $solicitud->fresh(['firmas']);
        });
    }

    /**
     * Subrogancia autogestionada: cambiar visador de un paso pendiente del circuito.
     * Invalida de inmediato el token_correo original y genera uno nuevo para el subrogante.
     */
    public function subrogarVisador(PermisoSolicitud $solicitud, array $datos): array
    {
        if (! $solicitud->esPendiente()) {
            throw new Exception("Solo se puede asignar subrogante mientras la solicitud esté en estado pendiente ('{$solicitud->estado}').");
        }

        return DB::transaction(function () use ($solicitud, $datos) {
            $targetEmail = strtolower(trim((string) ($datos['target_visador_email'] ?? '')));
            $targetRol = trim((string) ($datos['target_rol'] ?? ''));
            $targetOrden = isset($datos['target_orden']) ? (int) $datos['target_orden'] : null;

            $query = PermisoTrazabilidadFirma::query()
                ->where('permiso_id', $solicitud->id)
                ->where('estado_firma', 'pendiente');

            if ($targetOrden !== null && $targetOrden > 0) {
                $query->where('orden', $targetOrden);
            } elseif ($targetRol !== '') {
                $query->where('rol_firma', $targetRol);
            } elseif ($targetEmail !== '') {
                $query->whereRaw('LOWER(insamu_visador_id) = ?', [$targetEmail]);
            }

            /** @var PermisoTrazabilidadFirma|null $firmaPendiente */
            $firmaPendiente = $query->orderBy('orden', 'asc')->orderBy('id', 'asc')->first();

            if (! $firmaPendiente) {
                $firmaPendiente = $solicitud->firmaPendiente;
            }

            if (! $firmaPendiente) {
                throw new Exception('No existe una firma pendiente para subrogar en esta solicitud.');
            }

            $tokenOriginal = $firmaPendiente->token_correo;
            $visadorOriginalId = $firmaPendiente->insamu_visador_id;
            $ordenPaso = (int) ($firmaPendiente->orden ?: 1);
            $rol = $firmaPendiente->rol_firma;
            $cargo = $datos['nuevo_cargo_visador'] ?? $firmaPendiente->cargo_visador ?? $rol;
            $nuevoVisadorEmail = trim((string) $datos['nuevo_visador_id']);
            $nuevoVisadorLower = strtolower($nuevoVisadorEmail);
            $nuevoRutClean = strtolower(preg_replace('/[^0-9kK]/', '', (string) ($datos['nuevo_rut_visador'] ?? '')));
            $esSubrogante = array_key_exists('es_subrogante', $datos) ? (bool) $datos['es_subrogante'] : true;

            $solicitanteIdLower = strtolower(trim((string) $solicitud->insamu_user_id));
            $solicitanteRutClean = strtolower(preg_replace('/[^0-9kK]/', '', (string) $solicitud->rut_solicitante));

            if (! empty($datos['insamu_user_id'])) {
                $editorIdLower = strtolower(trim((string) $datos['insamu_user_id']));
                $editorRutClean = strtolower(preg_replace('/[^0-9kK]/', '', (string) ($datos['insamu_user_rut'] ?? $datos['insamu_user_id'])));
                $esOwner = ($editorIdLower !== '' && $editorIdLower === $solicitanteIdLower)
                    || ($editorRutClean !== '' && $solicitanteRutClean !== '' && $editorRutClean === $solicitanteRutClean);

                if (! $esOwner) {
                    throw new Exception('Solo el funcionario creador de la solicitud tiene autorización para modificar los visadores.');
                }
            }

            if (
                ($nuevoVisadorLower !== '' && $solicitanteIdLower !== '' && $nuevoVisadorLower === $solicitanteIdLower)
                || ($nuevoRutClean !== '' && $solicitanteRutClean !== '' && $nuevoRutClean === $solicitanteRutClean)
            ) {
                throw new Exception('El funcionario solicitante no puede asignarse a sí mismo como visador o subrogante.');
            }

            $duplicadoEnOtroPaso = PermisoTrazabilidadFirma::query()
                ->where('permiso_id', $solicitud->id)
                ->whereIn('estado_firma', ['pendiente', 'aprobado'])
                ->where('id', '!=', $firmaPendiente->id)
                ->where('orden', '!=', $ordenPaso)
                ->where(function ($q) use ($nuevoVisadorLower, $nuevoRutClean) {
                    $q->whereRaw('LOWER(insamu_visador_id) = ?', [$nuevoVisadorLower]);
                    if ($nuevoRutClean !== '') {
                        $q->orWhereRaw("REPLACE(REPLACE(LOWER(rut_visador), '.', ''), '-', '') = ?", [$nuevoRutClean]);
                    }
                })
                ->exists();

            if ($duplicadoEnOtroPaso) {
                throw new Exception('El funcionario seleccionado ya forma parte de otra etapa del circuito de firmas de esta solicitud.');
            }

            // Invalida inmediatamente el token_correo del visador original
            $firmaPendiente->token_correo = null;
            $firmaPendiente->estado_firma = 'subrogado';
            $firmaPendiente->motivo_rechazo = 'Subrogado por: '.($datos['nuevo_nombre_visador'] ?? $nuevoVisadorEmail);
            $firmaPendiente->save();

            // Genera nuevo registro para el subrogante conservando el mismo orden del circuito
            $nuevoToken = PermisoTrazabilidadFirma::generarToken();
            $nuevaFirma = PermisoTrazabilidadFirma::create([
                'permiso_id' => $solicitud->id,
                'orden' => $ordenPaso,
                'insamu_visador_id' => $nuevoVisadorEmail,
                'nombre_visador' => $datos['nuevo_nombre_visador'] ?? null,
                'rut_visador' => $datos['nuevo_rut_visador'] ?? null,
                'cargo_visador' => $cargo,
                'rol_firma' => $rol,
                'estado_firma' => 'pendiente',
                'es_subrogante' => $esSubrogante,
                'token_correo' => $nuevoToken,
            ]);

            // Sincronizar el array visadores en la solicitud para mantener coherencia total
            $visadores = is_array($solicitud->visadores) ? $solicitud->visadores : [];
            if (! empty($visadores)) {
                $actualizado = false;
                foreach ($visadores as &$v) {
                    $coincideOrden = isset($v['orden']) && (int) $v['orden'] === $ordenPaso;
                    $coincideRol = ! empty($v['rol']) && $v['rol'] === $rol;
                    $coincideEmail = ! empty($v['email']) && strtolower(trim((string) $v['email'])) === strtolower(trim((string) $visadorOriginalId));

                    if (empty($v['firmado']) && ($coincideOrden || $coincideRol || $coincideEmail)) {
                        $v['email'] = $nuevoVisadorEmail;
                        $v['nombre'] = $datos['nuevo_nombre_visador'] ?? $v['nombre'] ?? '';
                        $v['rut'] = $datos['nuevo_rut_visador'] ?? $v['rut'] ?? '';
                        $v['cargo'] = $cargo;
                        $v['es_subrogante'] = $esSubrogante;
                        $actualizado = true;
                        break;
                    }
                }
                unset($v);

                if (! $actualizado) {
                    foreach ($visadores as &$v) {
                        if (empty($v['firmado'])) {
                            $v['email'] = $nuevoVisadorEmail;
                            $v['nombre'] = $datos['nuevo_nombre_visador'] ?? $v['nombre'] ?? '';
                            $v['rut'] = $datos['nuevo_rut_visador'] ?? $v['rut'] ?? '';
                            $v['cargo'] = $cargo;
                            $v['es_subrogante'] = $esSubrogante;
                            break;
                        }
                    }
                    unset($v);
                }

                $solicitud->visadores = $visadores;
                $solicitud->save();
            }

            // Auditoría del cambio de subrogancia
            LogSistema::registrar(
                'SUBROGANCIA_VISADOR',
                $solicitud->insamu_user_id,
                'permisos_solicitudes',
                $solicitud->id,
                [
                    'orden' => $ordenPaso,
                    'visador_anterior_id' => $visadorOriginalId,
                    'nuevo_visador_subrogante_id' => $nuevaFirma->insamu_visador_id,
                    'es_subrogante' => $esSubrogante,
                    'token_anterior_invalidado' => ! empty($tokenOriginal),
                    'nuevo_token_generado' => $nuevoToken,
                    'rol_firma' => $rol,
                ]
            );

            return [
                'solicitud' => $solicitud->fresh('firmas'),
                'firma_subrogante' => $nuevaFirma,
                'token_invalidado' => $tokenOriginal,
                'nuevo_token' => $nuevoToken,
            ];
        });
    }

    /**
     * Solicitar anulación de un permiso. Pone la solicitud en 'pendiente_anulacion' y bloquea el PDF.
     */
    public function solicitarAnulacion(PermisoSolicitud $solicitud, array $datos = []): PermisoSolicitud
    {
        if (in_array($solicitud->estado, [
            PermisoSolicitud::ESTADO_ANULADO,
            PermisoSolicitud::ESTADO_RECHAZADO,
            PermisoSolicitud::ESTADO_PENDIENTE_ANULACION,
        ], true)) {
            throw new Exception("La solicitud no puede ser enviada a anulación desde el estado '{$solicitud->estado}'.");
        }

        if (! empty($datos['insamu_user_id'])) {
            $editorIdLower = strtolower(trim((string) $datos['insamu_user_id']));
            $solicitanteIdLower = strtolower(trim((string) $solicitud->insamu_user_id));
            $editorRutClean = strtolower(preg_replace('/[^0-9kK]/', '', (string) ($datos['insamu_user_rut'] ?? $datos['insamu_user_id'])));
            $solicitanteRutClean = strtolower(preg_replace('/[^0-9kK]/', '', (string) $solicitud->rut_solicitante));

            $esOwner = ($editorIdLower !== '' && $editorIdLower === $solicitanteIdLower)
                || ($editorRutClean !== '' && $solicitanteRutClean !== '' && $editorRutClean === $solicitanteRutClean);

            if (! $esOwner) {
                throw new Exception('Solo el funcionario solicitante puede solicitar la anulación de esta solicitud.');
            }
        }

        return DB::transaction(function () use ($solicitud, $datos) {
            $solicitud->estado_previo_anulacion = $solicitud->estado;
            $solicitud->estado = PermisoSolicitud::ESTADO_PENDIENTE_ANULACION;
            if (! empty($datos['motivo'])) {
                $solicitud->motivo_anulacion = $datos['motivo'];
            }
            $solicitud->solicitud_anulacion_en = now();
            $solicitud->save();

            LogSistema::registrar(
                'SOLICITUD_ANULACION',
                $datos['insamu_user_id'] ?? $solicitud->insamu_user_id,
                'permisos_solicitudes',
                $solicitud->id,
                [
                    'motivo_anulacion' => $solicitud->motivo_anulacion ?? 'Solicitud de anulación enviada por el usuario',
                    'estado_anterior' => $solicitud->estado_previo_anulacion,
                ]
            );

            return $solicitud->fresh(['firmas']);
        });
    }

    /**
     * Anulación directa instantánea de un permiso no decretado por el funcionario solicitante.
     * Restituye saldo inmediatamente y pasa la solicitud a estado 'anulado'.
     */
    public function anularDirecto(PermisoSolicitud $solicitud, array $datos = []): PermisoSolicitud
    {
        if (in_array($solicitud->estado, [
            PermisoSolicitud::ESTADO_ANULADO,
            PermisoSolicitud::ESTADO_RECHAZADO,
        ], true)) {
            throw new Exception("La solicitud ya se encuentra en estado '{$solicitud->estado}'.");
        }

        if ($solicitud->estado === PermisoSolicitud::ESTADO_DECRETADO || ! empty($solicitud->decreto_numero)) {
            throw new Exception('El documento ya se encuentra decretado. Debe solicitar autorización formal a Recursos Humanos para anularlo.');
        }

        if (! empty($datos['insamu_user_id'])) {
            $editorIdLower = strtolower(trim((string) $datos['insamu_user_id']));
            $solicitanteIdLower = strtolower(trim((string) $solicitud->insamu_user_id));
            $editorRutClean = strtolower(preg_replace('/[^0-9kK]/', '', (string) ($datos['insamu_user_rut'] ?? $datos['insamu_user_id'])));
            $solicitanteRutClean = strtolower(preg_replace('/[^0-9kK]/', '', (string) $solicitud->rut_solicitante));

            $esOwner = ($editorIdLower !== '' && $editorIdLower === $solicitanteIdLower)
                || ($editorRutClean !== '' && $solicitanteRutClean !== '' && $editorRutClean === $solicitanteRutClean);

            if (! $esOwner) {
                throw new Exception('Solo el funcionario solicitante puede anular esta solicitud.');
            }
        }

        return DB::transaction(function () use ($solicitud, $datos) {
            $solicitud->estado_previo_anulacion = $solicitud->estado;
            $solicitud->estado = PermisoSolicitud::ESTADO_ANULADO;
            if (! empty($datos['motivo'])) {
                $solicitud->motivo_anulacion = $datos['motivo'];
            }
            $solicitud->anulado_por = $datos['anulado_por'] ?? $datos['insamu_user_id'] ?? $solicitud->nombre_solicitante ?? 'Solicitante';
            $solicitud->anulado_en = now();
            $solicitud->save();

            // Restituir saldo inmediatamente
            $tipoSaldo = $solicitud->tipo_saldo;
            if ($tipoSaldo !== null) {
                $saldo = $this->buscarSaldoUsuario($solicitud->insamu_user_id, $solicitud->anio_imputacion, $tipoSaldo);
                $saldo?->restituirCantidad($solicitud->cantidad_solicitada);
            }

            // Invalidar tokens pendientes de firma
            $solicitud->firmas()->where('estado_firma', 'pendiente')->update([
                'token_correo' => null,
            ]);

            LogSistema::registrar(
                'ANULACION_DIRECTA',
                $datos['insamu_user_id'] ?? $solicitud->insamu_user_id,
                'permisos_solicitudes',
                $solicitud->id,
                [
                    'motivo_anulacion' => $solicitud->motivo_anulacion ?? 'Anulación directa con PIN efectuada por el funcionario',
                    'anulado_por' => $solicitud->anulado_por,
                    'estado_anterior' => $solicitud->estado_previo_anulacion,
                    'cantidad_restituida' => $solicitud->cantidad_solicitada,
                    'tipo_saldo' => $tipoSaldo,
                ]
            );

            return $solicitud->fresh(['firmas']);
        });
    }

    /**
     * RRHH: Aprobar anulación de permiso. Restituye saldo y pasa a 'anulado'.
     */
    public function aprobarAnulacion(PermisoSolicitud $solicitud, array $datos = []): PermisoSolicitud
    {
        if ($solicitud->estado !== PermisoSolicitud::ESTADO_PENDIENTE_ANULACION) {
            throw new Exception("Solo se pueden aprobar anulaciones de solicitudes en estado 'pendiente_anulacion'. Estado actual: '{$solicitud->estado}'.");
        }

        return DB::transaction(function () use ($solicitud, $datos) {
            $solicitud->estado = PermisoSolicitud::ESTADO_ANULADO;
            $solicitud->anulado_en = now();
            $solicitud->anulado_por = $datos['insamu_rrhh_user_id'] ?? 'RRHH';
            if (! empty($datos['observaciones']) && empty($solicitud->motivo_anulacion)) {
                $solicitud->motivo_anulacion = $datos['observaciones'];
            }
            $solicitud->save();

            // Restituir el saldo al usuario (si el permiso descuenta saldo)
            $tipoSaldo = $solicitud->tipo_saldo;
            $saldo = null;
            if ($tipoSaldo !== null) {
                $saldo = $this->buscarSaldoUsuario($solicitud->insamu_user_id, $solicitud->anio_imputacion, $tipoSaldo);
                $saldo?->restituirCantidad($solicitud->cantidad_solicitada);
            }

            LogSistema::registrar(
                'APROBACION_ANULACION',
                $datos['insamu_rrhh_user_id'] ?? null,
                'permisos_solicitudes',
                $solicitud->id,
                [
                    'cantidad_restituida' => $solicitud->cantidad_solicitada,
                    'tipo_saldo' => $tipoSaldo,
                    'nuevo_saldo_disponible' => $saldo?->cantidad_disponible,
                ]
            );

            return $solicitud->fresh(['firmas']);
        });
    }

    /**
     * RRHH: Rechazar anulación (por ejemplo, si ya fue decretado externamente).
     * Restaura el estado previo.
     */
    public function rechazarAnulacion(PermisoSolicitud $solicitud, array $datos = []): PermisoSolicitud
    {
        if ($solicitud->estado !== PermisoSolicitud::ESTADO_PENDIENTE_ANULACION) {
            throw new Exception("Solo se pueden rechazar anulaciones de solicitudes en estado 'pendiente_anulacion'. Estado actual: '{$solicitud->estado}'.");
        }

        return DB::transaction(function () use ($solicitud, $datos) {
            $estadoPrevio = $solicitud->estado_previo_anulacion ?? PermisoSolicitud::ESTADO_DECRETADO;
            $solicitud->estado = $estadoPrevio;
            $solicitud->anulacion_rechazada_en = now();
            $solicitud->anulacion_rechazo_motivo = $datos['motivo'] ?? null;
            $solicitud->save();

            LogSistema::registrar(
                'RECHAZO_ANULACION',
                $datos['insamu_rrhh_user_id'] ?? null,
                'permisos_solicitudes',
                $solicitud->id,
                [
                    'motivo' => $datos['motivo'] ?? 'Anulación rechazada por RRHH (ya decretado externamente u otra razón)',
                    'estado_restaurado' => $estadoPrevio,
                ]
            );

            return $solicitud->fresh(['firmas']);
        });
    }

    /**
     * RRHH: Decretar solicitud directamente.
     */
    public function decretar(PermisoSolicitud $solicitud, array $datos): PermisoSolicitud
    {
        if (in_array($solicitud->estado, [
            PermisoSolicitud::ESTADO_ANULADO,
            PermisoSolicitud::ESTADO_RECHAZADO,
            PermisoSolicitud::ESTADO_PENDIENTE_ANULACION,
            PermisoSolicitud::ESTADO_PENDIENTE_VISATURA,
            PermisoSolicitud::ESTADO_PENDIENTE_DIRECCION,
        ], true)) {
            throw new Exception("No se puede decretar una solicitud en estado '{$solicitud->estado}'. Solo solicitudes firmadas o en RRHH pueden ser decretadas.");
        }

        return DB::transaction(function () use ($solicitud, $datos) {
            $solicitud->estado = PermisoSolicitud::ESTADO_DECRETADO;
            $solicitud->decreto_numero = $datos['decreto_numero'] ?? null;
            $solicitud->fecha_decreto = $datos['fecha_decreto'] ?? now()->toDateString();
            if (array_key_exists('observaciones', $datos)) {
                $solicitud->decreto_observaciones = $datos['observaciones'];
            }
            $solicitud->save();

            LogSistema::registrar(
                'DECRETADO',
                $datos['insamu_user_id'] ?? null,
                'permisos_solicitudes',
                $solicitud->id,
                [
                    'decreto_numero' => $solicitud->decreto_numero,
                    'fecha_decreto' => $solicitud->fecha_decreto,
                    'decreto_observaciones' => $solicitud->decreto_observaciones,
                ]
            );

            return $solicitud->fresh(['firmas']);
        });
    }

    /**
     * Valida que no existan solapamientos indebidos de fechas para el mismo funcionario.
     * Reglas:
     * 1. Si la solicitud nueva o la existente es de Día Completo (jornada Completa / días >= 1), no se permite ninguna otra solicitud en esa fecha.
     * 2. Si ambas son parciales (ej. media jornada administrativa + compensación horaria), se permite la concurrencia.
     * 3. Si ambas son media jornada y coinciden en el mismo turno (ej. Mañana con Mañana), se bloquea.
     * 4. Si ambas son compensaciones de horas en la misma fecha y sus rangos horarios se cruzan, se bloquea.
     */
    public function validarSolapamientoFechas(
        string $userId,
        ?string $rutSolicitante,
        string $fechaInicioStr,
        string $fechaFinStr,
        string $jornada,
        float $diasSolicitados,
        ?float $horasSolicitadas,
        ?string $horaDesde,
        ?string $horaHasta
    ): void {
        $userIdLower = strtolower(trim($userId));
        $rutClean = strtolower(preg_replace('/[^0-9kK]/', '', (string) $rutSolicitante));

        $solicitudes = PermisoSolicitud::query()
            ->where(function ($q) use ($userIdLower, $rutClean) {
                $q->whereRaw('LOWER(insamu_user_id) = ?', [$userIdLower]);
                if ($rutClean !== '') {
                    $q->orWhereRaw("REPLACE(REPLACE(LOWER(rut_solicitante), '.', ''), '-', '') = ?", [$rutClean]);
                }
            })
            ->whereNotIn('estado', [
                PermisoSolicitud::ESTADO_ANULADO,
                PermisoSolicitud::ESTADO_RECHAZADO,
            ])
            ->where(function ($q) use ($fechaInicioStr, $fechaFinStr) {
                $q->where('fecha_inicio', '<=', $fechaFinStr)
                    ->where('fecha_fin', '>=', $fechaInicioStr);
            })
            ->get();

        if ($solicitudes->isEmpty()) {
            return;
        }

        $jornadaLower = strtolower(trim($jornada));
        $esHorasNuevo = ! empty($horasSolicitadas) || $jornadaLower === 'horas';
        $esMediaJornadaNuevo = in_array($jornadaLower, ['mañana', 'tarde'], true) || ($diasSolicitados == 0.5 && ! $esHorasNuevo);
        $esDiaCompletoNuevo = ! $esHorasNuevo && ! $esMediaJornadaNuevo;

        $inicioNuevo = Carbon::parse($fechaInicioStr);
        $finNuevo = Carbon::parse($fechaFinStr);

        foreach ($solicitudes as $existente) {
            $inicioExistente = Carbon::parse($existente->fecha_inicio);
            $finExistente = Carbon::parse($existente->fecha_fin);

            $maxInicio = $inicioNuevo->greaterThan($inicioExistente) ? $inicioNuevo->copy() : $inicioExistente->copy();
            $minFin = $finNuevo->lessThan($finExistente) ? $finNuevo->copy() : $finExistente->copy();

            if ($maxInicio->greaterThan($minFin)) {
                continue;
            }

            $diaSolapado = $maxInicio->format('d/m/Y');

            $jornadaExistLower = strtolower(trim((string) $existente->jornada));
            $esHorasExistente = ! empty($existente->horas_solicitadas) || ($jornadaExistLower === 'horas');
            $esMediaJornadaExistente = in_array($jornadaExistLower, ['mañana', 'tarde'], true)
                || ((float) $existente->dias_solicitados == 0.5 && ! $esHorasExistente);
            $esDiaCompletoExistente = ! $esHorasExistente && ! $esMediaJornadaExistente;

            // Regla 1: Si la nueva solicitud es día completo
            if ($esDiaCompletoNuevo) {
                throw ValidationException::withMessages([
                    'fecha_inicio' => [
                        "No es posible solicitar un permiso de jornada completa para el {$diaSolapado}: ya cuenta con una solicitud activa ({$existente->tipo_permiso}, Solicitud #{$existente->id}) para esa misma fecha.",
                    ],
                ]);
            }

            // Regla 2: Si la solicitud existente es día completo
            if ($esDiaCompletoExistente) {
                throw ValidationException::withMessages([
                    'fecha_inicio' => [
                        "No es posible ingresar la solicitud: ya cuenta con un permiso de jornada completa ({$existente->tipo_permiso}, Solicitud #{$existente->id}) para el {$diaSolapado}.",
                    ],
                ]);
            }

            // Regla 3: Si ambas son media jornada y comparten turno (mañana con mañana, o tarde con tarde)
            if ($esMediaJornadaNuevo && $esMediaJornadaExistente) {
                if ($jornadaLower === $jornadaExistLower || empty($jornadaExistLower)) {
                    $turnoTexto = $jornadaLower === 'mañana' ? 'la mañana' : ($jornadaLower === 'tarde' ? 'la tarde' : 'media jornada');
                    throw ValidationException::withMessages([
                        'jornada' => [
                            "Ya registra una solicitud activa de media jornada en {$turnoTexto} ({$existente->tipo_permiso}, Solicitud #{$existente->id}) para el {$diaSolapado}.",
                        ],
                    ]);
                }
            }

            // Regla 4: Si ambas son compensación horaria en la misma fecha y se cruzan en horario
            if ($esHorasNuevo && $esHorasExistente) {
                if ($horaDesde && $horaHasta && $existente->hora_desde && $existente->hora_hasta) {
                    $hDesdeNueva = substr($horaDesde, 0, 5);
                    $hHastaNueva = substr($horaHasta, 0, 5);
                    $hDesdeExist = substr($existente->hora_desde, 0, 5);
                    $hHastaExist = substr($existente->hora_hasta, 0, 5);

                    if ($hDesdeNueva < $hHastaExist && $hHastaNueva > $hDesdeExist) {
                        throw ValidationException::withMessages([
                            'hora_desde' => [
                                "El horario solicitado ({$hDesdeNueva} a {$hHastaNueva}) se solapa con otro permiso de compensación horaria activo (#{$existente->id}: {$hDesdeExist} a {$hHastaExist}) para el {$diaSolapado}.",
                            ],
                        ]);
                    }
                }
            }
        }
    }
}
