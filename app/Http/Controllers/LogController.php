<?php

namespace App\Http\Controllers;

use App\Models\LogSistema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /**
     * Consultar logs de auditoría del sistema.
     */
    public function index(Request $request): JsonResponse
    {
        $query = LogSistema::query();

        if ($request->filled('insamu_user_id')) {
            $query->where('insamu_user_id', $request->query('insamu_user_id'));
        }

        if ($request->filled('accion')) {
            $query->where('accion', $request->query('accion'));
        }

        if ($request->filled('entidad_id')) {
            $query->where('entidad_id', $request->query('entidad_id'));
        }

        if ($request->filled('entidad_tipo')) {
            $query->where('entidad_tipo', $request->query('entidad_tipo'));
        }

        $perPage = (int) $request->query('per_page', 25);
        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $logs,
        ]);
    }
}
