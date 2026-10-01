<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Valida que la raíz retorne el estado operativo y la versión de la API.
     */
    public function test_the_application_returns_operational_status_and_version(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'operativa',
                'version' => config('app.version', '1.0.0'),
            ]);
    }

    /**
     * Valida que la raíz reporte estado no operativo cuando la base de datos falla.
     */
    public function test_the_application_reports_unoperational_status_when_database_fails(): void
    {
        DB::shouldReceive('connection->getPdo')
            ->andThrow(new \Exception('Database connection failed'));

        $response = $this->get('/');

        $response->assertStatus(503)
            ->assertJson([
                'status' => 'no operativa',
                'version' => config('app.version', '1.0.0'),
            ]);
    }

    /**
     * Valida que la interfaz web del Gestor de Saldos y la plantilla CSV sean accesibles.
     */
    public function test_gestor_saldos_view_and_csv_template_accessible(): void
    {
        // 1. Interfaz Gestor de Saldos RRHH
        $viewRes = $this->get('/rrhh/gestor-saldos');
        $viewRes->assertStatus(200)
            ->assertSee('Gestor de Saldos de Recursos Humanos')
            ->assertSee('Inicialización de Permisos')
            ->assertSee('Abono de Compensaciones')
            ->assertSee('Saldo anual no configurado');

        // 2. Ruta de descarga de plantilla CSV
        $csvRes = $this->get('/rrhh/plantilla-saldos.csv');
        $csvRes->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $csvRes->headers->get('content-type'));
        $this->assertStringContainsString('insamu_user_id', $csvRes->getContent());
    }
}
