<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Movimiento extends Model
{
    use HasFactory;
    protected $fillable = [
        'idProductoAlmacen', // Añade cualquier otro campo que necesites aquí
        'idAreaOrigen',
        'idAreaDestino',
        'idUsuario',
        'tipoMovimiento',
        'cantidad',
        'fecha_movimiento',
        'documento',
    ];
    protected $appends = ['kardex_url'];

    /**
     * Genera el campo virtual 'kardex_url'
     */
    public function getKardexUrlAttribute()
    {
        if ($this->documento) {
            return Storage::disk('s3')->url($this->documento);
        }

        return null;
    }
    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario', 'id');
    }
    public function areaOrigen()
    {
        return $this->belongsTo(Area::class, 'idAreaOrigen', 'id');
    }
    public function areaDestino()
    {
        return $this->belongsTo(Area::class, 'idAreaDestino', 'id');
    }
    public function producto()
    {
        return $this->belongsTo(Almacen::class, 'idProductoAlmacen', 'id');
    }
}
