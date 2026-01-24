<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PedidosWebRegistro extends Model
{
    use HasFactory;
    protected $fillable = [
        'codigo_pedido',
        'idCliente',
        'fotoComprobante',
        'nombre_cliente',
        'numero_cliente',
        'latitud',
        'longitud',
        'tipo_entrega',
        'pedido_temporal',
        'estado_pedido',
        'estado_pago',
        'estado'
    ];
    const ESTADOS_PEDIDO = [
        1 => 'Pendiente - Sin confirmar pedido',
        2 => 'Pendiente - Sin confirmar pago',
        3 => 'Pendiente - Verificación de pago',
        4 => 'En preparación',
        5 => 'Listo para recoger',
        6 => 'Entregado y pagado',
        7 => 'Cancelado',
        8 => 'Esperando cantidad'
    ];

    // Para obtener el nombre del estado en una consulta:
    public function getEstadoPedidoTextoAttribute()
    {
        return self::ESTADOS_PEDIDO[$this->estado_pedido] ?? 'Desconocido';
    }

    public function detallesPedido()
    {
        return $this->hasMany(DetallePedidosWeb::class, 'idPedido');
    }
}
