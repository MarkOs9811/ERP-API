<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inventario extends Model
{
    use HasFactory;
    protected $fillable = [
        'idCategoria',
        'idUnidad',
        'codigoProd',
        'nombre',
        'laboratorio',
        'marca',
        'presentacion',
        'registro_sanitario',
        'lote',
        'descripcion',
        'stock',
        'precio',
        'fecha_vencimiento',
        'foto',
        'estado',
    ];

    public function categoria()
    {
        return $this->belongsTo(Categoria::class, 'idCategoria', 'id');
    }

    public function unidad()
    {
        return $this->belongsTo(UnidadMedida::class, 'idUnidad', 'id');
    }
}
