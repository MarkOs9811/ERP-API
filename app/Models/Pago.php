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
                // --- LÓGICA CORREGIDA PARA EL PERIODO ---
                if (empty($pago->idPeriodo)) {

                    // 4. Buscamos el periodo Abierto (1)
                    // que coincida con la empresa y sede del usuario
                    $periodoActivo = PeriodoNomina::where('estado', 1)
                        ->where('idEmpresa', $user->idEmpresa)
                        ->where('idSede', $user->idSede)
                        ->first(); // Solo debe haber uno

                    // 5. Verificamos si encontramos uno
                    if ($periodoActivo) {
                        // ¡Éxito! Asignamos el ID
                        $pago->idPeriodo = $periodoActivo->id;
                    } else {

                        throw new \Exception('No se encontró ningún periodo Abierto. No se puede registrar el adelanto.');
                    }
                }
            }
        });
    }
}
