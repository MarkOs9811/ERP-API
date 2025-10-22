<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Compra extends Model
{
    use HasFactory;
    protected $fillable = [
        'idUsuario',
        'idProveedor',
        'fecha_compra',
        'totalPagado',
        'numero_compra',
        'document_path',
        'observaciones',
        'estado'
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario', 'id');
    }
    public function proveedor()
    {
        return $this->belongsTo(Proveedore::class, 'idProveedor', 'id');
    }
    public function cuentaPorPagar()
    {
        return $this->belongsTo(CuentasPorPagar::class, 'idCuentaPorPagar', 'id');
    }
    protected static function booted()
    {

        static::addGlobalScope(new EmpresaScope);
        static::addGlobalScope(new SedeScope);
        static::creating(function ($compra) {
            $user = auth()->user();

            if ($user) {
                if (empty($compra->idSede)) {
                    $compra->idSede = $user->idSede;
                }
                if (empty($compra->idEmpresa)) {
                    $compra->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
