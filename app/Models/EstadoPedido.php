<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EstadoPedido extends Model
{
    use HasFactory;
    protected $table = 'estado_pedidos';

    protected $fillable = [
        'tipo_pedido',
        'idCaja',
        'detalle_platos',
        'idPedidoMesa',
        'idPedidoLlevar',
        'idPedidoWsp',
        'detalle_cliente',
        'numeroMesa',
        'estado',
    ];

    // Relación con preventa_mesas
    public function preventaMesa()
    {
        return $this->belongsTo(PedidoMesaRegistro::class, 'idPedidoMesa');
    }

    // Relación con pedidos (para llevar)
    public function pedidoLlevar()
    {
        return $this->belongsTo(Pedido::class, 'idPedidoLlevar');
    }
    public function caja()
    {
        return $this->belongsTo(Caja::class, 'idCaja');
    }
    // Relación con pedidos_web_registros
    public function pedidoWeb()
    {
        return $this->belongsTo(PedidosWebRegistro::class, 'idPedidoWsp');
    }

    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
        static::addGlobalScope(new SedeScope);

        static::creating(function ($pedido) {
            $user = auth()->user();

            if ($user) {
                if (empty($pedido->idSede)) {
                    $pedido->idSede = $user->idSede;
                }

                if (empty($pedido->idEmpresa)) {
                    $pedido->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
