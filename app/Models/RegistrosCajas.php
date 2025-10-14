<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegistrosCajas extends Model
{
    use HasFactory;
    protected $fillable = [
        'idUsuario',
        'idCaja',
        'montoInicial',
        'montoFinal',
        'montoDejado',
        'fechaApertura',
        'horaApertura',
        'fechaCierre',
        'horaCierre',
        'estado',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario', 'id');
    }

    public function caja()
    {
        return $this->belongsTo(Caja::class, 'idCaja', 'id');
    }
}
