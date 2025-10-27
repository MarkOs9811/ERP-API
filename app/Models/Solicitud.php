<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
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
        'idSede',
        'proveedor',
        'tipo',
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
    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
        static::addGlobalScope(new SedeScope);
        static::creating(function ($venta) {
            $user = auth()->user();

            if ($user) {
                if (empty($venta->idSede)) {
                    $venta->idSede = $user->idSede;
                }
                if (empty($venta->idEmpresa)) {
                    $venta->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
