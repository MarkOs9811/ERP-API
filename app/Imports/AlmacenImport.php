<?php

namespace App\Imports;

use App\Models\Almacen;
use App\Models\Inventario;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class AlmacenImport implements ToCollection, WithHeadingRow
{
    public array $resultados = [
        'subidos' => [],
        'no_subidos' => []
    ];

    protected bool $transferirInventario;

    public function __construct(bool $transferirInventario = false)
    {
        $this->transferirInventario = $transferirInventario;
    }

    public function collection(Collection $rows)
    {
        $procesadosEnArchivo = [];

        foreach ($rows as $row) {
            if (empty($row['nombre'])) {
                continue;
            }

            // Normalizamos datos y manejamos campos vacíos o nulos de forma segura
            $nombreExcel       = trim($row['nombre']);
            $laboratorioExcel  = !empty($row['laboratorio']) ? trim($row['laboratorio']) : 'N/A';
            $presentacionExcel = !empty($row['presentacion']) ? trim($row['presentacion']) : 'N/A';
            $marcaExcel        = !empty($row['marca']) ? trim($row['marca']) : 'N/A';



            // Detectar precio flexiblemente (soporta 'precio', 'precioUnit', 'precio_unit')
            $precioRaw = $row['precio'] ?? $row['preciounit'] ?? $row['precio_unit'] ?? 0;
            $precioLimpio = floatval(preg_replace('/[^0-9.]/', '', str_replace(',', '.', $precioRaw)));

            // Huella digital para evitar duplicados internos en el mismo Excel
            $huellaFila = strtolower($nombreExcel . '|' . $laboratorioExcel . '|' . $presentacionExcel . '|' . $marcaExcel);

            if (in_array($huellaFila, $procesadosEnArchivo)) {
                $this->resultados['no_subidos'][] = [
                    'nombre' => $nombreExcel,
                    'laboratorio' => $laboratorioExcel,
                    'presentacion' => $presentacionExcel,
                    'motivo' => 'Duplicado detectado dentro del mismo archivo Excel'
                ];
                continue;
            }

            // Validación robusta en DB usando COALESCE para atrapar nulos y vacíos correctamente
            $existeEnDb = Almacen::whereRaw('LOWER(TRIM(nombre)) = ?', [strtolower($nombreExcel)])
                ->whereRaw('LOWER(TRIM(COALESCE(NULLIF(laboratorio, ""), "N/A"))) = ?', [strtolower($laboratorioExcel)])
                ->whereRaw('LOWER(TRIM(COALESCE(NULLIF(presentacion, ""), "N/A"))) = ?', [strtolower($presentacionExcel)])
                ->whereRaw('LOWER(TRIM(COALESCE(NULLIF(marca, ""), "N/A"))) = ?', [strtolower($marcaExcel)])
                ->exists();

            if ($existeEnDb) {
                $this->resultados['no_subidos'][] = [
                    'nombre' => $nombreExcel,
                    'laboratorio' => $laboratorioExcel,
                    'presentacion' => $presentacionExcel,
                    'motivo' => 'Ya existe registrado en el almacén'
                ];
            } else {
                $procesadosEnArchivo[] = $huellaFila;

                // 1. Crear en Almacén con código temporal
                $nuevoAlmacen = Almacen::create([
                    'codigoProd'   => 'TEMP',
                    'nombre'       => $nombreExcel,
                    'laboratorio'  => $laboratorioExcel,
                    'presentacion' => $presentacionExcel,
                    'marca'        => $marcaExcel,
                    'precioUnit'   => $precioLimpio,
                    'cantidad'     => 0,
                    'estado'       => '1',
                ]);

                // Asignar código único basado en el ID real
                $codigoGenerado = 'PROD-' . str_pad($nuevoAlmacen->id, 5, '0', STR_PAD_LEFT);
                $nuevoAlmacen->update(['codigoProd' => $codigoGenerado]);

                // 2. Si el switch está activo, registrar también en Inventario
                if ($this->transferirInventario) {
                    $existeInventario = Inventario::where('codigoProd', $codigoGenerado)
                        ->orWhere(function ($query) use ($nombreExcel, $laboratorioExcel, $presentacionExcel, $marcaExcel) {
                            $query->whereRaw('LOWER(TRIM(nombre)) = ?', [strtolower($nombreExcel)])
                                ->whereRaw('LOWER(TRIM(COALESCE(NULLIF(laboratorio, ""), "N/A"))) = ?', [strtolower($laboratorioExcel)])
                                ->whereRaw('LOWER(TRIM(COALESCE(NULLIF(presentacion, ""), "N/A"))) = ?', [strtolower($presentacionExcel)])
                                ->whereRaw('LOWER(TRIM(COALESCE(NULLIF(marca, ""), "N/A"))) = ?', [strtolower($marcaExcel)]);
                        })
                        ->exists();

                    if (!$existeInventario) {
                        Inventario::create([
                            'codigoProd'   => $codigoGenerado,
                            'nombre'       => $nombreExcel,
                            'laboratorio'  => $laboratorioExcel,
                            'presentacion' => $presentacionExcel,
                            'marca'        => $marcaExcel,
                            'precio'       => $precioLimpio,
                            'stock'        => isset($row['stock']) ? intval($row['stock']) : 0,
                            'estado'       => '1',
                        ]);
                    }
                }

                $this->resultados['subidos'][] = [
                    'nombre' => $nombreExcel,
                    'laboratorio' => $laboratorioExcel,
                    'presentacion' => $presentacionExcel,
                    'marca' => $marcaExcel,
                    'codigo' => $codigoGenerado
                ];
            }
        }
    }
}
