<?php

namespace App\Models;
use App\Models\Scopes\SedeScope;
use App\Models\Scopes\EmpresaScope;
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
