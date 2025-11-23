<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cargo extends Model
{
    use HasFactory;
    protected $fillable = [
        'idEmpresa',
        'nombre',
        'salario',
        'pagoPorHoras',
        'estado',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'cargo_roles', 'idCargo', 'idRole');
    }

    public function empleados()
    {
        return $this->hasMany(Empleado::class, 'idCargo', 'id');
    }

    public function getRolesAttribute()
    {
        return $this->roles()->get();
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);


        static::creating(function ($cargo) {
            $user = auth()->user();

            if ($user) {

                if (empty($cargo->idEmpresa)) {
                    $cargo->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
