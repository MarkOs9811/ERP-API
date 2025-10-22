<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{
    use HasFactory;

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }
    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);

        static::creating(function ($pago) {
            $user = auth()->user();

            if ($user) {

                if (empty($pago->idEmpresa)) {
                    $pago->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
