<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LibroDiario extends Model
{
    use HasFactory;
    protected $fillable = [
        'fecha',
        'idUsuario',
        'estado',
    ];

    // Relación con DetalleTransaccion
    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario');
    }

    // Relación con DetalleLibro
    public function detalles()
    {
        return $this->hasMany(DetalleLibro::class, 'idLibroDiario');
    }
}
