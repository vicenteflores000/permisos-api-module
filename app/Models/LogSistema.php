<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class LogSistema extends Model
{
    use HasFactory;

    protected $table = 'logs_sistema';

    public $timestamps = false;

    protected $fillable = [
        'insamu_user_id',
        'accion',
        'entidad_tipo',
        'entidad_id',
        'detalles',
        'ip_origen',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'detalles' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Helper para registrar eventos de auditoría del sistema de manera sencilla.
     */
    public static function registrar(
        string $accion,
        ?string $userId = null,
        ?string $entidadTipo = null,
        ?int $entidadId = null,
        array $detalles = []
    ): self {
        return static::create([
            'insamu_user_id' => $userId,
            'accion' => $accion,
            'entidad_tipo' => $entidadTipo,
            'entidad_id' => $entidadId,
            'detalles' => $detalles,
            'ip_origen' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ]);
    }
}
