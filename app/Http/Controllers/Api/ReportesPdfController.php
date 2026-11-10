<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventario;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ReportesPdfController extends Controller
{
    public function getReporteInventario()
    {
        try {
            $productos = Inventario::all();

            $dataPdf = compact('productos');

            $pdf = Pdf::loadView('reportes.inventario', $dataPdf);

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="Reporte_Inventario.pdf"'
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
