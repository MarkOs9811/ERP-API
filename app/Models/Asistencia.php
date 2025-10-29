<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use App\Traits\FiltraPorPeriodoDePago;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asistencia extends Model
{
    use HasFactory;
    use FiltraPorPeriodoDePago;

    protected $fillable = [
        'codigoUsuario',
        'fechaEntrada',
        'horaEntrada',
        'fechaSalida',
        'horaSalida',
        'horasTrabajadas',
        'estadoAsistencia',
        'estado',
        'idEmpresa', // <-- AÑADIDO
        'idSede',    // <-- AÑADIDO
        'idPeriodo'  // <-- AÑADIDO
    ];

    public function empleado()
    {
        return $this->belongsTo(Persona::class, 'codigoUsuario', 'documento_identidad');
    }
    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
        static::addGlobalScope(new SedeScope);

        // 3. ESTA LÓGICA AHORA ES CORRECTA
        static::creating(function ($asistencia) {
            $user = auth()->user();

            if ($user) {
                // Esto está bien
                if (empty($asistencia->idSede)) {
                    $asistencia->idSede = $user->idSede;
                }
                if (empty($asistencia->idEmpresa)) {
                    $asistencia->idEmpresa = $user->idEmpresa;
                }

                // --- LÓGICA CORREGIDA PARA EL PERIODO ---
                if (empty($asistencia->idPeriodo)) {

                    // 4. Buscamos el periodo Abierto (1)
                    // que coincida con la empresa y sede del usuario
                    $periodoActivo = PeriodoNomina::where('estado', 1)
                        ->where('idEmpresa', $user->idEmpresa)
                        ->where('idSede', $user->idSede)
                        ->first(); // Solo debe haber uno

                    // 5. Verificamos si encontramos uno
                    if ($periodoActivo) {
                        // ¡Éxito! Asignamos el ID
                        $asistencia->idPeriodo = $periodoActivo->id;
                    } else {

                        throw new \Exception('No se encontró ningún periodo Abierto. No se puede registrar el adelanto.');
                    }
                }
            }
        });
    }
}
