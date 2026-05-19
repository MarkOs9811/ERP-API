<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ConfiguracionHelper;
use App\Http\Controllers\Controller;
use App\Models\MiEmpresa;
use Illuminate\Http\Request;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use DateTime;
use Greenter\Model\Sale\Charge;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FacturacionSunatController extends Controller
{
    public function generarFactura($datosFactura)
    {
        try {
            Log::info('Inicio de generación de factura.');

            $cliente = $datosFactura['cliente'] ?? [];

            if (!isset($cliente['nombre']) || empty($cliente['nombre'])) {
                $cliente['nombre'] = $cliente['razonSocial'] ?? 'CLIENTE GENERICO';
            }
            if (!isset($cliente['numero_documento']) || empty($cliente['numero_documento'])) {
                $cliente['numero_documento'] = $cliente['ruc'] ?? '00000000';
            }
            if (!isset($cliente['tipo_documento']) || empty($cliente['tipo_documento'])) {
                $cliente['tipo_documento'] = isset($cliente['ruc']) ? '6' : '1';
            }
            $datosFactura['cliente'] = $cliente;

            $empresa = MiEmpresa::first();
            $ruc = $empresa->ruc;
            $razonSocial = $empresa->nombre;
            $nombreComercial = $empresa->nombre;

            $certificateFile = ConfiguracionHelper::valor1('sunat');
            $endpoint = ConfiguracionHelper::valor2('sunat');
            $solUser = ConfiguracionHelper::valor3('sunat');
            $solPassword = ConfiguracionHelper::valor4('sunat');

            $certificatePath = storage_path('app/sunat_certificados/' . $certificateFile);

            $see = new See();
            $see->setCertificate(file_get_contents($certificatePath));
            $see->setClaveSOL($ruc, $solUser, $solPassword);
            $see->setService($endpoint);

            $cliente = $datosFactura['cliente'];

            $client = (new Client())
                ->setTipoDoc($cliente['tipo_documento'])
                ->setNumDoc($cliente['numero_documento'])
                ->setRznSocial($cliente['nombre']);

            Log::info('Cliente' . json_encode($client));

            $address = (new Address())
                ->setUbigueo('150101')
                ->setDepartamento('LIMA')
                ->setProvincia('LIMA')
                ->setDistrito('LIMA')
                ->setUrbanizacion('-')
                ->setDireccion('Av. Villa Nueva 221')
                ->setCodLocal('0000');

            Log::info('Direccion Empresa' . json_encode($address));

            $company = (new Company())
                ->setRuc($ruc)
                ->setRazonSocial($razonSocial)
                ->setNombreComercial($nombreComercial)
                ->setAddress($address);

            $serie = $datosFactura['tipo_comprobante'] === 'F' ? 'F001' : 'B001';
            $correlativo = $datosFactura['venta_id'];

            // ==================================================================
            // 🔥 ESCUDO ANTI-BUGS DE SUNAT BETA (FIRE WOK)
            // ==================================================================
            // ⚠️ IMPORTANTE PARA PRODUCCIÓN S.O.S:
            // Actualmente está fijo en UBL 2.0 y Operación 01 para evitar el bug 
            // "0306" de la SUNAT en su entorno de Pruebas (Beta).
            // 
            // CUANDO PASES A PRODUCCIÓN REAL, HAZ ESTOS DOS CAMBIOS AQUÍ ABAJO:
            // 1. Cambia ->setUblVersion('2.0')  por  ->setUblVersion('2.1')
            // 2. Cambia ->setTipoOperacion('01') por ->setTipoOperacion('0101')
            // ==================================================================
            $invoice = (new Invoice())
                ->setUblVersion('2.0')   // <--- CAMBIAR A '2.1' EN PRODUCCIÓN
                ->setTipoOperacion('01') // <--- CAMBIAR A '0101' EN PRODUCCIÓN
                ->setTipoDoc($datosFactura['tipo_comprobante'] === 'F' ? '01' : '03')
                ->setSerie($serie)
                ->setCorrelativo($correlativo)
                ->setFechaEmision(new DateTime('now', new \DateTimeZone('America/Lima')))
                ->setFormaPago(new FormaPagoContado())
                ->setTipoMoneda('PEN')
                ->setCompany($company)
                ->setClient($client);

            // ===================================================
            // 1. AGREGAR DETALLES (Soporta POS y Delivery App)
            // ===================================================
            $detalles = [];
            $sumValorVentaBruto = 0;
            $sumIgvBruto = 0;

            foreach ($datosFactura['detalle'] as $detalle) {
                $detalleObj = (object)$detalle;

                // Soporte multi-formato: Busca 'precio_unitario' (App) o 'precio' (POS)
                $precio_unitario = (float)($detalleObj->precio_unitario ?? $detalleObj->precio ?? 0);
                $cantidad = (int)($detalleObj->cantidad ?? 1);
                $descripcion = $detalleObj->descripcion ?? $detalleObj->nombre ?? 'Producto Genérico';
                $idProducto = $detalleObj->idPlato ?? $detalleObj->idPromocion ?? $detalleObj->id ?? 'SRV01';

                $valor_unitario = $precio_unitario / 1.18;
                $valor_total = $valor_unitario * $cantidad;
                $igv = ($precio_unitario * $cantidad) - $valor_total;

                $sumValorVentaBruto += $valor_total;
                $sumIgvBruto += $igv;

                $item = (new SaleDetail())
                    ->setCodProducto($idProducto)
                    ->setUnidad('NIU')
                    ->setCantidad($cantidad)
                    ->setDescripcion($descripcion)
                    ->setMtoValorUnitario(round($valor_unitario, 5))
                    ->setMtoBaseIgv(round($valor_total, 2))
                    ->setPorcentajeIgv(18.00)
                    ->setIgv(round($igv, 2))
                    ->setTipAfeIgv('10')
                    ->setTotalImpuestos(round($igv, 2))
                    ->setMtoValorVenta(round($valor_total, 2))
                    ->setMtoPrecioUnitario(round($precio_unitario, 5));
                $detalles[] = $item;
            }

            // ===================================================
            // 2. LÓGICA DE DESCUENTO GLOBAL (AllowanceCharge SUNAT)
            // ===================================================
            $descuentoGlobalBruto = isset($datosFactura['descuento']) ? (float)$datosFactura['descuento'] : 0.00;

            if ($descuentoGlobalBruto > 0) {
                $descuentoBase = $descuentoGlobalBruto / 1.18;
                $factor = $descuentoBase / $sumValorVentaBruto;

                $charge = (new Charge())
                    ->setCodTipo('02')
                    ->setMontoBase(round($sumValorVentaBruto, 2))
                    ->setFactor(round($factor, 5))
                    ->setMonto(round($descuentoBase, 2));

                $invoice->setDescuentos([$charge]);
                $invoice->setMtoDescuentos(round($descuentoBase, 2));

                $mtoOperGravadas = $sumValorVentaBruto - $descuentoBase;
                $mtoIGV = $sumIgvBruto - ($descuentoGlobalBruto - $descuentoBase);
                $totalFinal = $mtoOperGravadas + $mtoIGV;
            } else {
                $mtoOperGravadas = $sumValorVentaBruto;
                $mtoIGV = $sumIgvBruto;
                $totalFinal = $mtoOperGravadas + $mtoIGV;
            }

            // ===================================================
            // 3. SETEAR TOTALES EN LA CABECERA DEL INVOICE
            // ===================================================
            $invoice->setMtoOperGravadas(round($mtoOperGravadas, 2))
                ->setMtoIGV(round($mtoIGV, 2))
                ->setTotalImpuestos(round($mtoIGV, 2))
                ->setValorVenta(round($mtoOperGravadas, 2))
                ->setSubTotal(round($totalFinal, 2))
                ->setMtoImpVenta(round($totalFinal, 2))
                ->setDetails($detalles);

            // ===================================================
            // 4. LEYENDA (Monto en Letras protegido contra decimales)
            // ===================================================
            $montoEnLetras = $this->numToLetters(round($totalFinal, 2)) . ' SOLES';
            $legend = (new Legend())
                ->setCode('1000')
                ->setValue($montoEnLetras);

            $invoice->setLegends([$legend]);

            // ENVIAR A SUNAT
            Log::info('Enviando factura/boleta a SUNAT...');
            $result = $see->send($invoice);

            if ($result->isSuccess()) {
                Log::info('Documento aceptado por SUNAT.');

                $xml = $see->getFactory()->getLastXml();

                // GUARDAR XML
                // GUARDAR XML EN LA NUBE (S3/R2)
                $rutaXmlRelativa = "xml/{$serie}-{$correlativo}.xml";
                Storage::disk('s3')->put($rutaXmlRelativa, $xml);

                // GUARDAR CDR EN LA NUBE (S3/R2)
                $cdrZip = $result->getCdrZip();
                $rutaCdrRelativa = "cdr/{$serie}-{$correlativo}_CDR.zip";
                Storage::disk('s3')->put($rutaCdrRelativa, $cdrZip);

                $observaciones = $result->getCdrResponse()->getNotes();
                $estado = empty($observaciones) ? 1 : 3; // 1: Aceptado, 3: Aceptado con observaciones

                return [
                    'success' => true,
                    'message' => 'Documento aceptado por SUNAT',
                    'estado' => $estado,
                    'observaciones' => $observaciones,
                    'rutaXml' => $rutaXmlRelativa,
                    'rutaCdr' => $rutaCdrRelativa,
                    'cdr' => [
                        'code' => $result->getCdrResponse()->getCode(),
                        'description' => $result->getCdrResponse()->getDescription(),
                    ],
                ];
            } else {
                $error = $result->getError();
                Log::error('Error al enviar documento: ' . $error->getMessage(), ['code' => $error->getCode()]);

                return [
                    'success' => false,
                    'message' => $error->getMessage(),
                    'estado' => 0, // 0: Rechazado
                    'observaciones' => [],
                ];
            }
        } catch (\Exception $e) {
            Log::error('Excepción en la generación: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al generar el documento',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function numToLetters($number)
    {
        $formatter = new \NumberFormatter("es", \NumberFormatter::SPELLOUT);
        $integerPart = floor($number);
        $decimalPart = round(($number - $integerPart) * 100);

        $integerPartInWords = ucfirst($formatter->format($integerPart));
        $decimalPartInWords = str_pad($decimalPart, 2, '0', STR_PAD_LEFT);

        return "{$integerPartInWords} con {$decimalPartInWords}/100";
    }
}
