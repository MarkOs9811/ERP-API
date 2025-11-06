<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use App\Models\Scopes\UsuarioScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notificaciones extends Model
{
    use HasFactory;
    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
        static::addGlobalScope(new SedeScope);
        static::addGlobalScope(new UsuarioScope);

        static::creating(function ($notificaciones) {
            $user = auth()->user();

            if ($user) {
                if (empty($notificaciones->idSede)) {
                    $notificaciones->idSede = $user->idSede;
                }

                if (empty($notificaciones->idEmpresa)) {
                    $notificaciones->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
