<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bonificacione extends Model
{
    use HasFactory;

    public function empleados()
    {
        return $this->belongsToMany(Empleado::class, 'empleado_bonificaciones', 'idBonificacion', 'idEmpleado');
    }

    protected static function booted()
    {

        static::addGlobalScope(new EmpresaScope);

        static::creating(function ($bonificación) {
            $user = auth()->user();
            if ($user) {
                if (empty($bonificación->idEmpresa)) {
                    $bonificación->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
