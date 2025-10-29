<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use App\Traits\FiltraPorPeriodoDePago;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HoraExtras extends Model
{
    use HasFactory, FiltraPorPeriodoDePago;

    protected $fillable = [
        'idUsuario',
        'idEmpresa',
        'idSede',
        'idPeriodo',
        'fecha',
        'horas_trabajadas',
        'estado',
    ];
    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario');
    }
    public function sede()
    {
        return $this->belongsTo(Sede::class, 'idSede');
    }

    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
        static::addGlobalScope(new SedeScope);

        // 3. ESTA LÓGICA AHORA ES CORRECTA
        static::creating(function ($horasExtras) {
            $user = auth()->user();

            if ($user) {
                // Esto está bien
                if (empty($horasExtras->idSede)) {
                    $horasExtras->idSede = $user->idSede;
                }
                if (empty($horasExtras->idEmpresa)) {
                    $horasExtras->idEmpresa = $user->idEmpresa;
                }

                // --- LÓGICA CORREGIDA PARA EL PERIODO ---
                if (empty($horasExtras->idPeriodo)) {

                    // 4. Buscamos el periodo Abierto (1)
                    // que coincida con la empresa y sede del usuario
                    $periodoActivo = PeriodoNomina::where('estado', 1)
                        ->where('idEmpresa', $user->idEmpresa)
                        ->where('idSede', $user->idSede)
                        ->first(); // Solo debe haber uno

                    // 5. Verificamos si encontramos uno
                    if ($periodoActivo) {
                        // ¡Éxito! Asignamos el ID
                        $horasExtras->idPeriodo = $periodoActivo->id;
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
