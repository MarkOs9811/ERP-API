<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LibroMayor extends Model
{
    use HasFactory;
    protected $fillable = [
        'idCuentaContable',
        'idUsuario',
        'nombreTransaccion',
        'fecha',
        'descripcion',
        'debe',
        'haber',
    ];
    public function cuenta()
    {
        return $this->belongsTo(cuentasContables::class, 'idCuentaContable');
    }
}
