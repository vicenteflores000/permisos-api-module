<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): JsonResponse {
    try {
        DB::connection()->getPdo();
        $isOperational = true;
    } catch (Throwable) {
        $isOperational = false;
    }

    return response()->json([
        'status' => $isOperational ? 'operativa' : 'no operativa',
        'version' => (string) config('app.version', '1.0.0'),
    ], $isOperational ? 200 : 503);
});

// Módulo "Gestor de Saldos" para Recursos Humanos
Route::get('/rrhh/gestor-saldos', function () {
    return view('rrhh.gestor-saldos');
});

Route::get('/solicitar-permiso', function () {
    return view('rrhh.gestor-saldos');
});

// Descarga de Plantilla CSV para Carga Masiva (CESFAM / DESAM) - Feriado Legal y Compensación
Route::get('/rrhh/plantilla-saldos.csv', function () {
    $csv = "insamu_user_id,anio,dias_feriado_legal,horas_compensacion\n"
         ."MEDICO-01,2026,15.0,0.0\n"
         ."ENFERMERA-02,2026,20.0,4.5\n"
         ."TENS-03,2026,15.0,12.0\n"
         ."ADMIN-04,2026,25.0,0.0\n";

    return response($csv, 200, [
        'Content-Type' => 'text/csv; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="plantilla_saldos_cesfam_2026.csv"',
    ]);
});
