<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\EmpresaScope;

class MetodoPago extends Model
{
    use HasFactory;
    protected $fillable = [
        'nombre',
        'estado',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
        static::creating(function ($venta) {
            $user = auth()->user();

            if ($user) {
                if (empty($venta->idEmpresa)) {
                    $venta->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
