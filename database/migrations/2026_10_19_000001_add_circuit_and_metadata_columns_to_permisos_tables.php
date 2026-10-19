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
            if (! Schema::hasColumn('permisos_solicitudes', 'jornada')) {
                $table->string('jornada', 32)->default('Completa')->nullable()->after('horas_solicitadas');
            }
            if (! Schema::hasColumn('permisos_solicitudes', 'hora_desde')) {
                $table->string('hora_desde', 16)->nullable()->after('jornada');
            }
            if (! Schema::hasColumn('permisos_solicitudes', 'hora_hasta')) {
                $table->string('hora_hasta', 16)->nullable()->after('hora_desde');
            }
            if (! Schema::hasColumn('permisos_solicitudes', 'parentesco_fallecimiento')) {
                $table->string('parentesco_fallecimiento', 64)->nullable()->after('hora_hasta');
            }
            if (! Schema::hasColumn('permisos_solicitudes', 'archivo_adjunto_nombre')) {
                $table->string('archivo_adjunto_nombre', 255)->nullable()->after('archivo_adjunto_url');
            }
            if (! Schema::hasColumn('permisos_solicitudes', 'visadores')) {
                $table->json('visadores')->nullable()->after('archivo_adjunto_url');
            }
            if (! Schema::hasColumn('permisos_solicitudes', 'decreto_observaciones')) {
                $table->text('decreto_observaciones')->nullable()->after('fecha_decreto');
            }
            if (! Schema::hasColumn('permisos_solicitudes', 'motivo_anulacion')) {
                $table->text('motivo_anulacion')->nullable()->after('decreto_observaciones');
            }
            if (! Schema::hasColumn('permisos_solicitudes', 'anulado_por')) {
                $table->string('anulado_por', 128)->nullable()->after('motivo_anulacion');
            }
            if (! Schema::hasColumn('permisos_solicitudes', 'anulado_en')) {
                $table->timestamp('anulado_en')->nullable()->after('anulado_por');
            }
            if (! Schema::hasColumn('permisos_solicitudes', 'solicitud_anulacion_en')) {
                $table->timestamp('solicitud_anulacion_en')->nullable()->after('anulado_en');
            }
            if (! Schema::hasColumn('permisos_solicitudes', 'anulacion_rechazada_en')) {
                $table->timestamp('anulacion_rechazada_en')->nullable()->after('solicitud_anulacion_en');
            }
            if (! Schema::hasColumn('permisos_solicitudes', 'anulacion_rechazo_motivo')) {
                $table->text('anulacion_rechazo_motivo')->nullable()->after('anulacion_rechazada_en');
            }
        });

        Schema::table('permiso_trazabilidad_firmas', function (Blueprint $table) {
            if (! Schema::hasColumn('permiso_trazabilidad_firmas', 'orden')) {
                $table->unsignedTinyInteger('orden')->default(1)->after('permiso_id');
            }
            if (! Schema::hasColumn('permiso_trazabilidad_firmas', 'observaciones')) {
                $table->text('observaciones')->nullable()->after('motivo_rechazo');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('permiso_trazabilidad_firmas', function (Blueprint $table) {
            $cols = array_filter(['orden', 'observaciones'], fn ($c) => Schema::hasColumn('permiso_trazabilidad_firmas', $c));
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('permisos_solicitudes', function (Blueprint $table) {
            $cols = array_filter([
                'jornada',
                'hora_desde',
                'hora_hasta',
                'parentesco_fallecimiento',
                'visadores',
                'decreto_observaciones',
                'motivo_anulacion',
                'anulado_por',
                'anulado_en',
                'solicitud_anulacion_en',
                'anulacion_rechazada_en',
                'anulacion_rechazo_motivo',
            ], fn ($c) => Schema::hasColumn('permisos_solicitudes', $c));

            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
