<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PermisoTrazabilidadFirma extends Model
{
    use HasFactory;

    protected $table = 'permiso_trazabilidad_firmas';

    protected $fillable = [
        'permiso_id',
        'insamu_visador_id',
        'nombre_visador',
        'rut_visador',
        'cargo_visador',
        'rol_firma',
        'estado_firma',
        'es_subrogante',
        'motivo_rechazo',
        'fecha_accion',
        'token_correo',
    ];

    protected $casts = [
        'es_subrogante' => 'boolean',
        'fecha_accion' => 'datetime',
    ];

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(PermisoSolicitud::class, 'permiso_id');
    }

    /**
     * Genera un token único y seguro para Deep Linking por correo.
     */
    public static function generarToken(): string
    {
        return hash('sha256', Str::random(40) . microtime(true));
    }

    /**
     * Invalida el token para que no pueda volver a ser utilizado.
     */
    public function invalidarToken(): void
    {
        $this->token_correo = null;
        $this->save();
    }

    /**
     * Registra la aprobación de la firma.
     */
    public function registrarAprobacion(array $datosVisador = []): void
    {
        $this->estado_firma = 'aprobado';
        $this->fecha_accion = now();

        if (!empty($datosVisador['nombre'])) {
            $this->nombre_visador = $datosVisador['nombre'];
        }
        if (!empty($datosVisador['rut'])) {
            $this->rut_visador = $datosVisador['rut'];
        }
        if (!empty($datosVisador['cargo'])) {
            $this->cargo_visador = $datosVisador['cargo'];
        }

        $this->save();
    }

    /**
     * Registra el rechazo de la firma.
     */
    public function registrarRechazo(string $motivo, array $datosVisador = []): void
    {
        $this->estado_firma = 'rechazado';
        $this->motivo_rechazo = $motivo;
        $this->fecha_accion = now();

        if (!empty($datosVisador['nombre'])) {
            $this->nombre_visador = $datosVisador['nombre'];
        }
        if (!empty($datosVisador['rut'])) {
            $this->rut_visador = $datosVisador['rut'];
        }
        if (!empty($datosVisador['cargo'])) {
            $this->cargo_visador = $datosVisador['cargo'];
        }

        $this->save();
    }
}
