<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Automatización de Días Administrativos (Cron Job anual: 1 de enero a las 00:00)
// Asigna 6 días administrativos con vencimiento estricto al 31 de diciembre.
Schedule::command('permisos:asignar-administrativos-anuales')
    ->yearlyOn(1, 1, '00:00')
    ->description('Asignación automática anual de 6 días administrativos a funcionarios activos');
