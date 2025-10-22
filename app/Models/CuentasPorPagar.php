<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
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
    protected static function booted()
    {

        static::addGlobalScope(new EmpresaScope);
        static::addGlobalScope(new SedeScope);
        static::creating(function ($cuotasPagar) {
            $user = auth()->user();

            if ($user) {
                if (empty($cuotasPagar->idSede)) {
                    $cuotasPagar->idSede = $user->idSede;
                }
                if (empty($cuotasPagar->idEmpresa)) {
                    $cuotasPagar->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
