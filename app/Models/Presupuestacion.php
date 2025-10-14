<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Presupuestacion extends Model
{
    use HasFactory;

    // Especificamos los campos que pueden ser asignados masivamente
    protected $fillable = [
        'idUsuario',
        'tipo_presupuesto',
        'ciclo_anual',
        'anio',
        'mes',
        'monto_presupuestado',
        'descripcion',
        'observaciones',
        'monto_acumulado',
        'diferencia',
    ];
}
