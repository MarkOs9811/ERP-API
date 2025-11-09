<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use App\Traits\FiltraPorPeriodoDePago;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vacacione extends Model
{

    use HasFactory, FiltraPorPeriodoDePago;
    protected $fillable = [
        'idUsuario',
        'idEmpresa',
        'idSede',
        'idPeriodo',
        'fecha_inicio',
        'fecha_fin',
        'dias_totales',
        'dias_utilizados',
        'dias_vendidos',
        'observaciones',
        'estado',
    ];
    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario');
    }

    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
        static::addGlobalScope(new SedeScope);

        // 3. ESTA LÓGICA AHORA ES CORRECTA
        static::creating(function ($vacaciones) {
            $user = auth()->user();

            if ($user) {
                // Esto está bien
                if (empty($vacaciones->idSede)) {
                    $vacaciones->idSede = $user->idSede;
                }
                if (empty($vacaciones->idEmpresa)) {
                    $vacaciones->idEmpresa = $user->idEmpresa;
                }

                // --- LÓGICA CORREGIDA PARA EL PERIODO ---
                if (empty($vacaciones->idPeriodo)) {

                    // 4. Buscamos el periodo Abierto (1)
                    // que coincida con la empresa y sede del usuario
                    $periodoActivo = PeriodoNomina::where('estado', 1)
                        ->where('idEmpresa', $user->idEmpresa)
                        ->where('idSede', $user->idSede)
                        ->first(); // Solo debe haber uno

                    // 5. Verificamos si encontramos uno
                    if ($periodoActivo) {
                        // ¡Éxito! Asignamos el ID
                        $vacaciones->idPeriodo = $periodoActivo->id;
                    } else {

                        throw new \Exception('No se encontró ningún periodo Abierto. No se puede registrar el adelanto.');
                    }
                }
            }
        });
    }
}
