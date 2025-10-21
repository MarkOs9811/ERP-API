<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Proveedore extends Model
{
    use HasFactory;
    protected $fillable = [
        'nombre',
        'contacto',
        'direccion',
        'tipo_documento',
        'numero_documento',
        'telefono',
        'email',
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
