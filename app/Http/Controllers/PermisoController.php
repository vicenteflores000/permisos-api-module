<?php

namespace App\Http\Controllers;

use App\Models\LogSistema;
use App\Models\PermisoSolicitud;
use App\Services\PdfService;
use App\Services\PermisoService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

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
            $userId = (string) $request->query('insamu_user_id');
            $userLower = strtolower(trim($userId));
            $cleanRut = preg_replace('/[^0-9kK]/', '', $userId);

            $query->where(function ($q) use ($userId, $userLower, $cleanRut) {
                $q->where('insamu_user_id', $userId)
                    ->orWhereRaw('LOWER(insamu_user_id) = ?', [$userLower])
                    ->orWhere('rut_solicitante', $userId);

                if (! empty($cleanRut)) {
                    $q->orWhere('insamu_user_id', $cleanRut)
                        ->orWhereRaw("REPLACE(REPLACE(rut_solicitante, '.', ''), '-', '') = ?", [$cleanRut]);
                }
            });
        }

        if ($request->filled('rut_solicitante')) {
            $rut = (string) $request->query('rut_solicitante');
            $cleanRut = preg_replace('/[^0-9kK]/', '', $rut);

            $query->where(function ($q) use ($rut, $cleanRut) {
                $q->where('rut_solicitante', $rut)
                    ->orWhere('insamu_user_id', $rut);

                if (! empty($cleanRut)) {
                    $q->orWhereRaw("REPLACE(REPLACE(rut_solicitante, '.', ''), '-', '') = ?", [$cleanRut]);
                }
            });
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
        if ($request->boolean('all') || $perPage > 20) {
            $perPage = min(max($perPage, 100), 500);
        }
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
        // Cálculo Simple de Fechas: Se descarta la integración de calendarios de feriados nacionales.
        // La API confía plenamente en la cantidad enviada por el frontend (dias_solicitados u horas_solicitadas)
        // y los descuenta linealmente del saldo del funcionario.
        $validados = $request->validate([
            'insamu_user_id' => 'required|string|max:64',
            'nombre_solicitante' => 'nullable|string|max:255',
            'rut_solicitante' => 'nullable|string|max:20',
            'cargo_solicitante' => 'nullable|string|max:255',
            'unidad_solicitante' => 'nullable|string|max:255',
            'tipo_permiso' => 'required|string|max:100',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            'dias_solicitados' => 'nullable|numeric|min:0',
            'horas_solicitadas' => 'nullable|numeric|min:0.5',
            'insamu_visador_id' => 'required|string|max:64',
            'nombre_visador' => 'nullable|string|max:255',
            'rut_visador' => 'nullable|string|max:20',
            'cargo_visador' => 'nullable|string|max:255',
            'rol_firma' => 'nullable|string|max:64',
            'motivo' => 'nullable|string|max:1000',
            'archivo' => [
                Rule::requiredIf(function () use ($request) {
                    $slug = Str::slug($request->input('tipo_permiso', ''), '_');

                    return in_array($slug, [
                        'fallecimiento_familiar',
                        'nacimiento_hijo',
                        'capacitacion_autogestionada',
                    ], true) || str_contains($slug, 'fallecimiento')
                      || str_contains($slug, 'nacimiento')
                      || str_contains($slug, 'capacitacion');
                }),
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png,doc,docx',
                'max:10240',
            ],
            'archivo_adjunto_url' => 'nullable|string|max:500',
            'visadores' => 'nullable|array',
        ], [
            'archivo.required' => 'El archivo adjunto es obligatorio para solicitudes de tipo fallecimiento familiar, nacimiento de hijo o capacitación autogestionada.',
            'archivo.mimes' => 'El archivo adjunto debe ser de formato PDF, JPG, PNG, DOC o DOCX.',
            'archivo.max' => 'El archivo adjunto no puede exceder los 10MB.',
        ]);

        if (empty($validados['dias_solicitados']) && empty($validados['horas_solicitadas'])) {
            throw ValidationException::withMessages([
                'dias_solicitados' => ['Debe indicar dias_solicitados o horas_solicitadas mayor a 0.'],
            ]);
        }

        // Si se envió un archivo adjunto, se almacena de forma segura con UUID
        if ($request->hasFile('archivo')) {
            $validados['archivo_adjunto_url'] = $this->almacenarArchivoAdjunto($request->file('archivo'));
        }

        try {
            $solicitud = $this->permisoService->crearSolicitud($validados);

            return response()->json([
                'status' => 'success',
                'message' => 'Solicitud de permiso creada con éxito.',
                'data' => $solicitud,
            ], 201);
        } catch (ValidationException $e) {
            $errores = $e->errors();
            $mensaje = isset($errores['saldo'])
                ? $errores['saldo'][0]
                : (isset($errores['archivo']) ? $errores['archivo'][0] : ($e->validator?->errors()->first() ?: 'Error de validación de saldo o datos.'));

            return response()->json([
                'status' => 'error',
                'error_code' => isset($errores['saldo']) ? 'SALDO_NO_CONFIGURADO' : 'VALIDATION_ERROR',
                'message' => $mensaje,
                'errors' => $errores,
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

        if (! $solicitud) {
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

        if (! $solicitud) {
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

        if (! $solicitud) {
            return response()->json([
                'status' => 'error',
                'message' => "Solicitud #{$id} no encontrada.",
            ], 404);
        }

        if (! $solicitud->esPendiente()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden editar solicitudes en estado pendiente de visación.',
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
     * Anulación directa instantánea de un permiso no decretado por el funcionario.
     */
    public function anularDirecto(Request $request, int $id): JsonResponse
    {
        $solicitud = PermisoSolicitud::find($id);

        if (! $solicitud) {
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
            $solicitudActualizada = $this->permisoService->anularDirecto($solicitud, $validados);

            return response()->json([
                'status' => 'success',
                'message' => 'Permiso anulado exitosamente de forma directa e inmediata.',
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
     * Solicitar anulación de la solicitud por parte del usuario.
     * Si no está decretado, se anula de forma directa e inmediata.
     * Si está decretado, cambia a 'pendiente_anulacion' para resolución de RRHH.
     */
    public function solicitarAnulacion(Request $request, int $id): JsonResponse
    {
        $solicitud = PermisoSolicitud::find($id);

        if (! $solicitud) {
            return response()->json([
                'status' => 'error',
                'message' => "Solicitud #{$id} no encontrada.",
            ], 404);
        }

        $validados = $request->validate([
            'insamu_user_id' => 'nullable|string|max:64',
            'motivo' => 'nullable|string|max:1000',
            'accion' => 'nullable|string|max:32',
        ]);

        try {
            if (($validados['accion'] ?? '') === 'anular_directo') {
                $solicitudActualizada = $this->permisoService->anularDirecto($solicitud, $validados);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Permiso anulado exitosamente de forma directa e inmediata.',
                    'data' => $solicitudActualizada,
                ]);
            }

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

        if (! $solicitud) {
            return response()->json([
                'status' => 'error',
                'message' => "Solicitud #{$id} no encontrada.",
            ], 404);
        }

        // Regla: Bloquea la descarga del PDF si está en pendiente_anulacion
        if (! $solicitud->permiteDescargaPdf()) {
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

    /**
     * Subir o actualizar el archivo adjunto para una solicitud existente.
     */
    public function subirAdjunto(Request $request, int $id): JsonResponse
    {
        $solicitud = PermisoSolicitud::find($id);

        if (! $solicitud) {
            return response()->json([
                'status' => 'error',
                'message' => "Solicitud #{$id} no encontrada.",
            ], 404);
        }

        $request->validate([
            'archivo' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ], [
            'archivo.required' => 'Debe adjuntar un archivo válido.',
            'archivo.mimes' => 'El archivo adjunto debe ser de formato PDF, JPG, PNG, DOC o DOCX.',
            'archivo.max' => 'El archivo adjunto no puede exceder los 10MB.',
        ]);

        $archivo = $request->file('archivo');
        $rutaAlmacenada = $this->almacenarArchivoAdjunto($archivo);

        $solicitud->archivo_adjunto_url = $rutaAlmacenada;
        $solicitud->save();

        LogSistema::registrar(
            'SUBIDA_ARCHIVO_ADJUNTO',
            $request->input('insamu_user_id', $solicitud->insamu_user_id),
            'permisos_solicitudes',
            $solicitud->id,
            [
                'archivo_url' => $rutaAlmacenada,
                'nombre_original' => $archivo->getClientOriginalName(),
                'tamano_bytes' => $archivo->getSize(),
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Archivo adjunto subido y almacenado correctamente.',
            'data' => [
                'solicitud_id' => $solicitud->id,
                'archivo_adjunto_url' => $rutaAlmacenada,
                'url_descarga' => url("/api/permisos/{$solicitud->id}/adjunto"),
            ],
        ]);
    }

    /**
     * Descargar o visualizar el archivo adjunto de una solicitud.
     */
    public function descargarAdjunto(Request $request, int $id): Response
    {
        $solicitud = PermisoSolicitud::find($id);

        if (! $solicitud) {
            return response()->json([
                'status' => 'error',
                'message' => "Solicitud #{$id} no encontrada.",
            ], 404);
        }

        if (empty($solicitud->archivo_adjunto_url)) {
            return response()->json([
                'status' => 'error',
                'message' => 'La solicitud no cuenta con un archivo adjunto.',
            ], 404);
        }

        $disco = config('filesystems.default', 'local');

        if (! Storage::disk($disco)->exists($solicitud->archivo_adjunto_url)) {
            return response()->json([
                'status' => 'error',
                'message' => 'El archivo adjunto no fue encontrado en el almacenamiento.',
            ], 404);
        }

        LogSistema::registrar(
            'DESCARGA_ARCHIVO_ADJUNTO',
            $request->input('insamu_user_id', $solicitud->insamu_user_id),
            'permisos_solicitudes',
            $solicitud->id,
            ['archivo' => $solicitud->archivo_adjunto_url]
        );

        if ($request->boolean('download')) {
            return Storage::disk($disco)->download($solicitud->archivo_adjunto_url);
        }

        return Storage::disk($disco)->response($solicitud->archivo_adjunto_url);
    }

    /**
     * Almacena de manera segura un archivo adjunto renombrándolo con UUID para evitar colisiones y riesgos de seguridad.
     */
    protected function almacenarArchivoAdjunto(UploadedFile $archivo): string
    {
        $extension = strtolower($archivo->getClientOriginalExtension() ?: $archivo->guessExtension() ?: 'bin');
        $nombreSeguro = 'adjunto_'.Str::uuid()->toString().'.'.$extension;
        $directorio = 'adjuntos_permisos/'.date('Y/m');
        $disco = config('filesystems.default', 'local');

        return $archivo->storeAs($directorio, $nombreSeguro, $disco);
    }
}
