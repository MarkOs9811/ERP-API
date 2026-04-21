<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NuevaNotificacionCliente implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $idCliente;

    public function __construct($idCliente)
    {
        $this->idCliente = $idCliente;
    }

    // A qué canal se va a transmitir
    public function broadcastOn()
    {
        // Canal privado único para este cliente
        return new PrivateChannel('cliente.' . $this->idCliente);
    }

    // Opcional: El nombre del evento que escuchará React
    public function broadcastAs()
    {
        return 'NotificacionActualizada';
    }

    // Solo enviamos una bandera simple
    public function broadcastWith()
    {
        return ['recargar' => true];
    }
}
