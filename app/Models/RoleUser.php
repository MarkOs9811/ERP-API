<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoleUser extends Model
{
    use HasFactory;
    protected $fillable = [
        'idUsuarios',
        'idRole',
    ];
    public function rol()
    {
        return $this->belongsTo(Role::class, 'idRole');
    }

    public function permisos()
    {
        return $this->belongsToMany(Permiso::class, 'user_rol_permisos', 'idRolUser', 'idPermiso');
    }
}
