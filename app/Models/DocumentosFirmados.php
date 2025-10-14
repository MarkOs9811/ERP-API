<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentosFirmados extends Model
{
    use HasFactory;

    protected $fillable = [
        'idUsuario',
        'nombre_archivo',
        'ruta_archivo',
    ];
    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario');
    }
}
