<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiSecretKey
{
    /**
     * Handle an incoming request.
     *
     * Valida que la petición provenga legítimamente de INSAMU validando
     * la clave simétrica compartida (API_SECRET_KEY) configurada en el microservicio.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = config('insamu.api_secret_key');

        if (empty($configuredKey)) {
            return response()->json([
                'error' => 'Configuración incompleta: API_SECRET_KEY no configurada en el microservicio.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $providedKey = $request->header('X-API-SECRET-KEY')
            ?? $request->header('X-API-KEY')
            ?? $request->bearerToken();

        if (!$providedKey || !hash_equals((string) $configuredKey, (string) $providedKey)) {
            return response()->json([
                'error' => 'No autorizado. Clave simétrica API_SECRET_KEY inválida o ausente.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
