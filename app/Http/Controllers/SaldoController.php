<?php

namespace App\Http\Controllers;

use App\Models\LogSistema;
use App\Models\PermisoSaldo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaldoController extends Controller
{
    /**
     * Consultar saldos de permisos de un usuario de INSAMU.
     */
    public function show(Request $request, string $userId): JsonResponse
    {
        $query = PermisoSaldo::where('insamu_user_id', $userId);

        if ($request->filled('anio')) {
            $query->where('anio', (int) $request->query('anio'));
        }

        $saldos = $query->orderBy('anio', 'desc')->get();

        if ($saldos->isEmpty()) {
            // Inicializar automáticamente el año actual si no existe aún
            $anioActual = now()->year;
            $saldoActual = PermisoSaldo::obtenerOCrear($userId, $anioActual);
            $saldos = collect([$saldoActual]);
        }

        return response()->json([
            'status' => 'success',
            'data' => $saldos,
        ]);
    }

    /**
     * Configurar o ajustar el saldo de un usuario para un año específico.
     */
    public function upsert(Request $request): JsonResponse
    {
        $validados = $request->validate([
            'insamu_user_id' => 'required|string|max:64',
            'anio' => 'required|integer|min:2000|max:2100',
            'dias_totales' => 'required|numeric|min:0',
            'dias_usados' => 'nullable|numeric|min:0',
        ]);

        $saldo = PermisoSaldo::updateOrCreate(
            [
                'insamu_user_id' => $validados['insamu_user_id'],
                'anio' => $validados['anio'],
            ],
            [
                'dias_totales' => $validados['dias_totales'],
                'dias_usados' => $validados['dias_usados'] ?? 0.0,
            ]
        );

        LogSistema::registrar(
            'AJUSTE_SALDO',
            $validados['insamu_user_id'],
            'permisos_saldos',
            $saldo->id,
            [
                'anio' => $saldo->anio,
                'dias_totales' => $saldo->dias_totales,
                'dias_usados' => $saldo->dias_usados,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Saldo configurado correctamente.',
            'data' => $saldo,
        ]);
    }
}
