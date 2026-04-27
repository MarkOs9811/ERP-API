<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NuevaNotificacionUsuario implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $idUsuario;

    public function __construct($idUsuario)
    {
        $this->idUsuario = $idUsuario;
    }

    // A qué canal se va a transmitir
    public function broadcastOn()
    {
        // Canal privado único para este usuario
        return new PrivateChannel('user.notifications.' . $this->idUsuario);
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
