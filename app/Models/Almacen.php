<?php

namespace App\Models;

use App\Models\Scopes\SedeScope;
use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Almacen extends Model
{
    use HasFactory;
    protected $fillable = [
        'nombre',
        'cantidad',
        'presentacion',
        'fecha_vencimiento',
        'laboratorio',
        'descripcion',
        'lote',
        'registro_sanitario',
        'idSede',
        'idCategoria',
        'idUnidadMedida',
        'idProveedor',
        'precioUnit',
        'codigoProd'
    ];
    public function unidad()
    {
        return $this->belongsTo(UnidadMedida::class, 'idUnidadMedida', 'id');
    }

    public function categoria()
    {
        return $this->belongsTo(Categoria::class, 'idCategoria', 'id');
    }

    public function sede()
    {
        return $this->belongsTo(Sede::class, 'idSede');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedore::class, 'idProveedor', 'id');
    }
    protected static function booted()
    {
        static::addGlobalScope(new SedeScope);
        static::addGlobalScope(new EmpresaScope);
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
