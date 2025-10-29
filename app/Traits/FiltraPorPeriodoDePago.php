<?php

namespace App\Traits;

use App\Models\PeriodoNomina; // Importa tu modelo de Periodos

trait FiltraPorPeriodoDePago
{

    public function scopeDelPeriodoDePago($query)
    {
        $periodoDePago = PeriodoNomina::whereIn('estado', [1, 2]) // Busca Abierto O En Validación
            ->orderBy('estado', 'DESC') // Prioriza el '2' sobre el '1'
            ->first();

        // 2. Aplicamos el filtro
        if ($periodoDePago) {
            // Si encontramos un periodo (ej. Octubre), filtramos
            // por idPeriodo = 1
            return $query->where('idPeriodo', $periodoDePago->id);
        }

        // 3. Si no hay periodo (raro, pero seguro), no devolvemos nada.
        return $query->where('idPeriodo', -1); // Devuelve consulta vacía
    }
}
