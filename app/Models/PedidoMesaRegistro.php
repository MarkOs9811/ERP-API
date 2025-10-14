<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PedidoMesaRegistro extends Model
{
    use HasFactory;

    public function preVentas()
    {
        return $this->hasMany(PreventaMesa::class, 'idPedido');
    }
}
