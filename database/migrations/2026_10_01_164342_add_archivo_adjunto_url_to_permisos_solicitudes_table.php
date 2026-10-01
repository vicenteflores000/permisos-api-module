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
            $table->string('archivo_adjunto_url', 500)->nullable()->after('motivo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('permisos_solicitudes', function (Blueprint $table) {
            $table->dropColumn('archivo_adjunto_url');
        });
    }
};
