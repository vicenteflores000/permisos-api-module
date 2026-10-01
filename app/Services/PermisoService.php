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
     * Crear una nueva solicitud de permiso administrativo previa validación de saldo.
     */
    public function crearSolicitud(array $datos): PermisoSolicitud
    {
        $fechaInicio = Carbon::parse($datos['fecha_inicio']);
        $fechaFin = Carbon::parse($datos['fecha_fin']);
        $anio = $fechaInicio->year;
        $tipoSaldo = PermisoSaldo::normalizarTipoPermiso($datos['tipo_permiso'] ?? 'administrativo');

        // Vencimiento estricto: Los días administrativos caducan el 31 de diciembre.
        // Los días no utilizados no se traspasan ni pueden ser solicitados para años ya concluidos.
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

        // Si es compensación de tiempo y no se envió horas_solicitadas pero sí dias_solicitados, se interpreta como horas
        if ($tipoSaldo === PermisoSaldo::TIPO_COMPENSACION_TIEMPO && $horasSolicitadas === null) {
            $horasSolicitadas = $diasSolicitados;
        }

        $cantidadADescontar = ($tipoSaldo === PermisoSaldo::TIPO_COMPENSACION_TIEMPO)
            ? ($horasSolicitadas ?? 0.0)
            : $diasSolicitados;

        $unidad = ($tipoSaldo === PermisoSaldo::TIPO_COMPENSACION_TIEMPO) ? 'horas' : 'días';

        return DB::transaction(function () use ($datos, $anio, $tipoSaldo, $diasSolicitados, $horasSolicitadas, $cantidadADescontar, $unidad) {
            // 1. Validar estrictamente existencia de saldo y cupo disponible si el permiso requiere saldo
            if ($tipoSaldo !== null) {
                $saldo = PermisoSaldo::where('insamu_user_id', $datos['insamu_user_id'])
                    ->where('anio', $anio)
                    ->where('tipo_permiso', $tipoSaldo)
                    ->first();

                // Automatización 1 de enero: Días administrativos siempre disponibles con 6 días por defecto
                if (! $saldo && $tipoSaldo === PermisoSaldo::TIPO_ADMINISTRATIVO) {
                    $saldo = PermisoSaldo::obtenerOCrear($datos['insamu_user_id'], $anio, PermisoSaldo::TIPO_ADMINISTRATIVO, 6.0);
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

            // 2. Crear registro de solicitud
            $solicitud = PermisoSolicitud::create([
                'insamu_user_id' => $datos['insamu_user_id'],
                'nombre_solicitante' => $datos['nombre_solicitante'] ?? null,
                'rut_solicitante' => $datos['rut_solicitante'] ?? null,
                'cargo_solicitante' => $datos['cargo_solicitante'] ?? null,
                'unidad_solicitante' => $datos['unidad_solicitante'] ?? null,
                'tipo_permiso' => $datos['tipo_permiso'] ?? 'Permiso Administrativo',
                'fecha_inicio' => $datos['fecha_inicio'],
                'fecha_fin' => $datos['fecha_fin'],
                'dias_solicitados' => $diasSolicitados,
                'horas_solicitadas' => $horasSolicitadas,
                'estado' => PermisoSolicitud::ESTADO_PENDIENTE_VISATURA,
                'motivo' => $datos['motivo'] ?? null,
                'archivo_adjunto_url' => $datos['archivo_adjunto_url'] ?? null,
            ]);

            // 4. Crear firma inicial para Jefatura
            $firma = PermisoTrazabilidadFirma::create([
                'permiso_id' => $solicitud->id,
                'insamu_visador_id' => $datos['insamu_visador_id'],
                'nombre_visador' => $datos['nombre_visador'] ?? null,
                'rut_visador' => $datos['rut_visador'] ?? null,
                'cargo_visador' => $datos['cargo_visador'] ?? 'Jefatura Directa',
                'rol_firma' => $datos['rol_firma'] ?? 'Jefatura',
                'estado_firma' => 'pendiente',
                'es_subrogante' => false,
                'token_correo' => PermisoTrazabilidadFirma::generarToken(),
            ]);

            // 5. Registrar log de auditoría
            LogSistema::registrar(
                'CREACION_SOLICITUD',
                $solicitud->insamu_user_id,
                'permisos_solicitudes',
                $solicitud->id,
                [
                    'dias_solicitados' => $diasSolicitados,
                    'fecha_inicio' => $solicitud->fecha_inicio,
                    'fecha_fin' => $solicitud->fecha_fin,
                    'visador_inicial_id' => $firma->insamu_visador_id,
                    'token_correo' => $firma->token_correo,
                    'tiene_adjunto' => ! empty($solicitud->archivo_adjunto_url),
                    'archivo_adjunto_url' => $solicitud->archivo_adjunto_url,
                ]
            );

            return $solicitud->load('firmas');
        });
    }

    /**
     * Procesar la confirmación de Firma Electrónica Simple (FES) vía PIN validado en INSAMU.
     */
    public function confirmarFirma(array $datos): PermisoSolicitud
    {
        return DB::transaction(function () use ($datos) {
            // Buscar la trazabilidad pendiente por token_correo O por (permiso_id + insamu_visador_id)
            $query = PermisoTrazabilidadFirma::query()->where('estado_firma', 'pendiente');

            if (! empty($datos['token_correo'])) {
                $query->where('token_correo', $datos['token_correo']);
            } elseif (! empty($datos['permiso_id']) && ! empty($datos['insamu_visador_id'])) {
                $query->where('permiso_id', $datos['permiso_id'])
                    ->where('insamu_visador_id', $datos['insamu_visador_id']);
            } else {
                throw new Exception('Debe proveer token_correo o (permiso_id y insamu_visador_id).');
            }

            /** @var PermisoTrazabilidadFirma|null $firma */
            $firma = $query->first();

            if (! $firma) {
                throw new Exception('No se encontró una firma pendiente válida o el enlace ha caducado/sido revocado.');
            }

            $solicitud = $firma->solicitud;

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
            ];

            if ($accion === 'aprobar') {
                $firma->registrarAprobacion($datosVisador);

                // Avanzar según estado actual de la solicitud
                if ($solicitud->estado === PermisoSolicitud::ESTADO_PENDIENTE_VISATURA) {
                    $solicitud->estado = PermisoSolicitud::ESTADO_PENDIENTE_DIRECCION;
                    $solicitud->save();

                    // Generar trazabilidad para Dirección
                    PermisoTrazabilidadFirma::create([
                        'permiso_id' => $solicitud->id,
                        'insamu_visador_id' => $datos['siguiente_visador_id'] ?? 'direccion_general',
                        'nombre_visador' => $datos['siguiente_nombre_visador'] ?? 'Dirección General',
                        'rut_visador' => $datos['siguiente_rut_visador'] ?? null,
                        'cargo_visador' => $datos['siguiente_cargo_visador'] ?? 'Director(a)',
                        'rol_firma' => 'Dirección',
                        'estado_firma' => 'pendiente',
                        'es_subrogante' => false,
                        'token_correo' => PermisoTrazabilidadFirma::generarToken(),
                    ]);
                } elseif ($solicitud->estado === PermisoSolicitud::ESTADO_PENDIENTE_DIRECCION) {
                    $solicitud->estado = PermisoSolicitud::ESTADO_EN_RRHH;
                    $solicitud->save();
                } elseif ($solicitud->estado === PermisoSolicitud::ESTADO_EN_RRHH) {
                    $solicitud->estado = PermisoSolicitud::ESTADO_DECRETADO;
                    if (! empty($datos['decreto_numero'])) {
                        $solicitud->decreto_numero = $datos['decreto_numero'];
                        $solicitud->fecha_decreto = now();
                    }
                    $solicitud->save();
                }

                LogSistema::registrar(
                    'FIRMA_ELECTRONICA_APROBADA',
                    $firma->insamu_visador_id,
                    'permisos_solicitudes',
                    $solicitud->id,
                    [
                        'rol_firma' => $firma->rol_firma,
                        'es_subrogante' => $firma->es_subrogante,
                        'nuevo_estado' => $solicitud->estado,
                    ]
                );
            } elseif ($accion === 'rechazar') {
                $motivo = $datos['motivo_rechazo'] ?? 'Rechazado sin motivo especificado';
                $firma->registrarRechazo($motivo, $datosVisador);

                $solicitud->estado = PermisoSolicitud::ESTADO_RECHAZADO;
                $solicitud->save();

                // Restituir saldo automáticamente al rechazarse (si el permiso descuenta saldo)
                $tipoSaldo = $solicitud->tipo_saldo;
                if ($tipoSaldo !== null) {
                    $saldo = PermisoSaldo::where('insamu_user_id', $solicitud->insamu_user_id)
                        ->where('anio', $solicitud->anio_imputacion)
                        ->where('tipo_permiso', $tipoSaldo)
                        ->first();
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
     * Subrogancia autogestionada: cambiar visador en estado pendiente.
     * Invalida de inmediato el token_correo original y genera uno nuevo para el subrogante.
     */
    public function subrogarVisador(PermisoSolicitud $solicitud, array $datos): array
    {
        if (! $solicitud->esPendiente()) {
            throw new Exception("Solo se puede asignar subrogante mientras la solicitud esté en estado pendiente ('{$solicitud->estado}').");
        }

        return DB::transaction(function () use ($solicitud, $datos) {
            $firmaPendiente = $solicitud->firmaPendiente;

            if (! $firmaPendiente) {
                throw new Exception('No existe una firma pendiente para subrogar en esta solicitud.');
            }

            $tokenOriginal = $firmaPendiente->token_correo;
            $visadorOriginalId = $firmaPendiente->insamu_visador_id;
            $rol = $firmaPendiente->rol_firma;
            $cargo = $datos['nuevo_cargo_visador'] ?? $firmaPendiente->cargo_visador ?? $rol;

            // Invalida inmediatamente el token_correo del visador original
            $firmaPendiente->token_correo = null;
            $firmaPendiente->estado_firma = 'subrogado';
            $firmaPendiente->motivo_rechazo = 'Subrogado por: '.($datos['nuevo_nombre_visador'] ?? $datos['nuevo_visador_id']);
            $firmaPendiente->save();

            // Genera nuevo registro para el subrogante con token fresco
            $nuevoToken = PermisoTrazabilidadFirma::generarToken();
            $nuevaFirma = PermisoTrazabilidadFirma::create([
                'permiso_id' => $solicitud->id,
                'insamu_visador_id' => $datos['nuevo_visador_id'],
                'nombre_visador' => $datos['nuevo_nombre_visador'] ?? null,
                'rut_visador' => $datos['nuevo_rut_visador'] ?? null,
                'cargo_visador' => $cargo,
                'rol_firma' => $rol,
                'estado_firma' => 'pendiente',
                'es_subrogante' => true,
                'token_correo' => $nuevoToken,
            ]);

            // Auditoría del cambio de subrogancia
            LogSistema::registrar(
                'SUBROGANCIA_VISADOR',
                $solicitud->insamu_user_id,
                'permisos_solicitudes',
                $solicitud->id,
                [
                    'visador_anterior_id' => $visadorOriginalId,
                    'nuevo_visador_subrogante_id' => $nuevaFirma->insamu_visador_id,
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

        return DB::transaction(function () use ($solicitud, $datos) {
            $solicitud->estado_previo_anulacion = $solicitud->estado;
            $solicitud->estado = PermisoSolicitud::ESTADO_PENDIENTE_ANULACION;
            $solicitud->save();

            LogSistema::registrar(
                'SOLICITUD_ANULACION',
                $datos['insamu_user_id'] ?? $solicitud->insamu_user_id,
                'permisos_solicitudes',
                $solicitud->id,
                [
                    'motivo' => $datos['motivo'] ?? 'Solicitud de anulación enviada por el usuario',
                    'estado_anterior' => $solicitud->estado_previo_anulacion,
                ]
            );

            return $solicitud;
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
            $solicitud->save();

            // Restituir el saldo al usuario (si el permiso descuenta saldo)
            $tipoSaldo = $solicitud->tipo_saldo;
            $saldo = null;
            if ($tipoSaldo !== null) {
                $saldo = PermisoSaldo::where('insamu_user_id', $solicitud->insamu_user_id)
                    ->where('anio', $solicitud->anio_imputacion)
                    ->where('tipo_permiso', $tipoSaldo)
                    ->first();

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
        return DB::transaction(function () use ($solicitud, $datos) {
            $solicitud->estado = PermisoSolicitud::ESTADO_DECRETADO;
            $solicitud->decreto_numero = $datos['decreto_numero'] ?? null;
            $solicitud->fecha_decreto = $datos['fecha_decreto'] ?? now()->toDateString();
            $solicitud->save();

            LogSistema::registrar(
                'DECRETADO',
                $datos['insamu_user_id'] ?? null,
                'permisos_solicitudes',
                $solicitud->id,
                [
                    'decreto_numero' => $solicitud->decreto_numero,
                    'fecha_decreto' => $solicitud->fecha_decreto,
                ]
            );

            return $solicitud;
        });
    }
}
