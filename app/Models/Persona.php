<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Persona extends Model
{
    use HasFactory, HasApiTokens;
    protected $fillable = [
        'nombre',
        'idDistrito',
        'apellidos',
        'fecha_nacimiento',
        'documento_identidad',
        'telefono',
        'google_id',
        'foto',
        'direccion',
        'idDistrito',
        'correo',

    ];

    public function empleado()
    {
        return $this->hasOne(Empleado::class, 'idPersona', 'id');
    }
    public function distrito()
    {
        return $this->belongsTo(Distrito::class, 'idDistrito', 'id');
    }
}
