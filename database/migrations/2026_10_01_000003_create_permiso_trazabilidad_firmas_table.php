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
        Schema::create('permiso_trazabilidad_firmas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permiso_id')->constrained('permisos_solicitudes')->cascadeOnDelete();
            $table->string('insamu_visador_id', 64)->index();
            $table->string('nombre_visador')->nullable();
            $table->string('rut_visador', 20)->nullable();
            $table->string('cargo_visador')->nullable();
            $table->string('rol_firma', 64); // Ej. Jefatura, Dirección, RRHH
            $table->enum('estado_firma', ['pendiente', 'aprobado', 'rechazado', 'subrogado'])->default('pendiente')->index();
            $table->boolean('es_subrogante')->default(false);
            $table->text('motivo_rechazo')->nullable();
            $table->timestamp('fecha_accion')->nullable();
            $table->string('token_correo', 255)->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permiso_trazabilidad_firmas');
    }
};
