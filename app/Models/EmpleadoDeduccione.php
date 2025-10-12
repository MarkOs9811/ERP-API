<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmpleadoDeduccione extends Model
{
    use HasFactory;

    protected $fillable = [
        'idEmpleado',
        'idDeduccion',
        // Otros campos que puedan ser necesarios
    ];
}
