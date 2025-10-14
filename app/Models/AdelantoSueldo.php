<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdelantoSueldo extends Model
{
    use HasFactory;
    protected $fillable = [
        'idUsuario',
        'fecha',
        'monto',
        'descripcion',
        'justificacion',
        'estado'
    ];
    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario');
    }
}
