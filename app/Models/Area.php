<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    use HasFactory;

    protected $fillable = [
        'nombre',
        'descripcion',
        'idEmpresa',
    ];

    public function empleados()
    {
        return $this->hasMany(Empleado::class, 'idArea', 'id');
    }
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
