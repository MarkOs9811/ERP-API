<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

trait EmpresaSedeValidation
{
    public function uniqueEmpresaSede(string $table, string $column, $ignoreId = null)
    {
        $user = Auth::user();
        return Rule::unique($table, $column)
            ->where(function ($query) use ($user) {
                $query->where('idEmpresa', $user->idEmpresa)
                    ->where('idSede', $user->idSede);
            })
            ->ignore($ignoreId);
    }
}
