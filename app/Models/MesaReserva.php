<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MesaReserva extends Model
{
    use HasFactory;
    protected $fillable = [
        'idEmpresa',
        'idSede',
        'idUsuario',
        'idMesa',
        'idCliente',
        'nombre_cliente',
        'telefono_cliente',
        'fecha_reserva',
        'hora_reserva',
        'cantidad_personas',
        'estado',
        'nota'
    ];

    // Relación con la Mesa
    public function mesa()
    {
        return $this->belongsTo(Mesa::class, 'idMesa');
    }

    // Relación con el Usuario que la registró
    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario');
    }
}
