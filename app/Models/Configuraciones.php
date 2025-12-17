<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Configuraciones extends Model
{
    use HasFactory;

    protected $fillable = [
        'nombre',
        'idEmpresa',
        'tipo',
        'clave',
        'valor 1',
        'valor 2',
        'valor 3',
        'valor 4',
        'valor 5',
        'valor 6',
        'descripcion',
        'estado',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
    }
}
