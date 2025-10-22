<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CuotasPorPagar extends Model
{
    use HasFactory;
    protected $fillable = [
        'cuenta_por_cobrar_id',
        'fecha_pago',
        'monto',
        'estado'
    ];

    public function cuentasPorPagar()
    {
        return $this->belongsTo(CuentasPorPagar::class);
    }
}
