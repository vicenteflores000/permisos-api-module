<?php

namespace App\Http\Controllers;

use App\Models\LogSistema;
use App\Models\PermisoSolicitud;
use App\Services\PdfService;
use App\Services\PermisoService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class PermisoController extends Controller
{
    public function __construct(
        protected PermisoService $permisoService,
        protected PdfService $pdfService
    ) {}

    /**
     * Listar permisos con filtros opcionales (insamu_user_id, estado, anio, fecha).
     */
    public function index(Request $request): JsonResponse
    {
        $query = PermisoSolicitud::with(['firmas']);

        if ($request->filled('insamu_user_id')) {
            $query->where('insamu_user_id', $request->query('insamu_user_id'));
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->query('estado'));
        }

        if ($request->filled('anio')) {
            $anio = (int) $request->query('anio');
            $query->whereYear('fecha_inicio', $anio);
        }

        if ($request->filled('fecha_inicio')) {
            $query->whereDate('fecha_inicio', '>=', $request->query('fecha_inicio'));
        }

        if ($request->filled('fecha_fin')) {
            $query->whereDate('fecha_fin', '<=', $request->query('fecha_fin'));
        }

        $perPage = (int) $request->query('per_page', 20);
        $permisos = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $permisos,
        ]);
    }

    /**
     * Crear una nueva solicitud de permiso administrativo.
     */
    public function store(Request $request): JsonResponse
    {
        $validados = $request->validate([
            'insamu_user_id' => 'required|string|max:64',
            'nombre_solicitante' => 'nullable|string|max:255',
            'rut_solicitante' => 'nullable|string|max:20',
            'cargo_solicitante' => 'nullable|string|max:255',
            'unidad_solicitante' => 'nullable|string|max:255',
            'tipo_permiso' => 'required|string|max:100',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            'dias_solicitados' => 'required|numeric|min:0.5',
            'insamu_visador_id' => 'required|string|max:64',
            'nombre_visador' => 'nullable|string|max:255',
            'rut_visador' => 'nullable|string|max:20',
            'cargo_visador' => 'nullable|string|max:255',
            'rol_firma' => 'nullable|string|max:64',
            'motivo' => 'nullable|string|max:1000',
        ]);

        try {
            $solicitud = $this->permisoService->crearSolicitud($validados);

            return response()->json([
                'status' => 'success',
                'message' => 'Solicitud de permiso creada con éxito.',
                'data' => $solicitud,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación de saldo o datos.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Visualizar detalle de una solicitud de permiso y sus firmas.
     */
    public function show(int $id): JsonResponse
    {
        $solicitud = PermisoSolicitud::with(['firmas'])->find($id);

        if (!$solicitud) {
            return response()->json([
                'status' => 'error',
                'message' => "Solicitud #{$id} no encontrada.",
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $solicitud,
        ]);
    }

    /**
     * Subrogancia autogestionada: cambiar de visador en estado 'pendiente'.
     * Invalida de inmediato el token_correo del visador original y genera uno nuevo para el subrogante.
     */
    public function subrogarVisador(Request $request, int $id): JsonResponse
    {
        $solicitud = PermisoSolicitud::with(['firmas'])->find($id);

        if (!$solicitud) {
            return response()->json([
                'status' => 'error',
                'message' => "Solicitud #{$id} no encontrada.",
            ], 404);
        }

        $validados = $request->validate([
            'nuevo_visador_id' => 'required|string|max:64',
            'nuevo_nombre_visador' => 'nullable|string|max:255',
            'nuevo_rut_visador' => 'nullable|string|max:20',
            'nuevo_cargo_visador' => 'nullable|string|max:255',
        ]);

        try {
            $resultado = $this->permisoService->subrogarVisador($solicitud, $validados);

            return response()->json([
                'status' => 'success',
                'message' => 'Visador subrogado correctamente. Token anterior invalidado y nuevo token generado.',
                'data' => $resultado,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Edición general de solicitud en estado pendiente.
     * Si incluye nuevo_visador_id, ejecuta la lógica de subrogancia.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $solicitud = PermisoSolicitud::find($id);

        if (!$solicitud) {
            return response()->json([
                'status' => 'error',
                'message' => "Solicitud #{$id} no encontrada.",
            ], 404);
        }

        if (!$solicitud->esPendiente()) {
            return response()->json([
                'status' => 'error',
                'message' => "Solo se pueden editar solicitudes en estado pendiente de visación.",
            ], 422);
        }

        if ($request->filled('nuevo_visador_id')) {
            return $this->subrogarVisador($request, $id);
        }

        if ($request->filled('motivo')) {
            $solicitud->motivo = $request->input('motivo');
            $solicitud->save();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Solicitud actualizada.',
            'data' => $solicitud->load('firmas'),
        ]);
    }

    /**
     * Solicitar anulación de la solicitud por parte del usuario.
     * Cambia el estado a pendiente_anulacion y bloquea la descarga del PDF.
     */
    public function solicitarAnulacion(Request $request, int $id): JsonResponse
    {
        $solicitud = PermisoSolicitud::find($id);

        if (!$solicitud) {
            return response()->json([
                'status' => 'error',
                'message' => "Solicitud #{$id} no encontrada.",
            ], 404);
        }

        $validados = $request->validate([
            'insamu_user_id' => 'nullable|string|max:64',
            'motivo' => 'nullable|string|max:1000',
        ]);

        try {
            $solicitudActualizada = $this->permisoService->solicitarAnulacion($solicitud, $validados);

            return response()->json([
                'status' => 'success',
                'message' => 'Solicitud de anulación registrada. El permiso ha pasado a estado pendiente_anulacion.',
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
     * Descarga del PDF inalterable del permiso.
     * BLOQUEADO si la solicitud está en estado 'pendiente_anulacion'.
     */
    public function descargarPdf(int $id)
    {
        $solicitud = PermisoSolicitud::with(['firmas'])->find($id);

        if (!$solicitud) {
            return response()->json([
                'status' => 'error',
                'message' => "Solicitud #{$id} no encontrada.",
            ], 404);
        }

        // Regla: Bloquea la descarga del PDF si está en pendiente_anulacion
        if (!$solicitud->permiteDescargaPdf()) {
            return response()->json([
                'status' => 'error',
                'error' => 'Descarga de PDF bloqueada: la solicitud se encuentra en estado pendiente_anulacion.',
                'message' => 'No es posible descargar el certificado mientras la solicitud esté en proceso de anulación.',
            ], 403);
        }

        $pdf = $this->pdfService->generarPdf($solicitud);

        LogSistema::registrar(
            'DESCARGA_PDF',
            request()->header('X-USER-ID') ?? $solicitud->insamu_user_id,
            'permisos_solicitudes',
            $solicitud->id,
            ['estado' => $solicitud->estado]
        );

        $nombreArchivo = sprintf('permiso_insamu_%06d.pdf', $solicitud->id);

        return $pdf->stream($nombreArchivo);
    }
}
