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
        Schema::create('permisos_saldos', function (Blueprint $table) {
            $table->id();
            $table->string('insamu_user_id', 64)->index();
            $table->unsignedSmallInteger('anio');
            $table->decimal('dias_totales', 4, 1)->default(6.0);
            $table->decimal('dias_usados', 4, 1)->default(0.0);
            $table->timestamps();

            $table->unique(['insamu_user_id', 'anio'], 'uq_usuario_anio_saldo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permisos_saldos');
    }
};
