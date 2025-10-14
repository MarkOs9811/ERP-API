<?php

namespace App\Models;

use App\Models\Scopes\SedeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plato extends Model
{
    use HasFactory;

    protected $fillable = [
        'nombre',
        'descripcion',
        'precio',
        'idCategoria', // Relación personalizada
        'foto',
        'estado',
    ];

    /**
     * Relación con la categoría de platos.
     */
    public function categoria()
    {
        return $this->belongsTo(CategoriaPlato::class, 'idCategoria');
    }


    // protected static function booted()
    // {
    //     static::addGlobalScope(new SedeScope);
    // }
}
