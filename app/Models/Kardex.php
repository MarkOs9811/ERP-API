<?php

namespace App\Models;
use App\Models\Scopes\SedeScope;
use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Kardex extends Model
{
    use HasFactory;
    protected $fillable = [
        'idProducto', // Añade cualquier otro campo que necesites aquí
        'idUsuario',
        'cantidad',
        'tipo_movimiento',
        'descripcion',
        'stock_anterior',
        'stock_actual',
        'fecha_movimiento',
        'documento',
    ];
    protected $dates = ['fecha_movimiento'];

    public function producto()
    {
        return $this->belongsTo(Almacen::class, 'idProducto', 'id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario', 'id');
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
