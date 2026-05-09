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
        'idPromocion',
        'producto',
        'cantidad',
        'precio',
        'nota',
        'estado'
    ];

    // Relación con Plato
    public function plato()
    {
        return $this->belongsTo(Plato::class, 'idPlato', 'id');
    }

    public function promociones()
    {
        return $this->belongsTo(PromocionesApp::class, 'idPromocion', 'id');
    }


    // Relación con Pedido
    public function pedido()
    {
        return $this->belongsTo(PedidosWebRegistro::class, 'idPedido');
    }
}
