<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegistrosEjercicios extends Model
{
    use HasFactory;



    // AQUÍ ESTABA EL ERROR: Debes autorizar los campos para escritura masiva
    protected $fillable = [
        'idEmpresa',
        'idUsuario',
        'temporada',
        'fechaInicio',
        'fechaFin',
        'ingresos',
        'gastos',
    ];
    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);

        static::creating(function ($registroEjercicio) {
            $user = auth()->user();

            if ($user) {
                if (empty($registroEjercicio->idEmpresa)) {
                    $registroEjercicio->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
