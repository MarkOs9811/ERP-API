<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PedidoCocinaEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $detallePlatos;
    public int $idPedido;
    public string $estado;
    public string $tipo_pedido;

    public function __construct(int $idPedido, array $detallePlatos, string $tipo_pedido, string $estado = '1')
    {
        $this->idPedido = $idPedido;
        $this->detallePlatos = $detallePlatos;  // Ejemplo: [['nombre' => 'Ceviche', 'cantidad' => 2], ...]
        $this->estado = $estado;
        $this->tipo_pedido = $tipo_pedido;
    }

    public function broadcastOn()
    {
        return new Channel('pedidosEstado');  // Canal público, puedes cambiar a PrivateChannel si quieres
    }

    public function broadcastAs()
    {
        return 'pedidosEstado.creado';
    }

    public function broadcastWith()
    {
        return [
            'idPedido' => $this->idPedido,
            'detallePlatos' => $this->detallePlatos,
            'estado' => $this->estado,
            'tipo_pedido' => $this->tipo_pedido,
            'timestamp' => now()->toISOString(),
        ];
    }
}
