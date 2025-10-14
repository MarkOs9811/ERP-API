<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Solicitud extends Model
{
    use HasFactory;

    // Nombre de la tabla en la base de datos
    protected $table = 'solicitudes';

    // Los atributos que se pueden asignar de manera masiva
    protected $fillable = [
        'nombre_solicitante',
        'idArea',
        'correo_electronico',
        'telefono',
        'nombre_producto',
        'marcaProd',
        'descripcion',
        'cantidad',
        'idUnidadMedida',
        'idCategoria',
        'precio_estimado',
        'motivo',
        'uso_previsto',
        'prioridad',
        'estado',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuarioOrigen', 'id');
    }
    public function area()
    {
        return $this->belongsTo(Area::class, 'idArea', 'id');
    }
    public function unidad()
    {
        return $this->belongsTo(UnidadMedida::class, 'idUnidadMedida', 'id');
    }
    public function categoria()
    {
        return $this->belongsTo(Categoria::class, 'idCategoria', 'id');
    }
}
