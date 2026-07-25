<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetallePedido extends Model
{
    use HasFactory;
    protected $fillable = [
        'idPedido',
        'idPlato',
        'idInventario', // <-- ¡Agrega esta línea!
        'cantidad',
        'producto',
        'precio_unitario',
        'estado'
    ];
    public function producto()
    {
        return $this->belongsTo(Plato::class, 'idPlato');
    }

    public function inventario()
    {
        return $this->belongsTo(Inventario::class, 'idInventario');
    }


    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'idPedido');
    }
}
