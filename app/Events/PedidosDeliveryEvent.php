<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PedidosDeliveryEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $idCliente,
        public string $tipo,
        public string $mensaje,
        public string $titulo,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('deliveryEstado'),
        ];
    }
    public function broadcastAs(): string
    {
        return 'pedido.cambiarEstado'; // Cambiado a naming convention más estándar
    }
    public function broadcastWith(): array
    {
        return [
            'cliente' => $this->idCliente,
            'tipo' => $this->tipo,
            'mensaje' => $this->mensaje,
            'titulo' => $this->titulo,
            'timestamp' => now()->toISOString()
        ];
    }
}
