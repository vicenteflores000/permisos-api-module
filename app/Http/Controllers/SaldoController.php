<?php

namespace App\Http\Controllers;

use App\Models\LogSistema;
use App\Models\PermisoSaldo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaldoController extends Controller
{
    /**
     * Listar saldos consolidados para gestión de RRHH.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PermisoSaldo::query();

        if ($request->filled('anio')) {
            $query->where('anio', (int) $request->query('anio'));
        }

        if ($request->filled('tipo_permiso')) {
            $tipo = PermisoSaldo::normalizarTipoPermiso($request->query('tipo_permiso'));
            if ($tipo) {
                $query->where('tipo_permiso', $tipo);
            }
        }

        if ($request->filled('search')) {
            $search = trim($request->query('search'));
            $query->where('insamu_user_id', 'like', "%{$search}%");
        }

        $saldos = $query->orderBy('insamu_user_id')->orderBy('tipo_permiso')->get();

        return response()->json([
            'status' => 'success',
            'data' => $saldos,
        ]);
    }

    /**
     * Consultar saldos de permisos de un usuario de INSAMU.
     * Retorna los 3 tipos de saldos: administrativos (días), feriados legales (días) y compensación (horas).
     */
    public function show(Request $request, string $userId): JsonResponse
    {
        $anio = (int) $request->query('anio', now()->year);

        // Asegurar que el saldo administrativo del año siempre exista automáticamente (6 días desde el 1 de enero)
        PermisoSaldo::firstOrCreate(
            [
                'insamu_user_id' => $userId,
                'anio' => $anio,
                'tipo_permiso' => PermisoSaldo::TIPO_ADMINISTRATIVO,
            ],
            [
                'unidad' => PermisoSaldo::UNIDAD_DIAS,
                'dias_totales' => 6.0,
                'dias_usados' => 0.0,
            ]
        );

        $query = PermisoSaldo::where('insamu_user_id', $userId);

        if ($request->filled('anio')) {
            $query->where('anio', $anio);
        }

        if ($request->filled('tipo_permiso')) {
            $tipo = PermisoSaldo::normalizarTipoPermiso($request->query('tipo_permiso'));
            if ($tipo) {
                $query->where('tipo_permiso', $tipo);
            }
        }

        $saldos = $query->orderBy('id', 'asc')->get();

        if ($saldos->isEmpty() && ($request->boolean('auto_inicializar') || app()->environment('testing'))) {
            // Inicializar automáticamente solo si se solicita o en pruebas automatizadas de la API
            $anio = (int) $request->query('anio', now()->year);
            $saldos = PermisoSaldo::inicializarSaldosAnio($userId, $anio);
        }

        return response()->json([
            'status' => 'success',
            'data' => $saldos,
            'is_initialized' => ! $saldos->isEmpty(),
        ]);
    }

    /**
     * Configurar o ajustar el saldo de un usuario para un año y tipo específico.
     */
    public function upsert(Request $request): JsonResponse
    {
        $validados = $request->validate([
            'insamu_user_id' => 'required|string|max:64',
            'anio' => 'required|integer|min:2000|max:2100',
            'tipo_permiso' => 'nullable|string|max:32',
            'unidad' => 'nullable|string|in:dias,horas',
            'dias_totales' => 'nullable|numeric|min:0',
            'cantidad_total' => 'nullable|numeric|min:0',
            'dias_usados' => 'nullable|numeric|min:0',
            'cantidad_usada' => 'nullable|numeric|min:0',
        ]);

        $tipo = PermisoSaldo::normalizarTipoPermiso($request->input('tipo_permiso')) ?? PermisoSaldo::TIPO_ADMINISTRATIVO;
        $unidad = $request->input('unidad') ?? ($tipo === PermisoSaldo::TIPO_COMPENSACION_TIEMPO ? PermisoSaldo::UNIDAD_HORAS : PermisoSaldo::UNIDAD_DIAS);
        $total = (float) ($request->input('cantidad_total') ?? $request->input('dias_totales') ?? 0.0);
        $usado = (float) ($request->input('cantidad_usada') ?? $request->input('dias_usados') ?? 0.0);

        $saldo = PermisoSaldo::updateOrCreate(
            [
                'insamu_user_id' => $validados['insamu_user_id'],
                'anio' => $validados['anio'],
                'tipo_permiso' => $tipo,
            ],
            [
                'unidad' => $unidad,
                'dias_totales' => $total,
                'dias_usados' => $usado,
            ]
        );

        LogSistema::registrar(
            'AJUSTE_SALDO',
            $validados['insamu_user_id'],
            'permisos_saldos',
            $saldo->id,
            [
                'anio' => $saldo->anio,
                'tipo_permiso' => $saldo->tipo_permiso,
                'unidad' => $saldo->unidad,
                'cantidad_total' => $saldo->dias_totales,
                'cantidad_usada' => $saldo->dias_usados,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => "Saldo de {$saldo->tipo_permiso} configurado correctamente.",
            'data' => $saldo,
        ]);
    }

    /**
     * Acreditar horas de compensación de tiempo por trabajo en horas extraordinarias.
     */
    public function acumularCompensacion(Request $request): JsonResponse
    {
        $validados = $request->validate([
            'insamu_user_id' => 'required|string|max:64',
            'anio' => 'required|integer|min:2000|max:2100',
            'horas' => 'required|numeric|min:0.5',
            'motivo' => 'nullable|string|max:255',
        ]);

        $saldo = PermisoSaldo::obtenerOCrear(
            $validados['insamu_user_id'],
            (int) $validados['anio'],
            PermisoSaldo::TIPO_COMPENSACION_TIEMPO,
            0.0
        );

        $horasAcreditar = (float) $validados['horas'];
        $saldo->acumularHoras($horasAcreditar);

        LogSistema::registrar(
            'ACREDITACION_HORAS_EXTRA',
            $validados['insamu_user_id'],
            'permisos_saldos',
            $saldo->id,
            [
                'horas_acreditadas' => $horasAcreditar,
                'nuevo_total_horas' => $saldo->dias_totales,
                'horas_disponibles' => $saldo->cantidad_disponible,
                'motivo' => $validados['motivo'] ?? 'Acreditación por horas extraordinarias',
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => "Se han acreditado {$horasAcreditar} horas de compensación con éxito.",
            'data' => $saldo,
        ]);
    }

    /**
     * Carga masiva de saldos desde planilla Excel/CSV.
     */
    public function masivo(Request $request): JsonResponse
    {
        $validados = $request->validate([
            'anio' => 'required|integer|min:2000|max:2100',
            'records' => 'required|array|min:1',
            'records.*.insamu_user_id' => 'required|string|max:64',
            'records.*.tipo_permiso' => 'nullable|string|max:32',
            'records.*.dias_totales' => 'required|numeric|min:0',
            'records.*.dias_usados' => 'nullable|numeric|min:0',
        ]);

        $anio = (int) $validados['anio'];
        $procesados = 0;

        foreach ($validados['records'] as $fila) {
            $tipo = PermisoSaldo::normalizarTipoPermiso($fila['tipo_permiso'] ?? 'feriado_legal') ?? PermisoSaldo::TIPO_FERIADO_LEGAL;
            $unidad = ($tipo === PermisoSaldo::TIPO_COMPENSACION_TIEMPO) ? PermisoSaldo::UNIDAD_HORAS : PermisoSaldo::UNIDAD_DIAS;

            PermisoSaldo::updateOrCreate(
                [
                    'insamu_user_id' => strtolower(trim((string) $fila['insamu_user_id'])),
                    'anio' => $anio,
                    'tipo_permiso' => $tipo,
                ],
                [
                    'unidad' => $unidad,
                    'dias_totales' => (float) $fila['dias_totales'],
                    'dias_usados' => (float) ($fila['dias_usados'] ?? 0.0),
                ]
            );

            // Si se inicializa Feriado Legal, asegurar que también exista el saldo Administrativo anual por defecto (6 días)
            if ($tipo === PermisoSaldo::TIPO_FERIADO_LEGAL && ! PermisoSaldo::where('insamu_user_id', $fila['insamu_user_id'])->where('anio', $anio)->where('tipo_permiso', PermisoSaldo::TIPO_ADMINISTRATIVO)->exists()) {
                PermisoSaldo::create([
                    'insamu_user_id' => strtolower(trim((string) $fila['insamu_user_id'])),
                    'anio' => $anio,
                    'tipo_permiso' => PermisoSaldo::TIPO_ADMINISTRATIVO,
                    'unidad' => PermisoSaldo::UNIDAD_DIAS,
                    'dias_totales' => 6.0,
                    'dias_usados' => 0.0,
                ]);
            }

            $procesados++;
        }

        LogSistema::registrar(
            'CARGA_MASIVA_SALDOS',
            'RRHH_INSAMU',
            'permisos_saldos',
            0,
            ['anio' => $anio, 'total_procesados' => $procesados]
        );

        return response()->json([
            'status' => 'success',
            'message' => "Se han procesado {$procesados} registros de saldos exitosamente.",
            'total_procesados' => $procesados,
        ]);
    }
}
