<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromocionesApp extends Model
{
    use HasFactory;
    public function plato()
    {
        return $this->belongsTo(Plato::class, 'idPlato', 'id');
    }

    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
        static::addGlobalScope(new SedeScope);

        static::creating(function ($promociones) {
            $user = auth()->user();

            if ($user) {
                if (empty($promociones->idSede)) {
                    $promociones->idSede = $user->idSede;
                }

                if (empty($promociones->idEmpresa)) {
                    $promociones->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
