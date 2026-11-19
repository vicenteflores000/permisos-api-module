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
        Schema::table('permisos_solicitudes', function (Blueprint $table) {
            if (! Schema::hasColumn('permisos_solicitudes', 'fechas_seleccionadas')) {
                $table->json('fechas_seleccionadas')->nullable()->after('fecha_fin');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('permisos_solicitudes', function (Blueprint $table) {
            if (Schema::hasColumn('permisos_solicitudes', 'fechas_seleccionadas')) {
                $table->dropColumn('fechas_seleccionadas');
            }
        });
    }
};
