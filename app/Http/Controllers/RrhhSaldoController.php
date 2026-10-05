<?php

namespace App\Http\Controllers;

use App\Models\LogSistema;
use App\Models\PermisoSaldo;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RrhhSaldoController extends Controller
{
    /**
     * Inicializar o actualizar saldos de un funcionario de manera individual.
     */
    public function inicializarIndividual(Request $request): JsonResponse
    {
        $validados = $request->validate([
            'insamu_user_id' => 'required|string|max:64',
            'anio' => 'required|integer|min:2000|max:2100',
            'dias_feriado_legal' => 'required|numeric|min:0',
            'horas_compensacion' => 'nullable|numeric|min:0',
            'insamu_rrhh_user_id' => 'nullable|string|max:64',
        ]);

        $userId = $validados['insamu_user_id'];
        $anio = (int) $validados['anio'];
        $rrhhId = $validados['insamu_rrhh_user_id'] ?? 'RRHH_OFFICER';

        $saldosActualizados = DB::transaction(function () use ($validados, $userId, $anio, $rrhhId) {
            $resultados = [];

            // 1. Feriado Legal (Vacaciones - con variabilidad ej: 15, 20 o 25 días)
            $saldoFeriado = PermisoSaldo::updateOrCreate(
                [
                    'insamu_user_id' => $userId,
                    'anio' => $anio,
                    'tipo_permiso' => PermisoSaldo::TIPO_FERIADO_LEGAL,
                ],
                [
                    'unidad' => PermisoSaldo::UNIDAD_DIAS,
                    'dias_totales' => (float) $validados['dias_feriado_legal'],
                ]
            );
            $resultados[] = $saldoFeriado;

            // 2. Compensación de Tiempo (Opcional)
            if (isset($validados['horas_compensacion'])) {
                $saldoComp = PermisoSaldo::updateOrCreate(
                    [
                        'insamu_user_id' => $userId,
                        'anio' => $anio,
                        'tipo_permiso' => PermisoSaldo::TIPO_COMPENSACION_TIEMPO,
                    ],
                    [
                        'unidad' => PermisoSaldo::UNIDAD_HORAS,
                        'dias_totales' => (float) $validados['horas_compensacion'],
                    ]
                );
                $resultados[] = $saldoComp;
            }

            LogSistema::registrar(
                'INICIALIZACION_SALDOS_INDIVIDUAL',
                $rrhhId,
                'permisos_saldos',
                $resultados[0]->id ?? null,
                [
                    'funcionario_id' => $userId,
                    'anio' => $anio,
                    'dias_feriado_legal' => $validados['dias_feriado_legal'],
                    'horas_compensacion' => $validados['horas_compensacion'] ?? null,
                    'nota' => 'Días administrativos asignados automáticamente por Cron Job anual el 1 de enero',
                ]
            );

            return $resultados;
        });

        return response()->json([
            'status' => 'success',
            'message' => "Feriado Legal ({$validados['dias_feriado_legal']} días) del funcionario {$userId} para el año {$anio} inicializado correctamente. Los días administrativos se gestionan automáticamente vía Cron Job anual.",
            'data' => $saldosActualizados,
        ]);
    }

    /**
     * Carga masiva de saldos mediante archivo CSV o array JSON.
     * Restringido exclusivamente para Feriados Legales y Compensación de Tiempo.
     */
    public function cargaMasiva(Request $request): JsonResponse
    {
        $validados = $request->validate([
            'archivo_csv' => 'nullable|file|mimes:csv,txt|max:5120',
            'saldos' => 'nullable|array|min:1',
            'saldos.*.insamu_user_id' => 'required_with:saldos|string|max:64',
            'saldos.*.anio' => 'required_with:saldos|integer|min:2000|max:2100',
            'saldos.*.dias_feriado_legal' => 'required_with:saldos|numeric|min:0',
            'saldos.*.horas_compensacion' => 'nullable|numeric|min:0',
            'insamu_rrhh_user_id' => 'nullable|string|max:64',
            'anio_defecto' => 'nullable|integer|min:2000|max:2100',
        ]);

        $filas = [];

        // Caso A: Procesar archivo CSV
        if ($request->hasFile('archivo_csv')) {
            $archivo = $request->file('archivo_csv');
            $filas = $this->parsearCsv($archivo->getRealPath(), (int) ($request->input('anio_defecto', now()->year)));
        } elseif (! empty($validados['saldos'])) {
            // Caso B: Procesar array de objetos JSON
            $filas = $validados['saldos'];
        } else {
            throw ValidationException::withMessages([
                'archivo_csv' => ['Debe adjuntar un archivo CSV o enviar un array en el campo "saldos".'],
            ]);
        }

        if (empty($filas)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontraron registros válidos para procesar.',
            ], 422);
        }

        $rrhhId = $validados['insamu_rrhh_user_id'] ?? 'RRHH_OFFICER';
        $procesados = 0;
        $errores = [];

        DB::beginTransaction();
        try {
            foreach ($filas as $indice => $fila) {
                try {
                    $userId = trim((string) ($fila['insamu_user_id'] ?? $fila['rut'] ?? $fila['id_funcionario'] ?? ''));
                    $anio = (int) ($fila['anio'] ?? $request->input('anio_defecto', now()->year));

                    if (empty($userId)) {
                        $errores[] = "Fila #{$indice}: Falta identificador del funcionario.";

                        continue;
                    }

                    // Feriado Legal (Vacaciones: obligatorio en la planilla masiva)
                    $feriado = isset($fila['dias_feriado_legal']) ? (float) $fila['dias_feriado_legal'] : 15.0;
                    PermisoSaldo::updateOrCreate(
                        [
                            'insamu_user_id' => $userId,
                            'anio' => $anio,
                            'tipo_permiso' => PermisoSaldo::TIPO_FERIADO_LEGAL,
                        ],
                        [
                            'unidad' => PermisoSaldo::UNIDAD_DIAS,
                            'dias_totales' => $feriado,
                        ]
                    );

                    // Compensación opcional
                    if (isset($fila['horas_compensacion']) && $fila['horas_compensacion'] !== '') {
                        PermisoSaldo::updateOrCreate(
                            [
                                'insamu_user_id' => $userId,
                                'anio' => $anio,
                                'tipo_permiso' => PermisoSaldo::TIPO_COMPENSACION_TIEMPO,
                            ],
                            [
                                'unidad' => PermisoSaldo::UNIDAD_HORAS,
                                'dias_totales' => (float) $fila['horas_compensacion'],
                            ]
                        );
                    }

                    $procesados++;
                } catch (Exception $e) {
                    $errores[] = "Fila #{$indice} ({$userId}): {$e->getMessage()}";
                }
            }

            LogSistema::registrar(
                'CARGA_MASIVA_SALDOS',
                $rrhhId,
                'permisos_saldos',
                null,
                [
                    'total_filas' => count($filas),
                    'procesados_exitosamente' => $procesados,
                    'errores' => $errores,
                    'nota' => 'Carga masiva exclusiva de Feriado Legal y Compensación',
                ]
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Carga masiva completada: {$procesados} funcionarios configurados con Feriado Legal.",
                'resumen' => [
                    'total_procesados' => $procesados,
                    'total_filas' => count($filas),
                    'errores' => $errores,
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Ocurrió un error al procesar la carga masiva: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Inyección mensual de saldo de compensación de tiempo (transaccional).
     * Suma las horas trabajadas al saldo existente en lugar de sobrescribirlo.
     */
    public function inyectarCompensacion(Request $request): JsonResponse
    {
        $validados = $request->validate([
            'insamu_user_id' => 'required|string|max:64',
            'anio' => 'required|integer|min:2000|max:2100',
            'horas' => 'required|numeric|min:0.1',
            'motivo' => 'nullable|string|max:255',
            'insamu_rrhh_user_id' => 'required|string|max:64',
        ]);

        $userId = $validados['insamu_user_id'];
        $anio = (int) $validados['anio'];
        $horas = (float) $validados['horas'];
        $rrhhId = $validados['insamu_rrhh_user_id'];
        $motivo = $validados['motivo'] ?? 'Abono mensual de horas extraordinarias';

        $saldo = DB::transaction(function () use ($userId, $anio, $horas, $rrhhId, $motivo) {
            $saldoActual = PermisoSaldo::firstOrCreate(
                [
                    'insamu_user_id' => $userId,
                    'anio' => $anio,
                    'tipo_permiso' => PermisoSaldo::TIPO_COMPENSACION_TIEMPO,
                ],
                [
                    'unidad' => PermisoSaldo::UNIDAD_HORAS,
                    'dias_totales' => 0.0,
                    'dias_usados' => 0.0,
                ]
            );

            // Operación transaccional: Sumar al acumulado existente
            $saldoActual->acumularHoras($horas);

            LogSistema::registrar(
                'INYECCION_COMPENSACION_RRHH',
                $rrhhId,
                'permisos_saldos',
                $saldoActual->id,
                [
                    'funcionario_id' => $userId,
                    'anio' => $anio,
                    'horas_inyectadas' => $horas,
                    'nuevo_total_horas' => $saldoActual->dias_totales,
                    'horas_disponibles' => $saldoActual->cantidad_disponible,
                    'motivo' => $motivo,
                    'operado_por_rrhh' => $rrhhId,
                ]
            );

            return $saldoActual;
        });

        return response()->json([
            'status' => 'success',
            'message' => "Se han abonado {$horas} horas de compensación al funcionario {$userId}. Saldo disponible actual: {$saldo->cantidad_disponible} horas.",
            'data' => $saldo,
        ]);
    }

    /**
     * Verificar estado de saldo y bloqueo preventivo para el funcionario.
     */
    public function verificarSaldo(Request $request, string $userId): JsonResponse
    {
        $anio = (int) $request->query('anio', now()->year);
        $tipoInput = $request->query('tipo_permiso', 'administrativo');
        $tipo = PermisoSaldo::normalizarTipoPermiso($tipoInput);

        // Si es un permiso no contabilizable (ej. Duelo, Maternidad)
        if ($tipo === null) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'configurado' => true,
                    'requiere_saldo' => false,
                    'tipo_permiso' => $tipoInput,
                    'anio' => $anio,
                    'bloqueo_preventivo' => false,
                    'mensaje' => null,
                ],
            ]);
        }

        $saldo = PermisoSaldo::where('insamu_user_id', $userId)
            ->where('anio', $anio)
            ->where('tipo_permiso', $tipo)
            ->first();

        // Automatización 1 de enero: Los días administrativos siempre se habilitan automáticamente a 6 días
        if (! $saldo && $tipo === PermisoSaldo::TIPO_ADMINISTRATIVO) {
            $saldo = PermisoSaldo::obtenerOCrear($userId, $anio, PermisoSaldo::TIPO_ADMINISTRATIVO, 6.0);
        }

        if (! $saldo) {
            $aplicaBloqueo = ($tipo === PermisoSaldo::TIPO_FERIADO_LEGAL);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'configurado' => false,
                    'requiere_saldo' => true,
                    'tipo_permiso' => $tipo,
                    'anio' => $anio,
                    'cantidad_disponible' => 0.0,
                    'unidad' => $tipo === PermisoSaldo::TIPO_COMPENSACION_TIEMPO ? PermisoSaldo::UNIDAD_HORAS : PermisoSaldo::UNIDAD_DIAS,
                    'bloqueo_preventivo' => $aplicaBloqueo,
                    'mensaje' => $aplicaBloqueo ? 'Saldo anual no configurado. Por favor, regularice su situación con Recursos Humanos antes de solicitar este permiso.' : null,
                ],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'configurado' => true,
                'requiere_saldo' => true,
                'tipo_permiso' => $saldo->tipo_permiso,
                'anio' => $saldo->anio,
                'unidad' => $saldo->unidad,
                'cantidad_total' => $saldo->dias_totales,
                'cantidad_usada' => $saldo->dias_usados,
                'cantidad_disponible' => $saldo->cantidad_disponible,
                'bloqueo_preventivo' => false,
                'mensaje' => null,
            ],
        ]);
    }

    /**
     * Parsea un archivo CSV delimitado por comas o punto y coma.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parsearCsv(string $rutaArchivo, int $anioDefecto): array
    {
        $filas = [];
        $handle = fopen($rutaArchivo, 'r');
        if (! $handle) {
            return [];
        }

        $encabezados = null;
        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            // Si vino separado por ';'
            if (count($data) === 1 && str_contains($data[0], ';')) {
                $data = str_getcsv($data[0], ';');
            }

            // Sanitizar BOM UTF-8 en el primer encabezado si existe
            if ($encabezados === null) {
                $data[0] = preg_replace('/[\x{EF}\x{BB}\x{BF}]/u', '', $data[0]);
                $encabezados = array_map(fn ($col) => mb_strtolower(trim($col)), $data);

                continue;
            }

            if (empty(array_filter($data))) {
                continue;
            }

            $fila = [];
            foreach ($encabezados as $i => $clave) {
                $fila[$clave] = isset($data[$i]) ? trim($data[$i]) : null;
            }

            if (! empty($fila['insamu_user_id']) || ! empty($fila['rut']) || ! empty($fila['id_funcionario'])) {
                if (empty($fila['anio'])) {
                    $fila['anio'] = $anioDefecto;
                }
                $filas[] = $fila;
            }
        }

        fclose($handle);

        return $filas;
    }
}
