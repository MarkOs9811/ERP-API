<?php

namespace App\Services;

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ImpresionService
{
    protected $printer;

    public function __construct()
    {
        try {
            $nombreImpresoraCompartida = "pos80";
            $connector = new WindowsPrintConnector($nombreImpresoraCompartida);
            $this->printer = new Printer($connector);
        } catch (\Exception $e) {
            Log::error("No se pudo conectar a la impresora: " . $e->getMessage());
        }
    }

    private function getDatosEmpresa()
    {
        $user = Auth::user();
        if ($user && $user->idEmpresa) {
            return DB::table('mi_empresas')->where('id', $user->idEmpresa)->first();
        }
        return null;
    }

    public function imprimirTicketVenta($data)
    {
        if (!$this->printer) return;

        try {
            $empresa = $this->getDatosEmpresa();
            $nombreEmpresa = $empresa ? strtoupper($empresa->nombre) : "MI EMPRESA S.A.C.";
            $rucEmpresa = $empresa ? $empresa->ruc : "00000000000";
            $direccionEmpresa = $empresa ? $empresa->direccion : "Direccion no configurada";
            $telefonoEmpresa = $empresa ? $empresa->numero : "";

            // 1. CABECERA
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->setEmphasis(true);
            $this->printer->setTextSize(2, 2);
            $this->printer->text($nombreEmpresa . "\n");
            $this->printer->setTextSize(1, 1);
            $this->printer->setEmphasis(false);
            $this->printer->text("RUC: " . $rucEmpresa . "\n");
            $this->printer->text($direccionEmpresa . "\n");
            if (!empty($telefonoEmpresa)) {
                $this->printer->text("TELF: " . $telefonoEmpresa . "\n");
            }
            // 🔥 Ajustado a 48 guiones
            $this->printer->text("------------------------------------------------\n");

            // 2. TIPO DE COMPROBANTE
            $tipoDoc = strtoupper($data['tipo_comprobante']);
            if ($tipoDoc == 'B' || str_contains($tipoDoc, 'BOLETA')) {
                $tituloDoc = "BOLETA DE VENTA ELECTRÓNICA";
            } elseif ($tipoDoc == 'F' || str_contains($tipoDoc, 'FACTURA')) {
                $tituloDoc = "FACTURA ELECTRÓNICA";
            } else {
                $tituloDoc = "NOTA DE VENTA";
            }

            $this->printer->setEmphasis(true);
            $this->printer->text($tituloDoc . "\n");
            $this->printer->text($data['serie_correlativo'] . "\n");
            $this->printer->setEmphasis(false);
            $this->printer->text("------------------------------------------------\n");

            // 3. DATOS DEL CLIENTE
            $this->printer->setJustification(Printer::JUSTIFY_LEFT);
            $this->printer->text("Fecha Emision: " . $data['fecha'] . "\n");
            $this->printer->text("Cajero       : " . $data['cajero'] . "\n");
            $this->printer->text("Cliente      : " . strtoupper($data['cliente']['nombre']) . "\n");
            $this->printer->text("Documento    : " . $data['cliente']['documento'] . "\n");
            if (!empty($data['cliente']['direccion'])) {
                $this->printer->text("Direccion    : " . strtoupper($data['cliente']['direccion']) . "\n");
            }
            $this->printer->text("------------------------------------------------\n");

            // 4. DETALLE DE PRODUCTOS
            $this->printer->setEmphasis(true);
            $this->printer->text($this->formatearLinea("CANT", "DESCRIPCION", "TOTAL"));
            $this->printer->setEmphasis(false);
            $this->printer->text("------------------------------------------------\n");

            foreach ($data['productos'] as $item) {
                $linea = $this->formatearLinea(
                    $item->cantidad,
                    substr(strtoupper($item->descripcion), 0, 30), // 🔥 Aumentamos el límite de letras del nombre
                    number_format($item->valor_total + $item->igv, 2)
                );
                $this->printer->text($linea);
            }
            $this->printer->text("------------------------------------------------\n");

            // 5. TOTALES
            $this->printer->setJustification(Printer::JUSTIFY_LEFT);

            $subtotalStr = number_format($data['subtotal'], 2);
            $igvStr = number_format($data['igv'], 2);
            $totalStr = number_format($data['total'], 2);

            $this->printer->text($this->formatearTotal("OP. GRAVADA:", "S/ " . $subtotalStr));
            $this->printer->text($this->formatearTotal("IGV (18%):", "S/ " . $igvStr));

            $this->printer->setEmphasis(true);
            $this->printer->text($this->formatearTotal("TOTAL A PAGAR:", "S/ " . $totalStr));
            $this->printer->setEmphasis(false);

            $this->printer->text("------------------------------------------------\n");
            $this->printer->text("Medio de Pago: " . strtoupper($data['metodo_pago']) . "\n");

            // 🔥 ¡AQUÍ ESTÁ LA SOLUCIÓN PARA LAS OBSERVACIONES!
            if (!empty($data['observacion'])) {
                $this->printer->text("------------------------------------------------\n");
                $this->printer->setEmphasis(true);
                $this->printer->text("OBSERVACIONES:\n");
                $this->printer->setEmphasis(false);
                $this->printer->text(strtoupper($data['observacion']) . "\n");
            }

            // 6. PIE DE PÁGINA
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->text("\n");
            $this->printer->text("¡GRACIAS POR SU PREFERENCIA!\n");

            if ($tituloDoc === "NOTA DE VENTA") {
                $this->printer->text("Este documento no es un comprobante\n");
                $this->printer->text("de pago con valor fiscal.\n");
            } else {
                $this->printer->text("Representacion impresa del\n");
                $this->printer->text("Comprobante de Pago Electronico\n");
            }

            $this->printer->text("\n\n\n");
            $this->printer->cut();
            $this->printer->close();
        } catch (\Exception $e) {
            Log::error("Error procesando los comandos de impresión: " . $e->getMessage());
        }
    }

    public function imprimirComandaCocina($data)
    {
        if (!$this->printer) return;

        try {
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->setTextSize(2, 2);
            $this->printer->text("MESA " . $data['mesa'] . "\n");
            $this->printer->setTextSize(1, 1);
            $this->printer->text("------------------------------------------------\n");

            $this->printer->setJustification(Printer::JUSTIFY_LEFT);
            $this->printer->text("Fecha: " . $data['fecha'] . "\n");
            $this->printer->text("Mozo : " . strtoupper($data['usuario']) . "\n");
            $this->printer->text("------------------------------------------------\n");

            $this->printer->setEmphasis(true);
            $this->printer->setTextSize(1, 2);
            foreach ($data['productos'] as $item) {
                $this->printer->text("[ ] " . $item['cantidad'] . "x " . strtoupper($item['nombre']) . "\n");
            }
            $this->printer->setTextSize(1, 1);
            $this->printer->setEmphasis(false);

            if (!empty($data['nota'])) {
                $this->printer->text("------------------------------------------------\n");
                $this->printer->text("NOTA:\n");
                $this->printer->text(strtoupper($data['nota']) . "\n");
            }

            $this->printer->text("------------------------------------------------\n");
            $this->printer->text("\n\n\n");

            $this->printer->cut();
            $this->printer->close();
        } catch (\Exception $e) {
            Log::error("Error imprimiendo comanda de cocina: " . $e->getMessage());
        }
    }

    public function imprimirGenerico($data)
    {
        if (!$this->printer) return;

        try {
            $empresa = $this->getDatosEmpresa();
            $nombreEmpresa = $empresa ? strtoupper($empresa->nombre) : "MI EMPRESA S.A.C.";
            $rucEmpresa = $empresa ? $empresa->ruc : "00000000000";

            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->setEmphasis(true);
            $this->printer->text($nombreEmpresa . "\n");
            $this->printer->setEmphasis(false);
            $this->printer->text("RUC: " . $rucEmpresa . "\n");
            $this->printer->text("------------------------------------------------\n");

            $this->printer->setTextSize(2, 2);
            $this->printer->setEmphasis(true);
            $this->printer->text(strtoupper($data['titulo']) . "\n");
            $this->printer->setTextSize(1, 1);
            $this->printer->setEmphasis(false);
            $this->printer->text("------------------------------------------------\n");

            $this->printer->setJustification(Printer::JUSTIFY_LEFT);
            $this->printer->text("Fecha Emision: " . date('d/m/Y H:i:s') . "\n");
            $this->printer->text("------------------------------------------------\n");

            $this->printer->setEmphasis(true);
            $this->printer->text($this->formatearLinea("CANT", "DESCRIPCION", "TOTAL"));
            $this->printer->setEmphasis(false);
            $this->printer->text("------------------------------------------------\n\n");

            if (is_array($data['contenido'])) {
                foreach ($data['contenido'] as $item) {
                    $nombre = isset($item['nombre']) ? substr(strtoupper($item['nombre']), 0, 30) : 'ITEM';
                    $cant = $item['cantidad'] ?? 1;
                    $subtotal = $item['subtotal'] ?? 0;

                    $linea = $this->formatearLinea(
                        $cant,
                        $nombre,
                        number_format((float)$subtotal, 2)
                    );
                    $this->printer->text($linea);
                }
            } else {
                $this->printer->text($data['contenido'] . "\n");
            }

            $this->printer->text("\n------------------------------------------------\n");

            if (isset($data['total'])) {
                $this->printer->setJustification(Printer::JUSTIFY_RIGHT);
                $this->printer->setTextSize(1, 2);
                $this->printer->setEmphasis(true);
                $this->printer->text("TOTAL: S/ " . number_format((float)$data['total'], 2) . "\n");
                $this->printer->setEmphasis(false);
                $this->printer->setTextSize(1, 1);
                $this->printer->text("------------------------------------------------\n");
            }

            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->text("\nPor favor, revise su consumo.\n");
            $this->printer->setEmphasis(true);
            $this->printer->text("Indique al mozo si deseara\n");
            $this->printer->text("BOLETA o FACTURA electronica.\n");
            $this->printer->setEmphasis(false);
            $this->printer->text("\nEste documento NO es un\n");
            $this->printer->text("comprobante de pago con valor fiscal.\n\n\n");

            $this->printer->cut();
            $this->printer->close();
        } catch (\Exception $e) {
            Log::error("Error imprimiendo ticket generico/pre-cuenta: " . $e->getMessage());
        }
    }

    /**
     * 🔥 Helper corregido para 48 caracteres (80mm)
     */
    private function formatearLinea($cant, $desc, $total)
    {
        // 4 Caracteres para la cantidad
        $cantPad = str_pad(substr($cant, 0, 4), 4, " ", STR_PAD_RIGHT);
        // 32 Caracteres para la descripción (¡Antes era 26!)
        $descPad = str_pad(substr($desc, 0, 32), 32, " ", STR_PAD_RIGHT);
        // 10 Caracteres para el precio total
        $totalPad = str_pad(substr($total, 0, 10), 10, " ", STR_PAD_LEFT);

        // 4 + 1(espacio) + 32 + 1(espacio) + 10 = 48 caracteres perfectos
        return "$cantPad $descPad $totalPad\n";
    }

    /**
     * 🔥 Helper corregido para 48 caracteres (80mm)
     */
    private function formatearTotal($etiqueta, $monto)
    {
        // Etiqueta a la izquierda (24 chars) y Monto a la derecha (24 chars) = 48
        $etiqPad = str_pad(substr($etiqueta, 0, 24), 24, " ", STR_PAD_RIGHT);
        $montoPad = str_pad(substr($monto, 0, 24), 24, " ", STR_PAD_LEFT);

        return "$etiqPad$montoPad\n";
    }
}
