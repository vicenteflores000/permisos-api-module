<?php

return [
    /*
    |--------------------------------------------------------------------------
    | INSAMU Shared Symmetric Secret Key
    |--------------------------------------------------------------------------
    |
    | Clave simétrica compartida entre INSAMU (Frontend/Core) y este microservicio.
    | Todas las solicitudes entrantes que modifiquen o consulten recursos deben
    | validar esta clave.
    |
    */
    'api_secret_key' => env('API_SECRET_KEY', 'default_insamu_secret_change_me'),

    /*
    |--------------------------------------------------------------------------
    | Saldos por defecto al inicio de cada año
    |--------------------------------------------------------------------------
    |
    | - Permisos Administrativos: 6 días anuales
    | - Feriados Legales (Vacaciones): 15 días anuales
    | - Compensación de Tiempo: 0 horas iniciales (aumenta según horas extra)
    |
    */
    'dias_totales_defecto' => (float) env('INSAMU_DIAS_TOTALES_DEFECTO', 6.0),

    'saldos_defecto' => [
        'administrativo' => [
            'tipo_permiso' => 'administrativo',
            'nombre' => 'Permiso Administrativo',
            'cantidad_defecto' => (float) env('INSAMU_DIAS_ADMINISTRATIVOS_DEFECTO', 6.0),
            'unidad' => 'dias',
            'requiere_saldo' => true,
        ],
        'feriado_legal' => [
            'tipo_permiso' => 'feriado_legal',
            'nombre' => 'Feriado Legal',
            'cantidad_defecto' => (float) env('INSAMU_DIAS_FERIADO_LEGAL_DEFECTO', 15.0),
            'unidad' => 'dias',
            'requiere_saldo' => true,
        ],
        'compensacion_tiempo' => [
            'tipo_permiso' => 'compensacion_tiempo',
            'nombre' => 'Compensación de Tiempo',
            'cantidad_defecto' => (float) env('INSAMU_HORAS_COMPENSACION_DEFECTO', 0.0),
            'unidad' => 'horas',
            'requiere_saldo' => true,
        ],
    ],
];
