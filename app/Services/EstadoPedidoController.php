<?php

namespace App\Services;

use App\Events\PedidoCocinaEvent;
use App\Models\EstadoPedido;

class EstadoPedidoController
{
    protected $tipoPedido;
    protected $idCaja;
    protected $detallePlatos;
    protected $detalleCliente;
    protected $idRelacion;
    protected $numMesa;
    public function __construct($tipoPedido, $idCaja, $detallePlatos, $idRelacion, $detalleCliente = null, $numMesa = null)
    {
        $this->tipoPedido = $tipoPedido;
        $this->idCaja = $idCaja;
        $this->detallePlatos = $detallePlatos;
        $this->idRelacion = $idRelacion;
        $this->detalleCliente = $detalleCliente;
        $this->numMesa = $numMesa;
    }

    public function registrar()
    {
        $pedido = new EstadoPedido();
        $pedido->tipo_pedido = $this->tipoPedido;
        $pedido->idCaja = $this->idCaja;
        $pedido->detalle_platos = $this->detallePlatos; // Entra como JSON
        $pedido->detalles_extras = null;
        $pedido->numeroMesa = $this->numMesa;

        switch ($this->tipoPedido) {
            case 'mesa':
                $pedido->idPedidoMesa = $this->idRelacion;
                break;
            case 'llevar':
                $pedido->idPedidoLLevar = $this->idRelacion; // ✅ LL Mayúscula
                break;
            case 'web':
                $pedido->idPedidoWsp = $this->idRelacion;
                break;
        }

        $pedido->estado = 0;
        $pedido->detalle_cliente = $this->detalleCliente; // ✅ Entra como String simple (el nombre)
        $pedido->save();

        event(new PedidoCocinaEvent(
            $pedido->id,
            json_decode($this->detallePlatos, true), // ✅ Decodificamos solo para el WebSocket
            $this->tipoPedido,
            $pedido->estado
        ));

        return $pedido;
    }
}
