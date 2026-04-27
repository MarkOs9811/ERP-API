<?php

use App\Models\Cliente;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/
// BROIADCAST PARA USUARIOS DEL SISTEMA
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
// BROADCAST Y PERSMISOS DE EVETNOS PARA CLIENTES
Broadcast::channel('cliente.{idCliente}', function ($user, $idCliente) {

    $cliente = Cliente::where('idPersona', $user->id)->first();
    return $cliente && $cliente->id === (int) $idCliente;
});
// Crea un canal específico para notificaciones de cualquier usuario
Broadcast::channel('user.notifications.{id}', function ($user, $id) {
    // Solo permitimos que el usuario escuche su propio canal
    return (int) $user->id === (int) $id;
});
