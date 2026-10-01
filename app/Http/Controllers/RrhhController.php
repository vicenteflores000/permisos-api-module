<?php

namespace App\Http\Controllers;

use App\Models\PermisoSolicitud;
use App\Services\PermisoService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RrhhController extends Controller
{
    public function __construct(
        protected PermisoService $permisoService
    ) {}

    /**
     * RRHH: Aprobar anulación de un permiso.
     * Restituye los días en permisos_saldos y cambia el estado a 'anulado'.
     */
    public function aprobarAnulacion(Request $request, int $id): JsonResponse
    {
        $solicitud = PermisoSolicitud::find($id);

        if (!$solicitud) {
            return response()->json([
                'status' => 'error',
                'message' => "Solicitud #{$id} no encontrada.",
            ], 404);
        }

        $validados = $request->validate([
            'insamu_rrhh_user_id' => 'nullable|string|max:64',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        try {
            $solicitudActualizada = $this->permisoService->aprobarAnulacion($solicitud, $validados);

            return response()->json([
                'status' => 'success',
                'message' => 'Anulación aprobada exitosamente por RRHH. Saldo de días restituido al funcionario.',
                'data' => $solicitudActualizada,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * RRHH: Rechazar anulación de un permiso.
     * Útil si el permiso ya fue decretado en una plataforma externa u otra causa.
     * Restaura el estado previo.
     */
    public function rechazarAnulacion(Request $request, int $id): JsonResponse
    {
        $solicitud = PermisoSolicitud::find($id);

        if (!$solicitud) {
            return response()->json([
                'status' => 'error',
                'message' => "Solicitud #{$id} no encontrada.",
            ], 404);
        }

        $validados = $request->validate([
            'insamu_rrhh_user_id' => 'nullable|string|max:64',
            'motivo' => 'nullable|string|max:1000',
        ]);

        try {
            $solicitudActualizada = $this->permisoService->rechazarAnulacion($solicitud, $validados);

            return response()->json([
                'status' => 'success',
                'message' => 'Anulación rechazada por RRHH. El permiso ha retornado a su estado previo.',
                'data' => $solicitudActualizada,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * RRHH: Decretar formalmente el permiso administrativo.
     */
    public function decretar(Request $request, int $id): JsonResponse
    {
        $solicitud = PermisoSolicitud::find($id);

        if (!$solicitud) {
            return response()->json([
                'status' => 'error',
                'message' => "Solicitud #{$id} no encontrada.",
            ], 404);
        }

        $validados = $request->validate([
            'decreto_numero' => 'required|string|max:64',
            'fecha_decreto' => 'nullable|date',
            'insamu_user_id' => 'nullable|string|max:64',
        ]);

        try {
            $solicitudActualizada = $this->permisoService->decretar($solicitud, $validados);

            return response()->json([
                'status' => 'success',
                'message' => "Permiso #{$id} decretado formalmente con N° {$solicitudActualizada->decreto_numero}.",
                'data' => $solicitudActualizada,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
