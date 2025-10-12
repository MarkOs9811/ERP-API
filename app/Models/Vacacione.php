<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vacacione extends Model
{
    use HasFactory;
    protected $fillable = [
        'idUsuario',
        'fecha_inicio',
        'fecha_fin',
        'dias_totales',
        'dias_utilizados',
        'dias_vendidos',
        'observaciones',
        'estado',
    ];
    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario');
    }
}
