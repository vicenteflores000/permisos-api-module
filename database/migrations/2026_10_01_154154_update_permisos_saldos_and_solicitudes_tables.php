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
        Schema::table('permisos_saldos', function (Blueprint $table) {
            $table->string('tipo_permiso', 32)->default('administrativo')->after('anio');
            $table->string('unidad', 16)->default('dias')->after('tipo_permiso');
            $table->dropUnique('uq_usuario_anio_saldo');
            $table->unique(['insamu_user_id', 'anio', 'tipo_permiso'], 'uq_usuario_anio_tipo_saldo');
        });

        Schema::table('permisos_solicitudes', function (Blueprint $table) {
            $table->decimal('horas_solicitadas', 5, 2)->nullable()->after('dias_solicitados');
            $table->decimal('dias_solicitados', 4, 1)->default(0.0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('permisos_solicitudes', function (Blueprint $table) {
            $table->dropColumn('horas_solicitadas');
            $table->decimal('dias_solicitados', 4, 1)->change();
        });

        Schema::table('permisos_saldos', function (Blueprint $table) {
            $table->dropUnique('uq_usuario_anio_tipo_saldo');
            $table->unique(['insamu_user_id', 'anio'], 'uq_usuario_anio_saldo');
            $table->dropColumn(['tipo_permiso', 'unidad']);
        });
    }
};
