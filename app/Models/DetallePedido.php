<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetallePedido extends Model
{
    use HasFactory;

    public function producto()
    {
        return $this->belongsTo(Plato::class, 'idPlato');
    }

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'idPedido');
    }
}
