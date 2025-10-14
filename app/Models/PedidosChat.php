<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PedidosChat extends Model
{
    use HasFactory;
    protected $table = 'pedidos_chats'; // Nombre de la tabla
    protected $fillable = ['idPedido', 'idUsuario', 'remitente', 'mensaje', 'estado'];
}
