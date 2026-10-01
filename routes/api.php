<?php

use App\Http\Controllers\FirmaController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\PermisoController;
use App\Http\Controllers\RrhhController;
use App\Http\Controllers\SaldoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Módulo de Permisos Administrativos INSAMU
|--------------------------------------------------------------------------
|
| Todas las rutas de esta API están protegidas bajo el middleware 'insamu.auth',
| el cual verifica la clave simétrica compartida (API_SECRET_KEY) en el header
| 'X-API-SECRET-KEY' o 'Authorization: Bearer <key>'.
|
*/

Route::middleware(['insamu.auth'])->group(function () {

    // Verificación de estado de la API
    Route::get('/ping', function () {
        return response()->json([
            'status' => 'online',
            'modulo' => 'INSAMU - Permisos Administrativos API',
            'time' => now()->toIso8601String(),
        ]);
    });

    // Gestión de Solicitudes de Permisos
    Route::prefix('permisos')->group(function () {
        Route::get('/', [PermisoController::class, 'index']);
        Route::post('/', [PermisoController::class, 'store']);
        Route::get('/{id}', [PermisoController::class, 'show']);
        Route::put('/{id}', [PermisoController::class, 'update']);
        Route::patch('/{id}', [PermisoController::class, 'update']);

        // Subrogancia Autogestionada
        Route::post('/{id}/subrogar-visador', [PermisoController::class, 'subrogarVisador']);

        // Lógica de Anulación (Funcionario)
        Route::post('/{id}/solicitar-anulacion', [PermisoController::class, 'solicitarAnulacion']);

        // Generación y descarga de PDF inalterable
        Route::get('/{id}/pdf', [PermisoController::class, 'descargarPdf']);
    });

    // Firma Electrónica Simple (FES)
    Route::prefix('firmas')->group(function () {
        Route::post('/confirmar', [FirmaController::class, 'confirmar']);
        Route::get('/verificar-token/{token}', [FirmaController::class, 'verificarToken']);
    });

    // Recursos Humanos (RRHH)
    Route::prefix('rrhh/permisos')->group(function () {
        Route::post('/{id}/aprobar-anulacion', [RrhhController::class, 'aprobarAnulacion']);
        Route::post('/{id}/rechazar-anulacion', [RrhhController::class, 'rechazarAnulacion']);
        Route::post('/{id}/decretar', [RrhhController::class, 'decretar']);
    });

    // Saldos de Permisos
    Route::prefix('saldos')->group(function () {
        Route::get('/{userId}', [SaldoController::class, 'show']);
        Route::post('/', [SaldoController::class, 'upsert']);
    });

    // Logs de Auditoría
    Route::get('/logs', [LogController::class, 'index']);
});
