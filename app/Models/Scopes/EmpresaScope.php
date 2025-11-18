<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class EmpresaScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();
        if (!$user) {
            return;
        }
        if ($user->isAdmin == 1) {
            return;
        }
        if ($user->idEmpresa) {
            $builder->where($model->getTable() . '.idEmpresa', $user->idEmpresa);
        }
    }
}
