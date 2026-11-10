<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Inventario</title>
    <style>
        /* Configuración de página vertical A4 */
        @page {
            size: a4 portrait;
            margin: 12mm;
        }

        body {
            font-family: sans-serif;
            font-size: 7.5pt;
            color: #222;
        }

        /* Cabecera */
        .header-table {
            width: 100%;
            border-bottom: 2px solid #272b31;
            margin-bottom: 8px;
            padding-bottom: 4px;
        }
        .header-table td {
            border: none;
            padding: 0;
        }

        /* Resumen informativo */
        .resumen-table {
            width: 100%;
            margin-bottom: 10px;
            border-collapse: collapse;
        }
        .resumen-table td {
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 5px;
            text-align: center;
            font-size: 8.5pt;
        }

        /* Tabla Principal */
        .tabla-datos {
            width: 100%;
            border-collapse: collapse;
        }
        .tabla-datos th {
            background-color: #1e293b;
            color: #ffffff;
            padding: 4px 3px;
            border: 1px solid #1e293b;
            font-size: 7.5pt;
            text-transform: uppercase;
        }
        .tabla-datos td {
            padding: 4px 3px;
            border: 1px solid #cbd5e1;
            font-size: 7.5pt;
            word-wrap: break-word;
        }

        /* Utilidades sencillas para DomPDF */
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bg-zebra { background-color: #f8fafc; }
        .stock-critico { 
            background-color: #fee2e2; 
            color: #991b1b; 
            font-weight: bold; 
        }
    </style>
</head>
<body>

    <!-- Cabecera simple -->
    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                <h2 style="margin: 0; color: #2e2e2e; font-size: 13pt;">REPORTE GENERAL DE INVENTARIO</h2>
                <span style="color: #64748b; font-size: 8pt;">Listado de Productos y Estado de Stock</span>
            </td>
            <td style="width: 40%; text-align: right; font-size: 8pt; color: #475569;">
                <strong>Emisión:</strong> {{ date('d/m/Y H:i') }}<br>
                <strong>Total Registros:</strong> {{ count($productos) }} productos
            </td>
        </tr>
    </table>

    @php
        // Cálculos en la vista
        $totalStock = $productos->sum('stock');
    @endphp

    <!-- Cuadro con información / Métricas útiles -->
    <table class="resumen-table">
        <tr>
            <td><strong>Productos en Catálogo:</strong> {{ count($productos) }}</td>
            <td><strong>Total Unidades (Stock):</strong> {{ number_format($totalStock) }}</td>
        </tr>
    </table>

    <!-- Tabla completa adaptada a Vertical sin columna Total -->
    <table class="tabla-datos">
        <thead>
            <tr>
                <th width="4%">#</th>
                <th width="10%">Código</th>
                <th width="20%">Producto</th>
                <th width="10%">Marca</th>
                <th width="14%">Presentación / Lab.</th>
                <th width="9%">Lote</th>
                <th width="12%">Reg. Sanitario</th>
                <th width="9%" class="text-center">F. Venc.</th>
                <th width="6%" class="text-right">Stock</th>
                <th width="6%" class="text-right">Precio</th>
            </tr>
        </thead>
        <tbody>
            @forelse($productos as $index => $item)
                @php
                    $esBajoStock = $item->stock <= 5;
                @endphp
                <tr class="{{ $index % 2 == 1 ? 'bg-zebra' : '' }}">
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td><strong>{{ $item->codigoProd }}</strong></td>
                    <td>{{ $item->nombre }}</td>
                    <td>{{ $item->marca ?? '-' }}</td>
                    <td>
                        {{ $item->presentacion ?? '-' }}
                        @if($item->laboratorio)
                            <br><span style="color: #64748b;">({{ $item->laboratorio }})</span>
                        @endif
                    </td>
                    <td class="text-center">{{ $item->lote ?? '-' }}</td>
                    <td class="text-center">{{ $item->registro_sanitario ?? '-' }}</td>
                    <td class="text-center">
                        {{ $item->fecha_vencimiento ? date('d/m/Y', strtotime($item->fecha_vencimiento)) : '-' }}
                    </td>
                    <td class="text-right {{ $esBajoStock ? 'stock-critico' : '' }}">
                        {{ $item->stock }}
                    </td>
                    <td class="text-right">S/ {{ number_format($item->precio, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="text-center" style="padding: 10px;">
                        No hay productos registrados en el inventario.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

</body>
</html>