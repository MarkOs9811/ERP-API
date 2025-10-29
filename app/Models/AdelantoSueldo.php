<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
// 1. IMPORTAMOS EL MODELO DE PERIODO
use App\Models\PeriodoNomina;
use App\Traits\FiltraPorPeriodoDePago;

class AdelantoSueldo extends Model
{
    use HasFactory, FiltraPorPeriodoDePago;

    // 2. ASEGÚRATE DE AÑADIR 'idPeriodo' AL $fillable
    protected $fillable = [
        'idUsuario',
        'idEmpresa',
        'idSede',
        'idPeriodo',
        'fecha',
        'monto',
        'descripcion',
        'justificacion',
        'estado',
        'idEmpresa', // (Asumo que también están aquí)
        'idSede',    // (Asumo que también están aquí)
        'idPeriodo'  // <-- AÑADIDO
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
        static::creating(function ($adelantoSueldo) {
            $user = auth()->user();

            if ($user) {
                // Esto está bien
                if (empty($adelantoSueldo->idSede)) {
                    $adelantoSueldo->idSede = $user->idSede;
                }
                if (empty($adelantoSueldo->idEmpresa)) {
                    $adelantoSueldo->idEmpresa = $user->idEmpresa;
                }

                // --- LÓGICA CORREGIDA PARA EL PERIODO ---
                if (empty($adelantoSueldo->idPeriodo)) {

                    // 4. Buscamos el periodo Abierto (1)
                    // que coincida con la empresa y sede del usuario
                    $periodoActivo = PeriodoNomina::where('estado', 1)
                        ->where('idEmpresa', $user->idEmpresa)
                        ->where('idSede', $user->idSede)
                        ->first(); // Solo debe haber uno

                    // 5. Verificamos si encontramos uno
                    if ($periodoActivo) {
                        // ¡Éxito! Asignamos el ID
                        $adelantoSueldo->idPeriodo = $periodoActivo->id;
                    } else {
                        // 6. ¡ERROR GRAVE!
                        // No hay ningún periodo abierto. No podemos guardar esto.
                        // Lanzamos una excepción para detener el guardado
                        // y que el frontend reciba un error.
                        throw new \Exception('No se encontró ningún periodo Abierto. No se puede registrar el adelanto.');
                    }
                }
            }
        });
    }
}
