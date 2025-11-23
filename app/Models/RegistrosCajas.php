<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegistrosCajas extends Model
{
    use HasFactory;
    protected $fillable = [
        'idUsuario',
        'idCaja',
        'montoInicial',
        'montoFinal',
        'montoDejado',
        'fechaApertura',
        'horaApertura',
        'fechaCierre',
        'horaCierre',
        'estado',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario', 'id');
    }

    public function caja()
    {
        return $this->belongsTo(Caja::class, 'idCaja', 'id');
    }
    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);


        static::creating(function ($venta) {
            $user = auth()->user();

            if ($user) {

                if (empty($venta->idEmpresa)) {
                    $venta->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
