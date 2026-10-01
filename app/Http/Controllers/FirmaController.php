<?php

namespace App\Http\Controllers;

use App\Models\PermisoTrazabilidadFirma;
use App\Services\PermisoService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FirmaController extends Controller
{
    public function __construct(
        protected PermisoService $permisoService
    ) {}

    /**
     * Endpoint de Firma Electrónica Simple (FES):
     * Recibe la confirmación desde INSAMU (tras validar el PIN de 6 dígitos del usuario).
     * Registra la firma en permiso_trazabilidad_firmas y avanza el estado de la solicitud.
     */
    public function confirmar(Request $request): JsonResponse
    {
        $validados = $request->validate([
            'token_correo' => 'nullable|string',
            'permiso_id' => 'nullable|integer',
            'insamu_visador_id' => 'nullable|string|max:64',
            'accion' => 'required|in:aprobar,rechazar,APROBAR,RECHAZAR',
            'motivo_rechazo' => 'nullable|string|max:1000|required_if:accion,rechazar,RECHAZAR',
            'nombre_firmante' => 'nullable|string|max:255',
            'rut_firmante' => 'nullable|string|max:20',
            'cargo_firmante' => 'nullable|string|max:255',
            'siguiente_visador_id' => 'nullable|string|max:64',
            'siguiente_nombre_visador' => 'nullable|string|max:255',
            'siguiente_rut_visador' => 'nullable|string|max:20',
            'siguiente_cargo_visador' => 'nullable|string|max:255',
            'decreto_numero' => 'nullable|string|max:64',
        ]);

        if (empty($validados['token_correo']) && (empty($validados['permiso_id']) || empty($validados['insamu_visador_id']))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Debe indicar token_correo o ambos campos permiso_id e insamu_visador_id.',
            ], 422);
        }

        try {
            $solicitud = $this->permisoService->confirmarFirma($validados);

            return response()->json([
                'status' => 'success',
                'message' => 'Firma procesada con éxito y estado de la solicitud actualizado.',
                'data' => [
                    'solicitud_id' => $solicitud->id,
                    'nuevo_estado' => $solicitud->estado,
                    'firmas' => $solicitud->firmas,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Verificar token de correo (Deep Linking):
     * Valida si el token es válido y permite a INSAMU precargar la interfaz de firma por PIN.
     */
    public function verificarToken(string $token): JsonResponse
    {
        $firma = PermisoTrazabilidadFirma::with(['solicitud'])
            ->where('token_correo', $token)
            ->first();

        if (!$firma) {
            return response()->json([
                'status' => 'error',
                'valido' => false,
                'message' => 'El token proporcionado no existe o ha sido invalidado / revocado por subrogancia.',
            ], 404);
        }

        if ($firma->estado_firma !== 'pendiente') {
            return response()->json([
                'status' => 'error',
                'valido' => false,
                'message' => "Esta solicitud ya fue gestionada previamente (estado de firma: {$firma->estado_firma}).",
                'estado_firma' => $firma->estado_firma,
            ], 410);
        }

        return response()->json([
            'status' => 'success',
            'valido' => true,
            'data' => [
                'firma_id' => $firma->id,
                'rol_firma' => $firma->rol_firma,
                'es_subrogante' => $firma->es_subrogante,
                'insamu_visador_id' => $firma->insamu_visador_id,
                'solicitud' => $firma->solicitud,
            ],
        ]);
    }
}
