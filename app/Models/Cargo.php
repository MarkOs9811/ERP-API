<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cargo extends Model
{
    use HasFactory;
    protected $fillable = ['nombre'];

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
}
