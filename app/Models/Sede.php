<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sede extends Model
{
    use HasFactory;

    protected $fillable = [
        'codigo',
        'nombre',
        'direccion',
        'telefono',
        'idEmpresa',
    ];
    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);

        static::creating(function ($sedes) {
            $user = auth()->user();

            if ($user) {
                if (empty($sedes->idEmpresa)) {
                    $sedes->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
