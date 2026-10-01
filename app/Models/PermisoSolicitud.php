<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PermisoSolicitud extends Model
{
    use HasFactory;

    protected $table = 'permisos_solicitudes';

    public const ESTADO_PENDIENTE_VISATURA = 'pendiente_visatura';

    public const ESTADO_PENDIENTE_DIRECCION = 'pendiente_direccion';

    public const ESTADO_EN_RRHH = 'en_rrhh';

    public const ESTADO_DECRETADO = 'decretado';

    public const ESTADO_PENDIENTE_ANULACION = 'pendiente_anulacion';

    public const ESTADO_ANULADO = 'anulado';

    public const ESTADO_RECHAZADO = 'rechazado';

    protected $fillable = [
        'insamu_user_id',
        'nombre_solicitante',
        'rut_solicitante',
        'cargo_solicitante',
        'unidad_solicitante',
        'tipo_permiso',
        'fecha_inicio',
        'fecha_fin',
        'dias_solicitados',
        'horas_solicitadas',
        'estado',
        'motivo',
        'archivo_adjunto_url',
        'estado_previo_anulacion',
        'decreto_numero',
        'fecha_decreto',
    ];

    protected $casts = [
        'fecha_inicio' => 'date:Y-m-d',
        'fecha_fin' => 'date:Y-m-d',
        'fecha_decreto' => 'date:Y-m-d',
        'dias_solicitados' => 'float',
        'horas_solicitadas' => 'float',
    ];

    protected $appends = [
        'url_descarga_adjunto',
    ];

    public function getUrlDescargaAdjuntoAttribute(): ?string
    {
        if (empty($this->archivo_adjunto_url)) {
            return null;
        }

        return url("/api/permisos/{$this->id}/adjunto");
    }

    public function tieneAdjunto(): bool
    {
        return ! empty($this->archivo_adjunto_url);
    }

    public function firmas(): HasMany
    {
        return $this->hasMany(PermisoTrazabilidadFirma::class, 'permiso_id');
    }

    public function firmaPendiente(): HasOne
    {
        return $this->hasOne(PermisoTrazabilidadFirma::class, 'permiso_id')
            ->where('estado_firma', 'pendiente')
            ->latestOfMany();
    }

    public function firmasAprobadas(): HasMany
    {
        return $this->hasMany(PermisoTrazabilidadFirma::class, 'permiso_id')
            ->where('estado_firma', 'aprobado')
            ->orderBy('fecha_accion', 'asc');
    }

    /**
     * Determina si la solicitud está en un estado pendiente de visación (editable para subrogancias).
     */
    public function esPendiente(): bool
    {
        return in_array($this->estado, [
            self::ESTADO_PENDIENTE_VISATURA,
            self::ESTADO_PENDIENTE_DIRECCION,
        ], true);
    }

    /**
     * Comprueba si el PDF puede ser descargado (bloqueado en pendiente_anulacion).
     */
    public function permiteDescargaPdf(): bool
    {
        return $this->estado !== self::ESTADO_PENDIENTE_ANULACION;
    }

    /**
     * Año de imputación del permiso según fecha de inicio.
     */
    public function getAnioImputacionAttribute(): int
    {
        return Carbon::parse($this->fecha_inicio)->year;
    }

    public function getAnioAttribute(): int
    {
        return $this->getAnioImputacionAttribute();
    }

    public function getTipoSaldoAttribute(): ?string
    {
        return PermisoSaldo::normalizarTipoPermiso($this->tipo_permiso);
    }

    public function getCantidadSolicitadaAttribute(): float
    {
        if ($this->tipo_saldo === PermisoSaldo::TIPO_COMPENSACION_TIEMPO) {
            return (float) ($this->horas_solicitadas ?? $this->dias_solicitados ?? 0.0);
        }

        return (float) ($this->dias_solicitados ?? 0.0);
    }
}
