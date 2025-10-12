<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CuentasPorCobrar extends Model
{
    use HasFactory;
    protected $fillable = [
        'idCuentaContable',
        'idCliente',
        'idUsuario',
        'nombreTransaccion',
        'fecha_inicio',
        'fecha_fin',
        'cuotas',
        'monto',
        'monto_pagado',
        'descripcion',
        'comprobante',
        'estado'
    ];


    public function cuentaContable()
    {
        return $this->belongsTo(CuentasContables::class);
    }

    public function cuotasProgramadas()
    {
        return $this->hasMany(Cuota::class, 'cuenta_por_cobrar_id');
    }
    public function usuario()
    {
        return $this->belongsTo(User::class);
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'idCliente');
    }
}
