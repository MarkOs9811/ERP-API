<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UbicacionRiderActualizada implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $idCliente;

    public function __construct($idCliente)
    {
        $this->idCliente = $idCliente;
    }

    public function broadcastOn()
    {
        // Emitimos al mismo canal privado que ya tienes configurado en React
        return new PrivateChannel('cliente.' . $this->idCliente);
    }

    public function broadcastAs()
    {
        // Este es el nombre exacto que escucharemos en React (con el punto inicial)
        return 'UbicacionRiderActualizada';
    }
}