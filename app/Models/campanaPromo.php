<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CampanaPromo extends Model
{
    protected $fillable = [
        'id',
        'nombre',
        'tipo',
        'codigo_cupon',
        'tipo_descuento',
        'valor_descuento',
        'monto_minimo_compra',
        'limite_uso',
        'fecha_inicio',
        'usados',
        'fecha_fin',
        'estado'
    ];

    // Casts para asegurar formatos correctos
    protected $casts = [
        'estado' => 'boolean',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'valor_descuento' => 'decimal:2',
        'monto_minimo_compra' => 'decimal:2',
    ];
    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
        static::addGlobalScope(new SedeScope);

        static::creating(function ($campanaPromo) {
            $user = auth()->user();

            if ($user) {
                if (empty($campanaPromo->idSede)) {
                    $campanaPromo->idSede = $user->idSede;
                }

                if (empty($campanaPromo->idEmpresa)) {
                    $campanaPromo->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
