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
    public function cajas()
    {
        // Primer parámetro: El modelo relacionado (Caja)
        // Segundo parámetro: La llave foránea en la tabla 'cajas' (idSede)
        // Tercer parámetro: La llave local en la tabla 'sedes' (id)
        return $this->hasMany(Caja::class , 'idSede', 'id');
    }

    public function configuracionDelivery()
    {
        return $this->hasOne(ConfiguracionDelivery::class , 'idSede', 'id');
    }

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
