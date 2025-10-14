<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Kardex extends Model
{
    use HasFactory;
    protected $fillable = [
        'idProducto', // Añade cualquier otro campo que necesites aquí
        'idUsuario',
        'cantidad',
        'tipo_movimiento',
        'descripcion',
        'stock_anterior',
        'stock_actual',
        'fecha_movimiento',
        'documento',
    ];
    protected $dates = ['fecha_movimiento'];

    public function producto()
    {
        return $this->belongsTo(Almacen::class, 'idProducto', 'id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario', 'id');
    }
}
