<?php

namespace App\Services;

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Illuminate\Support\Facades\Log;

class ImpresionService
{
    protected $printer;

    public function __construct()
    {
        // ⚠️ IMPORTANTE: Configuración de conexión a la impresora

        try {
            // OPCIÓN A: Si la impresora está compartida en Windows por USB
            // El nombre debe ser el exacto con el que compartiste la impresora en Windows
            $nombreImpresoraCompartida = "pos80";
            $connector = new WindowsPrintConnector($nombreImpresoraCompartida);

            // OPCIÓN B: Si la impresora es de Red (Ethernet/WIFI)
            // $ipImpresora = "192.168.1.100";
            // $connector = new NetworkPrintConnector($ipImpresora, 9100);

            $this->printer = new Printer($connector);
        } catch (\Exception $e) {
            Log::error("No se pudo conectar a la impresora: " . $e->getMessage());
            throw $e;
        }
    }

    public function imprimirTicketVenta($data)
    {
        if (!$this->printer) return;

        try {
            // 1. CABECERA (Centrada)
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->setEmphasis(true);
            $this->printer->text("POLLERIA ÁLVAREZ\n"); // Cámbialo por tu variable de empresa si deseas
            $this->printer->setEmphasis(false);
            $this->printer->text("RUC: 20123456789\n");
            $this->printer->text("Av. Principal 123, Ica\n");
            $this->printer->text("--------------------------------\n");

            // 2. DATOS DEL COMPROBANTE
            $this->printer->setEmphasis(true);
            $this->printer->text($data['tipo_comprobante'] . "\n");
            $this->printer->text($data['serie_correlativo'] . "\n");
            $this->printer->setEmphasis(false);
            $this->printer->text("Fecha: " . $data['fecha'] . "\n");
            $this->printer->text("Cajero: " . $data['cajero'] . "\n");
            $this->printer->text("--------------------------------\n");

            // 3. DATOS DEL CLIENTE (Izquierda)
            $this->printer->setJustification(Printer::JUSTIFY_LEFT);
            $this->printer->text("Cliente: " . $data['cliente']['nombre'] . "\n");
            $this->printer->text("Doc: " . $data['cliente']['documento'] . "\n");
            if (!empty($data['cliente']['direccion'])) {
                $this->printer->text("Dir: " . $data['cliente']['direccion'] . "\n");
            }
            $this->printer->text("--------------------------------\n");

            // 4. DETALLE DE PRODUCTOS
            // Formato: CANTIDAD | DESCRIPCION | TOTAL
            $this->printer->setEmphasis(true);
            $this->printer->text($this->formatearLinea("CANT", "DESCRIPCION", "TOTAL"));
            $this->printer->setEmphasis(false);
            $this->printer->text("--------------------------------\n");

            foreach ($data['productos'] as $item) {
                // Formateamos para que ocupe los espacios correctos en papel de 80mm
                $linea = $this->formatearLinea(
                    $item->cantidad,
                    substr($item->descripcion, 0, 18), // Cortamos textos muy largos
                    "S/ " . number_format($item->valor_total + $item->igv, 2)
                );
                $this->printer->text($linea);
            }
            $this->printer->text("--------------------------------\n");

            // 5. TOTALES (Derecha)
            $this->printer->setJustification(Printer::JUSTIFY_RIGHT);
            $this->printer->text("OP. GRAVADA: S/ " . number_format($data['subtotal'], 2) . "\n");
            $this->printer->text("IGV (18%): S/ " . number_format($data['igv'], 2) . "\n");
            $this->printer->setEmphasis(true);
            $this->printer->text("TOTAL: S/ " . number_format($data['total'], 2) . "\n");
            $this->printer->setEmphasis(false);

            $this->printer->text("Pago con: " . strtoupper($data['metodo_pago']) . "\n");
            $this->printer->text("--------------------------------\n");

            // 6. PIE DE PÁGINA (Centrado)
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->text("¡Gracias por su preferencia!\n");

            // 7. CORTAR PAPEL Y ABRIR CAJA REGISTRADORA
            $this->printer->cut();
            // $this->printer->pulse(); // Descomenta esto si tienes una gaveta de dinero conectada a la impresora

            // 8. FINALIZAR (CRÍTICO: Libera la memoria de la impresora)
            $this->printer->close();
        } catch (\Exception $e) {
            Log::error("Error procesando los comandos de impresión: " . $e->getMessage());
        }
    }

    public function imprimirComandaCocina($data)
    {
        if (!$this->printer) return;

        try {
            // 1. CABECERA CON LETRA GRANDE
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->setTextSize(2, 2); // Doble de ancho y alto
            $this->printer->text("MESA " . $data['mesa'] . "\n");
            $this->printer->setTextSize(1, 1); // Volver a tamaño normal
            $this->printer->text("--------------------------------\n");

            // 2. DATOS DEL PEDIDO
            $this->printer->setJustification(Printer::JUSTIFY_LEFT);
            $this->printer->text("Fecha: " . $data['fecha'] . "\n");
            $this->printer->text("Mozo : " . $data['usuario'] . "\n");
            $this->printer->text("--------------------------------\n");

            // 3. LISTA DE PLATOS (Resaltado)
            $this->printer->setEmphasis(true);
            foreach ($data['productos'] as $item) {
                // Formato tipo checkbox: [ ] 2x Pollo a la brasa
                $this->printer->text("[ ] " . $item['cantidad'] . "x " . strtoupper($item['nombre']) . "\n");
            }
            $this->printer->setEmphasis(false);

            // 4. NOTAS ADICIONALES (Si hay)
            if (!empty($data['nota'])) {
                $this->printer->text("--------------------------------\n");
                $this->printer->text("NOTA:\n");
                $this->printer->text($data['nota'] . "\n");
            }

            $this->printer->text("--------------------------------\n");
            $this->printer->text("\n\n"); // Espacio extra para que no se corte el texto

            // 5. CORTAR Y CERRAR
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
            // 1. CABECERA
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->setTextSize(2, 2); // Letra grande para el título
            $this->printer->text($data['titulo'] . "\n");
            $this->printer->setTextSize(1, 1); // Letra normal
            $this->printer->text("--------------------------------\n");

            // 2. FECHA Y HORA
            $this->printer->setJustification(Printer::JUSTIFY_LEFT);
            $this->printer->text("Fecha: " . date('d/m/Y H:i:s') . "\n");
            $this->printer->text("--------------------------------\n");

            // 3. DETALLE DE CONTENIDO (Los platos)
            $this->printer->setEmphasis(true);
            $this->printer->text($this->formatearLinea("CANT", "DESCRIPCION", "TOTAL"));
            $this->printer->setEmphasis(false);
            $this->printer->text("--------------------------------\n");

            // Verificamos si el contenido es un array de productos
            if (is_array($data['contenido'])) {
                foreach ($data['contenido'] as $item) {
                    $nombre = isset($item['nombre']) ? substr($item['nombre'], 0, 18) : 'Item';
                    $cant = $item['cantidad'] ?? 1;
                    $subtotal = $item['subtotal'] ?? 0;

                    $linea = $this->formatearLinea(
                        $cant,
                        $nombre,
                        "S/ " . number_format((float)$subtotal, 2)
                    );
                    $this->printer->text($linea);
                }
            } else {
                // Por si en algún momento decides enviar solo un bloque de texto en 'contenido'
                $this->printer->text($data['contenido'] . "\n");
            }

            $this->printer->text("--------------------------------\n");

            // 4. TOTAL (Opcional, si viene en la data)
            if (isset($data['total'])) {
                $this->printer->setJustification(Printer::JUSTIFY_RIGHT);
                $this->printer->setEmphasis(true);
                $this->printer->text("TOTAL A PAGAR: S/ " . number_format((float)$data['total'], 2) . "\n");
                $this->printer->setEmphasis(false);
                $this->printer->text("--------------------------------\n");
            }

            // 5. PIE DE PÁGINA (Aclaración importante para pre-cuentas)
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->text("Este documento NO es un\n");
            $this->printer->text("comprobante de pago valido\n");
            $this->printer->text("\n\n"); // Espacio para que el corte no muerda el texto

            // 6. CORTAR Y CERRAR
            $this->printer->cut();
            $this->printer->close();
        } catch (\Exception $e) {
            Log::error("Error imprimiendo ticket generico/pre-cuenta: " . $e->getMessage());
        }
    }
    /**
     * Helper para alinear columnas en el ticket (asumiendo ticket de 80mm = aprox 42 caracteres por línea)
     */
    private function formatearLinea($cant, $desc, $total)
    {
        $cant = str_pad($cant, 5, " ", STR_PAD_RIGHT);
        $desc = str_pad($desc, 20, " ", STR_PAD_RIGHT);
        $total = str_pad($total, 10, " ", STR_PAD_LEFT);

        return "$cant $desc $total\n";
    }
}
