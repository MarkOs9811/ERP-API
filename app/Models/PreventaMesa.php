<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PreventaMesa extends Model
{
    use HasFactory;
    protected $fillable = [
        'idUsuario',
        'idCaja',
        'idPlato',
        'idMesa',
        'idPedido',
        'cantidad',
        'precio',
    ];

    public function plato()
    {
        return $this->belongsTo(plato::class, 'idPlato');
    }
    public function usuario()
    {
        return $this->belongsTo(empleado::class, 'idUsuario');
    }
    public function mesa()
    {
        return $this->belongsTo(mesa::class, 'idMesa');
    }
    public function caja()
    {
        return $this->belongsTo(caja::class, 'idCaja');
    }
    public function pedido()
    {
        return $this->belongsTo(PedidoMesaRegistro::class, 'idPedido');
    }
}
