<?php

namespace App\Console\Commands;

use App\Models\LogSistema;
use App\Models\PermisoSaldo;
use App\Models\PermisoSolicitud;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

class AsignarDiasAdministrativosAnuales extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'permisos:asignar-administrativos-anuales
                            {--anio= : Año calendario para el cual asignar los días administrativos (por defecto el año entrante/actual)}
                            {--user= : ID de un funcionario específico (opcional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Asigna automáticamente 6 días administrativos a cada funcionario activo cada 1 de enero a las 00:00 (caducidad estricta al 31 de diciembre)';

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        $schedule->yearlyOn(1, 1, '00:00');
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $anio = (int) ($this->option('anio') ?: now()->year);
        $userFiltro = $this->option('user');

        $this->info("Iniciando asignación automática de días administrativos para el año {$anio}...");

        $userIds = collect();

        if ($userFiltro) {
            $userIds = collect([trim((string) $userFiltro)]);
        } else {
            // Obtener funcionarios activos conocidos por el sistema
            $saldosUsers = PermisoSaldo::distinct()->pluck('insamu_user_id');
            $solicitudesUsers = PermisoSolicitud::distinct()->pluck('insamu_user_id');
            $appUsers = User::pluck('name');

            $userIds = $saldosUsers
                ->merge($solicitudesUsers)
                ->merge($appUsers)
                ->filter(fn ($u) => ! empty($u))
                ->unique()
                ->values();
        }

        if ($userIds->isEmpty()) {
            $this->warn('No se encontraron funcionarios activos para procesar.');

            return self::SUCCESS;
        }

        $procesados = 0;

        DB::transaction(function () use ($userIds, $anio, &$procesados) {
            foreach ($userIds as $userId) {
                // Vencimiento estricto: Se crea o inicializa el registro del nuevo año
                // con exactamente 6.0 días totales y 0.0 usados.
                // Los días no utilizados del año anterior NO se traspasan ni se suman.
                PermisoSaldo::updateOrCreate(
                    [
                        'insamu_user_id' => $userId,
                        'anio' => $anio,
                        'tipo_permiso' => PermisoSaldo::TIPO_ADMINISTRATIVO,
                    ],
                    [
                        'unidad' => PermisoSaldo::UNIDAD_DIAS,
                        'dias_totales' => 6.0,
                        'dias_usados' => 0.0,
                    ]
                );

                $procesados++;
            }

            LogSistema::registrar(
                'ASIGNACION_AUTOMATICA_ADMINISTRATIVOS_ANUAL',
                'SISTEMA_CRON',
                'permisos_saldos',
                null,
                [
                    'anio' => $anio,
                    'total_funcionarios' => $procesados,
                    'dias_asignados_por_funcionario' => 6.0,
                    'caducidad_estricta' => "31/12/{$anio}",
                ]
            );
        });

        $this->info("✓ Proceso completado exitosamente: Se asignaron 6 días administrativos a {$procesados} funcionarios para el año {$anio}.");

        return self::SUCCESS;
    }
}
