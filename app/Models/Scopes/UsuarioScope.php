<?php

namespace App\Models\Scopes;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class UsuarioScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user && $user->id) {
            // 1. Si el que está logueado es un CLIENTE
            // (Asegúrate de poner el namespace correcto de tu modelo Cliente)
            if ($user instanceof Cliente) {
                $builder->where($model->getTable() . '.idCliente', $user->id);
            }
            // 2. Si el que está logueado es un USUARIO DEL SISTEMA
            else {
                $builder->where($model->getTable() . '.idUsuario', $user->id);
            }
        }
    }
}
