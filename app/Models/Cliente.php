<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    use HasFactory;

    protected $fillable = [
        'idPersona', // Si es un cliente persona natural
        'idEmpresa', // Si es un cliente persona jurídica
        'estado',
        'created_at', // Si estás manejando timestamps
        'updated_at', // Si estás manejando timestamps
    ];

    public function persona()
    {
        return $this->belongsTo(Persona::class, 'idPersona');
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'idEmpresa');
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
}
