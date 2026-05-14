<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    use HasFactory;
    protected $fillable = [
        'idVenta',
        'comentario',
        'categoria',
        'calificacion',
    ];

    public function venta()
    {
        return $this->belongsTo(Venta::class, 'idVenta');
    }
    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
        static::addGlobalScope(new SedeScope);

        static::creating(function ($feedback) {
            $user = auth()->user();

            if ($user) {
                if (empty($feedback->idSede)) {
                    $feedback->idSede = $user->idSede;
                }

                if (empty($feedback->idEmpresa)) {
                    $feedback->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
