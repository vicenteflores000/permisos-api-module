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
    | Días totales por defecto
    |--------------------------------------------------------------------------
    |
    | Días administrativos asignados por año calendario según estatuto.
    |
    */
    'dias_totales_defecto' => (float) env('INSAMU_DIAS_TOTALES_DEFECTO', 6.0),
];
