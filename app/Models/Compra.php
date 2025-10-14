<?php

namespace App\Models;

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
}
