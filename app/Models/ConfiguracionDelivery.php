<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;

class ConfiguracionDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'idEmpresa',
        'idSede',
        'costo_base_delivery',
        'costo_prioridad',
        'tiempo_min',
        'tiempo_max',
        'propinas_sugeridas',
        'estado',
    ];

    // ESTO ES CLAVE: Convierte el JSON de la BD a Array de PHP automáticamente
    protected $casts = [
        'propinas_sugeridas' => 'array',
        'costo_base_delivery' => 'decimal:2',
        'costo_prioridad' => 'decimal:2',
    ];

    // Relación inversa (opcional)
    public function empresa()
    {
        return $this->belongsTo(MiEmpresa::class , 'idEmpresa', 'id');
    }
    public function sede()
    {
        return $this->belongsTo(Sede::class , 'idSede', 'id');
    }
    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);

        static::creating(function ($ZonaTarifaConfig) {
            $user = auth()->user();

            if ($user) {
                if (empty($ZonaTarifaConfig->idEmpresa)) {
                    $ZonaTarifaConfig->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
