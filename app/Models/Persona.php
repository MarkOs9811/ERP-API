<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Persona extends Model
{
    use HasFactory;
    protected $fillable = [
        'nombre',
        'idDistrito',
        'apellidos',
        'fecha_nacimiento',
        'documento_identidad',
        'telefono',
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
