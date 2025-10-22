<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
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

    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);

        static::creating(function ($cuota) {
            $user = auth()->user();

            if ($user) {

                if (empty($cuota->idEmpresa)) {
                    $cuota->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
