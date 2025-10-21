<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use App\Scopes\EmpresaSedeScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Caja extends Model
{
    use HasFactory;
    public function sedes()
    {
        return $this->belongsTo(Sede::class, 'idSede');
    }
    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
        static::addGlobalScope(new SedeScope);

        static::creating(function ($caja) {
            $user = auth()->user();

            if ($user) {
                if (empty($caja->idSede)) {
                    $caja->idSede = $user->idSede;
                }

                if (empty($caja->idEmpresa)) {
                    $caja->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
