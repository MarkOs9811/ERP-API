<?php

namespace App\Imports;

use App\Models\Almacen;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class AlmacenImport implements ToCollection, WithHeadingRow
{
    // Array público para almacenar el reporte detallado
    public array $resultados = [
        'subidos' => [],
        'no_subidos' => []
    ];

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            if (empty($row['nombre'])) {
                continue;
            }

            $nombreExcel = trim($row['nombre']);
            $laboratorioExcel = trim($row['laboratorio'] ?? 'Genérico');
            $presentacionExcel = trim($row['presentacion'] ?? 'N/A');
            $precioRaw = $row['precio'] ?? 0;
            $precioLimpio = floatval(preg_replace('/[^0-9.]/', '', str_replace(',', '.', $precioRaw)));

            // Validación estricta: Nombre + Laboratorio + Presentación
            $existe = Almacen::whereRaw('LOWER(TRIM(nombre)) = ?', [strtolower($nombreExcel)])
                ->whereRaw('LOWER(TRIM(laboratorio)) = ?', [strtolower($laboratorioExcel)])
                ->whereRaw('LOWER(TRIM(presentacion)) = ?', [strtolower($presentacionExcel)])
                ->exists();

            if ($existe) {
                // Lo guardamos en la lista de NO subidos (duplicados)
                $this->resultados['no_subidos'][] = [
                    'nombre' => $nombreExcel,
                    'laboratorio' => $laboratorioExcel,
                    'presentacion' => $presentacionExcel,
                    'motivo' => 'Ya existe en el almacén'
                ];
            } else {
                $ultimoProducto = Almacen::latest('id')->first();
                $siguienteId = $ultimoProducto ? $ultimoProducto->id + 1 : 1;
                $codigoGenerado = 'PROD-' . str_pad($siguienteId, 5, '0', STR_PAD_LEFT);

                Almacen::create([
                    'codigoProd'   => $codigoGenerado,
                    'nombre'       => $nombreExcel,
                    'laboratorio'  => $laboratorioExcel,
                    'presentacion' => $presentacionExcel,
                    'precioUnit'   => $precioLimpio,
                    'cantidad'     => 0,
                    'estado'       => 1,
                ]);

                // Lo guardamos en la lista de subidos con éxito
                $this->resultados['subidos'][] = [
                    'nombre' => $nombreExcel,
                    'laboratorio' => $laboratorioExcel,
                    'presentacion' => $presentacionExcel,
                    'codigo' => $codigoGenerado
                ];
            }
        }
    }
}
