<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromotionalBanner extends Model
{
    use HasFactory;
    protected $fillable = [
        'idEmpresa',
        'idSede',
        'tag',
        'title',
        'subtitle',
        'offer',
        'code',
        'theme',
        'has_icon',
        'icon_name',
        'border_radius',
        'bg_color',
        'text_color',
        'gradient',
        'gradient_color',
        'has_aura',
        'aura_color',
        'is_active',
    ];

    // Asegurarnos de que Laravel convierta los 1 y 0 de la BD a true/false en React
    protected $casts = [
        'has_icon' => 'boolean',
        'gradient' => 'boolean',
        'has_aura' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
        static::addGlobalScope(new SedeScope);

        static::creating(function ($venta) {
            $user = auth()->user();

            if ($user) {
                if (empty($venta->idSede)) {
                    $venta->idSede = $user->idSede;
                }

                if (empty($venta->idEmpresa)) {
                    $venta->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
