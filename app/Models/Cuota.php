<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cuota extends Model
{
    use HasFactory;
    protected $fillable = [
        'cuenta_por_cobrar_id',
        'fecha_pago',
        'monto',
        'estado'
    ];

    public function cuentaPorCobrar()
    {
        return $this->belongsTo(cuentasPorCobrar::class);
    }
}
