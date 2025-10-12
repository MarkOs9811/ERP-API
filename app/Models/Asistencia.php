<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asistencia extends Model
{
    use HasFactory;

    protected $fillable = [
        'codigoUsuario', 'fechaEntrada', 'horaEntrada', 'fechaSalida', 'horaSalida', 'horasTrabajadas', 'estadoAsistencia','estado'
    ];

    public function empleado()
    {
        return $this->belongsTo(Persona::class, 'codigoUsuario' , 'documento_identidad');
    }
}
