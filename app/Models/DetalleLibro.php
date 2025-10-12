<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetalleLibro extends Model
{
    use HasFactory;

    protected $fillable = [
        'idLibroDiario',
        'idCuenta',
        'tipo',
        'monto',
        'estado',
    ];

    public function cuenta()
    {
        return $this->belongsTo(CuentasContables::class, 'idCuenta');
    }

    public function libroDiario()
    {
        return $this->belongsTo(libroDiario::class, 'idLibroDiario');
    }
}
