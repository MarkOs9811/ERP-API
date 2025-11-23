<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = ['nombre'];



    public function cargos()
    {
        return $this->belongsToMany(Cargo::class, 'cargo_roles', 'idRole', 'idCargo');
    }

    public function users()
    {

        return $this->belongsToMany(User::class, 'role_users', 'idRole', 'idUsuarios');
    }

    public function rolUsers()
    {
        return $this->hasMany(RoleUser::class, 'idRole');
    }
}
