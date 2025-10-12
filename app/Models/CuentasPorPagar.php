<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CuentasPorPagar extends Model
{
    use HasFactory;
    protected $table = 'cuentas_por_pagars';

    protected $fillable = [
        'idUsuario',
        'idProveedor',
        'nombreServicio',
        'fecha_inicio',
        'fecha_pagada',
        'cuotas',
        'monto',
        'monto_pagado',
        'descripcion',
        'comprobante',
        'estado',
    ];


    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario'); // Especifica el campo si no es 'user_id'
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedore::class, 'idProveedor');
    }

    public function cuotasPagar()
    {
        return $this->hasMany(CuotasPorPagar::class, 'idCuentaPorPagar');
    }
}
