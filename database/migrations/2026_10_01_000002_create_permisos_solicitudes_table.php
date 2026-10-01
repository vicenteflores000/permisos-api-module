<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('permisos_solicitudes', function (Blueprint $table) {
            $table->id();
            $table->string('insamu_user_id', 64)->index();
            $table->string('nombre_solicitante')->nullable();
            $table->string('rut_solicitante', 20)->nullable();
            $table->string('cargo_solicitante')->nullable();
            $table->string('unidad_solicitante')->nullable();
            $table->string('tipo_permiso');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->decimal('dias_solicitados', 4, 1);
            $table->enum('estado', [
                'pendiente_visatura',
                'pendiente_direccion',
                'en_rrhh',
                'decretado',
                'pendiente_anulacion',
                'anulado',
                'rechazado',
            ])->default('pendiente_visatura')->index();
            $table->text('motivo')->nullable();
            $table->string('estado_previo_anulacion', 32)->nullable();
            $table->string('decreto_numero', 64)->nullable();
            $table->date('fecha_decreto')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permisos_solicitudes');
    }
};
