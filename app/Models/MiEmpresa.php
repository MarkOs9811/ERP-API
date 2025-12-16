<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MiEmpresa extends Model
{
    use HasFactory;
    protected $fillable = [
        'nombre',
        'ruc',
        'logo',
        'numero',
        'correo',
        'direccion',
    ];

    public function usuarios()
    {
        return $this->hasMany(User::class, 'idEmpresa', 'id');
    }

    public function sedes()
    {
        return $this->hasMany(Sede::class, 'idEmpresa', 'id');
    }
    public function configuraciones()
    {
        return $this->hasMany(Configuraciones::class, 'idEmpresa', 'id');
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'empresa_roles', 'idEmpresa', 'idRole')
            ->withPivot('estado')
            ->withTimestamps();
    }
}
