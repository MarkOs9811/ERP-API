<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PedidoCreadoEvent implements ShouldBroadcast
{


    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $codigoPedido,
        public string $numeroCliente,
        public string $estadoPedido
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('pedidosPendiente');
    }

    public function broadcastAs(): string
    {
        return 'pedido.creado'; // Cambiado a naming convention más estándar
    }
    public function broadcastWith(): array
    {
        return [
            'codigoPedido' => $this->codigoPedido,
            'numeroCliente' => $this->numeroCliente,
            'estadoPedido' => $this->estadoPedido,
            'timestamp' => now()->toISOString()
        ];
    }
}
