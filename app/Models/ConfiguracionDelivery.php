<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConfiguracionDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'idEmpresa',
        'costo_base_delivery',
        'costo_prioridad',
        'tiempo_min',
        'tiempo_max',
        'propinas_sugeridas',
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
        return $this->belongsTo(MiEmpresa::class, 'idEmpresa', 'id');
    }
}
