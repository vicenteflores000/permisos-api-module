<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PermisoSaldo extends Model
{
    use HasFactory;

    protected $table = 'permisos_saldos';

    protected $fillable = [
        'insamu_user_id',
        'anio',
        'dias_totales',
        'dias_usados',
    ];

    protected $casts = [
        'anio' => 'integer',
        'dias_totales' => 'float',
        'dias_usados' => 'float',
    ];

    protected $appends = [
        'dias_disponibles',
    ];

    public function getDiasDisponiblesAttribute(): float
    {
        return max(0.0, round($this->dias_totales - $this->dias_usados, 1));
    }

    public function tieneSaldoSuficiente(float $dias): bool
    {
        return ($this->dias_usados + $dias) <= $this->dias_totales;
    }

    public function descontarDias(float $dias): void
    {
        $this->dias_usados = round($this->dias_usados + $dias, 1);
        $this->save();
    }

    public function restituirDias(float $dias): void
    {
        $this->dias_usados = max(0.0, round($this->dias_usados - $dias, 1));
        $this->save();
    }

    public static function obtenerOCrear(string $userId, int $anio, ?float $diasTotales = null): self
    {
        $dias = $diasTotales ?? (float) config('insamu.dias_totales_defecto', 6.0);

        return static::firstOrCreate(
            [
                'insamu_user_id' => $userId,
                'anio' => $anio,
            ],
            [
                'dias_totales' => $dias,
                'dias_usados' => 0.0,
            ]
        );
    }
}
