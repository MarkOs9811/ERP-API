<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    use HasFactory;

    protected $fillable = [
        'idPersona', // Si es un cliente persona natural
        'idTipoEmpresa', // Si es un cliente persona jurídica
        'estado',
        'idEmpresa', // <--- ¡Añade esto!
        'idSede',
        'created_at', // Si estás manejando timestamps
        'updated_at', // Si estás manejando timestamps
    ];

    public function persona()
    {
        return $this->belongsTo(Persona::class, 'idPersona');
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'idTipoEmpresa');
    }
    public function empresaRest()
    {
        return $this->belongsTo(MiEmpresa::class, 'idEmpresa');
    }
    public function sede()
    {
        return $this->belongsTo(Sede::class, 'idSede');
    }
    // Relación con Direcciones
    public function direcciones()
    {
        return $this->hasMany(Direccione::class, 'idCliente');
    }

    // Relación con Métodos de Pago
    public function metodosPago()
    {
        return $this->hasMany(MetodosPagoCliente::class, 'idCliente');
    }

    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
        static::addGlobalScope(new SedeScope);

        static::creating(function ($cliente) {
            $user = auth()->user();

            if ($user) {
                if (empty($cliente->idSede)) {
                    $cliente->idSede = $user->idSede;
                }

                if (empty($cliente->idEmpresa)) {
                    $cliente->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
