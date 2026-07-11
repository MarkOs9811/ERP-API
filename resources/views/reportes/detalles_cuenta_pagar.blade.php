<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Detalle de Cuenta por Pagar</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #333;
            font-size: 14px;
        }

        .header {
            border-bottom: 2px solid #eaeaea;
            padding-bottom: 10px;
            margin-bottom: 20px;
            position: relative;
        }

        .title {
            font-size: 24px;
            font-weight: bold;
            margin: 0 0 5px 0;
        }

        .subtitle {
            font-size: 14px;
            color: #666;
            margin: 0;
        }

        .badge {
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .badge-pagado {
            background-color: #d1fae5;
            color: #059669;
        }

        .badge-pendiente {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .badge-status-container {
            position: absolute;
            top: 0;
            right: 0;
        }

        /* Tarjetas de Resumen */
        .summary-table {
            width: 100%;
            border-spacing: 10px;
            margin-left: -10px;
            margin-bottom: 20px;
        }

        .summary-card {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            text-align: left;
            width: 25%;
        }

        .card-title {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .card-value {
            font-size: 18px;
            font-weight: bold;
            color: #111827;
        }

        /* Tabla de Cuotas */
        .table-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .data-table th {
            background-color: #f3f4f6;
            color: #374151;
            font-size: 12px;
            font-weight: bold;
            text-align: left;
            padding: 10px;
            border-bottom: 2px solid #d1d5db;
        }

        .data-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .text-green {
            color: #059669;
            font-weight: bold;
        }

        .text-red {
            color: #b91c1c;
            font-weight: bold;
        }
    </style>
</head>

<body>

    @php
    $cuenta = $cuentas_por_pagar;

    // Buscamos el nombre directamente usando la columna correcta
    $nombreProveedor = 'Proveedor no registrado';
    if($cuenta->proveedor) {
    // Apuntamos directo al campo 'nombre' de tu tabla proveedores
    $nombreProveedor = $cuenta->proveedor->nombre ?? 'Proveedor sin nombre';
    }
    @endphp

    <div class="header">
        <h1 class="title">Detalle de Cuenta por Pagar</h1>
        <p class="subtitle">Proveedor: {{ $nombreProveedor }}</p>

        <div class="badge-status-container">
            <span class="badge {{ $cuenta->estado === 'pagado' ? 'badge-pagado' : 'badge-pendiente' }}">
                {{ $cuenta->estado }}
            </span>
        </div>
    </div>

    <table class="summary-table">
        <tr>
            <td class="summary-card">
                <div class="card-title">📄 Monto Total</div>
                <div class="card-value">S/. {{ number_format($cuenta->monto, 2) }}</div>
            </td>
            <td class="summary-card">
                <div class="card-title">✔️ Monto Pagado</div>
                <div class="card-value">S/. {{ number_format($cuenta->monto_pagado, 2) }}</div>
            </td>
            <td class="summary-card">
                <div class="card-title">📅 Fecha Límite de Pago</div>
                <div class="card-value">{{ $cuenta->fecha_pago ? $cuenta->fecha_pago : 'N/A' }}</div>
            </td>
            <td class="summary-card">
                <div class="card-title">✅ Fecha Pagada</div>
                <div class="card-value">{{ $cuenta->fecha_pagado ? $cuenta->fecha_pagado : 'Pendiente' }}</div>
            </td>
        </tr>
    </table>

    <table class="summary-table" style="width: 75%;">
        <tr>
            <td class="summary-card">
                <div class="card-title">🗂️ Cuotas Totales</div>
                <div class="card-value">{{ $cuenta->cuotas }}</div>
            </td>
            <td class="summary-card">
                <div class="card-title">✔️ Cuotas Pagadas</div>
                <div class="card-value">{{ $cuenta->cuotas_pagadas }}</div>
            </td>
            <td class="summary-card">
                <div class="card-title">⏳ Cuotas Restantes</div>
                <div class="card-value">{{ $cuenta->cuotas - $cuenta->cuotas_pagadas }}</div>
            </td>
        </tr>
    </table>

    <div class="table-title">📅 Cuotas a Pagar</div>
    <table class="data-table">
        <thead>
            <tr>
                <th class="text-center">Cuota</th>
                <th>Fecha de Pago</th>
                <th>Monto</th>
                <th class="text-center">Estado</th>
                <th>Fecha del Pago Realizado</th>
            </tr>
        </thead>
        <tbody>
            {{-- Iteramos sobre la relación cuotasPagar --}}
            @foreach($cuenta->cuotasPagar as $cuota)
            <tr>
                <td class="text-center" style="color: #ef4444; font-weight: bold;">
                    {{-- En tu tabla cuotas_por_pagars, el número de cuota se llama "cuotas" (columna 5) --}}
                    {{ $cuota->cuotas }}
                </td>
                <td>{{ $cuota->fecha_pago }}</td>
                <td class="text-green">S/. {{ number_format($cuota->monto, 2) }}</td>
                <td class="text-center">
                    <span class="badge {{ $cuota->estado === 'pagado' ? 'badge-pagado' : 'badge-pendiente' }}">
                        {{ $cuota->estado }}
                    </span>
                </td>
                <td>
                    {{-- En tu tabla cuotas_por_pagars, la columna es fecha_pagado --}}
                    {{ $cuota->fecha_pagado ? $cuota->fecha_pagado : '—' }}
                </td>
            </tr>
            @endforeach

            @if($cuenta->cuotasPagar->isEmpty())
            <tr>
                <td colspan="5" class="text-center" style="padding: 20px; color: #6b7280;">
                    No hay cuotas registradas para esta cuenta por pagar.
                </td>
            </tr>
            @endif
        </tbody>
    </table>

</body>

</html>