<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmpleadoBonificacione extends Model
{
    use HasFactory;

    protected $fillable = [
        'idEmpleado',
        'idBonificaciones',
        // Otros campos que necesiten asignación masiva
    ];
}
