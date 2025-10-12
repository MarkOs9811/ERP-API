<?php

namespace App\Models;

use App\Models\Scopes\SedeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    use HasFactory;

    public function metodoPago()
    {
        return $this->belongsTo(MetodoPago::class, 'idMetodo');
    }

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'idPedido');
    }
    public function pedidoWeb()
    {
        return $this->belongsTo(PedidosWebRegistro::class, 'idPedidoWeb');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'idCliente');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario');
    }

    public function detallePedidos()
    {
        return $this->hasManyThrough(DetallePedido::class, Pedido::class, 'id', 'idPedido', 'idPedido', 'id');
    }
    // Relación con Factura
    public function factura()
    {
        return $this->hasOne(Factura::class, 'idVenta');
    }

    // Relación con Boleta
    public function boleta()
    {
        return $this->hasOne(Boleta::class, 'idVenta');
    }

    protected static function booted()
    {
        static::addGlobalScope(new SedeScope);
        static::creating(function ($venta) {
            if (auth()->check() && empty($venta->idSede)) {
                $venta->idSede = auth()->user()->idSede;
            }
        });
    }
}
