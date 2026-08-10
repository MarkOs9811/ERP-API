<?php

namespace App\Models;


use App\Models\Scopes\SedeScope;
use App\Models\Scopes\EmpresaScope;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mesa extends Model
{
    use HasFactory;
    public function preventas()
    {
        // belongsTo / hasMany -> (Modelo Destino, foreign_key, local_key)
        return $this->hasMany(PreventaMesa::class, 'idMesa', 'id');
    }
    protected static function booted()
    {
        static::addGlobalScope(new SedeScope);
        static::addGlobalScope(new EmpresaScope);

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
