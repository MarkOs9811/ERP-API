<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait SedeValidation
{

    public function uniqueSede(string $table, string $column, $ignoreId = null, array $extraWheres = []): Unique
    {
        $user = Auth::user();
        if (!isset($user->idSede)) {
            throw new \Exception("El usuario autenticado no tiene una propiedad 'idSede'.");
        }
        $rule = Rule::unique($table, $column)
            ->where(function ($query) use ($user) {

                $query->where('idSede', $user->idSede);
            });


        foreach ($extraWheres as $extraColumn => $value) {
            if (!empty($value)) {
                $rule->where($extraColumn, $value);
            }
        }
        if ($ignoreId) {
            $rule->ignore($ignoreId);
        }

        return $rule;
    }
}
