<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use \App\Models\Plato;

class DetallePedidosWeb extends Model
{
    use HasFactory;
    protected $fillable = [
        'idPedido',
        'idPlato',
        'producto',
        'cantidad',
        'precio',
        'estado'
    ];

    // Relación con Plato
    public function plato()
    {
        return $this->belongsTo(Plato::class, 'idPlato');
    }

    // Relación con Pedido
    public function pedido()
    {
        return $this->belongsTo(PedidosWebRegistro::class, 'idPedido');
    }
}
