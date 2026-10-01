<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class PermisoSaldo extends Model
{
    use HasFactory;

    public const TIPO_ADMINISTRATIVO = 'administrativo';

    public const TIPO_FERIADO_LEGAL = 'feriado_legal';

    public const TIPO_COMPENSACION_TIEMPO = 'compensacion_tiempo';

    public const UNIDAD_DIAS = 'dias';

    public const UNIDAD_HORAS = 'horas';

    protected $table = 'permisos_saldos';

    protected $fillable = [
        'insamu_user_id',
        'anio',
        'tipo_permiso',
        'unidad',
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
        'cantidad_disponible',
    ];

    public function getCantidadDisponibleAttribute(): float
    {
        return max(0.0, round($this->dias_totales - $this->dias_usados, 2));
    }

    public function getDiasDisponiblesAttribute(): float
    {
        return $this->getCantidadDisponibleAttribute();
    }

    public function tieneSaldoSuficiente(float $cantidad): bool
    {
        return ($this->dias_usados + $cantidad) <= $this->dias_totales;
    }

    public function descontarCantidad(float $cantidad): void
    {
        $this->dias_usados = round($this->dias_usados + $cantidad, 2);
        $this->save();
    }

    public function descontarDias(float $dias): void
    {
        $this->descontarCantidad($dias);
    }

    public function restituirCantidad(float $cantidad): void
    {
        $this->dias_usados = max(0.0, round($this->dias_usados - $cantidad, 2));
        $this->save();
    }

    public function restituirDias(float $dias): void
    {
        $this->restituirCantidad($dias);
    }

    public function acumularHoras(float $horas): void
    {
        $this->dias_totales = round($this->dias_totales + $horas, 2);
        $this->save();
    }

    /**
     * Normaliza un nombre o slug de tipo de permiso a uno de los tipos con saldo registrado.
     * Retorna null si es un permiso que no requiere descuento de saldo.
     */
    public static function normalizarTipoPermiso(?string $tipo): ?string
    {
        if (empty($tipo)) {
            return self::TIPO_ADMINISTRATIVO;
        }

        $normalizado = mb_strtolower(trim($tipo));

        if (str_contains($normalizado, 'administrativ') || $normalizado === self::TIPO_ADMINISTRATIVO) {
            return self::TIPO_ADMINISTRATIVO;
        }

        if (str_contains($normalizado, 'feriado') || str_contains($normalizado, 'vacacion') || $normalizado === self::TIPO_FERIADO_LEGAL) {
            return self::TIPO_FERIADO_LEGAL;
        }

        if (str_contains($normalizado, 'compensa') || str_contains($normalizado, 'hora') || $normalizado === self::TIPO_COMPENSACION_TIEMPO) {
            return self::TIPO_COMPENSACION_TIEMPO;
        }

        return null;
    }

    /**
     * Obtener o inicializar un saldo específico de un usuario para un año y tipo.
     */
    public static function obtenerOCrear(string $userId, int $anio, string $tipoPermiso = self::TIPO_ADMINISTRATIVO, ?float $cantidadInicial = null): self
    {
        $configSaldos = config('insamu.saldos_defecto');
        $defecto = $configSaldos[$tipoPermiso] ?? null;

        $unidad = $defecto['unidad'] ?? ($tipoPermiso === self::TIPO_COMPENSACION_TIEMPO ? self::UNIDAD_HORAS : self::UNIDAD_DIAS);
        $cantidad = $cantidadInicial ?? ($defecto['cantidad_defecto'] ?? ($tipoPermiso === self::TIPO_FERIADO_LEGAL ? 15.0 : ($tipoPermiso === self::TIPO_COMPENSACION_TIEMPO ? 0.0 : 6.0)));

        return static::firstOrCreate(
            [
                'insamu_user_id' => $userId,
                'anio' => $anio,
                'tipo_permiso' => $tipoPermiso,
            ],
            [
                'unidad' => $unidad,
                'dias_totales' => $cantidad,
                'dias_usados' => 0.0,
            ]
        );
    }

    /**
     * Inicializa los tres saldos correspondientes al inicio de año para un funcionario.
     * - Permisos Administrativos: 6 días
     * - Feriados Legales: 15 días
     * - Compensación de Tiempo: 0 horas
     *
     * @return Collection<int, self>
     */
    public static function inicializarSaldosAnio(string $userId, int $anio)
    {
        $tipos = [
            self::TIPO_ADMINISTRATIVO,
            self::TIPO_FERIADO_LEGAL,
            self::TIPO_COMPENSACION_TIEMPO,
        ];

        return collect($tipos)->map(function (string $tipo) use ($userId, $anio) {
            return static::obtenerOCrear($userId, $anio, $tipo);
        });
    }
}
