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
        return $this->belongsTo(Plato::class, 'idPlato');
    }
    public function usuario()
    {
        return $this->belongsTo(Empleado::class, 'idUsuario');
    }
    public function mesa()
    {
        return $this->belongsTo(Mesa::class, 'idMesa');
    }
    public function caja()
    {
        return $this->belongsTo(Caja::class, 'idCaja');
    }
    public function pedido()
    {
        return $this->belongsTo(PedidoMesaRegistro::class, 'idPedido');
    }
}
