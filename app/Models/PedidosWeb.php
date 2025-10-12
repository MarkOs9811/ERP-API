<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PedidosWeb extends Model
{
    use HasFactory;
    protected $table = 'pedidos_webs'; // Nombre de la tabla
    protected $fillable = ['cliente', 'nombre', 'mensaje', 'estado'];
}
