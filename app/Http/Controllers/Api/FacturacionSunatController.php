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
use Illuminate\Support\Facades\Log;

class FacturacionSunatController extends Controller
{
    public function generarFactura($datosFactura)
    {
        try {
            Log::info('Inicio de generación de factura.');

            // Validar y completar los datos del cliente
            $cliente = $datosFactura['cliente'];

            // Asegurarse de que el campo 'nombre' esté presente
            if (!isset($cliente['nombre']) || empty($cliente['nombre'])) {
                $cliente['nombre'] = $cliente['razonSocial'] ?? 'CLIENTE GENERICO';
            }

            // Asegurarse de que el campo 'numero_documento' esté presente
            if (!isset($cliente['numero_documento']) || empty($cliente['numero_documento'])) {
                $cliente['numero_documento'] = $cliente['ruc'] ?? '00000000';
            }

            // Asegurarse de que el campo 'tipo_documento' esté presente
            if (!isset($cliente['tipo_documento']) || empty($cliente['tipo_documento'])) {
                $cliente['tipo_documento'] = isset($cliente['ruc']) ? '6' : '1'; // 6: RUC, 1: DNI
            }

            // Actualizar los datos del cliente en $datosFactura
            $datosFactura['cliente'] = $cliente;


            // Obteniendo datos de la empresa
            $empresa = MiEmpresa::first();

            // 2. Obtener configuración SUNAT desde la base de datos
            $ruc = $empresa->ruc; // O $empresa->clave si así lo tienes
            $razonSocial = $empresa->nombre;
            $nombreComercial = $empresa->nombre; //cambiar si tiene un nombre comercial

            // Continuar con la generación de la factura
            $certificateFile = ConfiguracionHelper::valor1('sunat'); // Ej: certificado.pem
            $endpoint = ConfiguracionHelper::valor2('sunat');
            $solUser = ConfiguracionHelper::valor3('sunat');
            $solPassword = ConfiguracionHelper::valor4('sunat'); // Si tienes más valores, puedes agregar métodos en tu helper



            // 3. Construir la ruta del certificado
            $certificatePath = storage_path('app/sunat_certificados/' . $certificateFile);


            $see = new See();
            $see->setCertificate(file_get_contents($certificatePath));
            $see->setClaveSOL($ruc, $solUser, $solPassword);
            $see->setService($endpoint);

            // DATOS DEL CLIENTE
            $cliente = $datosFactura['cliente'];

            // Configurar cliente genérico si no hay datos del cliente
            $client = (new Client())
                ->setTipoDoc($cliente['tipo_documento']) // 0 para sin documento
                ->setNumDoc($cliente['numero_documento']) // '00000000' para genérico
                ->setRznSocial($cliente['nombre']); // 'CLIENTE GENERICO' para boletas sin cliente

            Log::info('Cliente' . json_encode($client));
            // DIRECCIÓN DEL EMISOR (configurada fija o desde tu sistema)
            $address = (new Address())
                ->setUbigueo('150101')
                ->setDepartamento('LIMA')
                ->setProvincia('LIMA')
                ->setDistrito('LIMA')
                ->setUrbanizacion('-')
                ->setDireccion('Av. Villa Nueva 221')
                ->setCodLocal('0000');

            Log::info('Direccion Empresa' . json_encode($address));
            // EMPRESA EMISORA
            $company = (new Company())
                ->setRuc($ruc)
                ->setRazonSocial($razonSocial)
                ->setNombreComercial($nombreComercial)
                ->setAddress($address);

            // CONFIGURAR FACTURA O BOLETA
            $serie = $datosFactura['tipo_comprobante'] === 'F' ? 'F001' : 'B001';
            $correlativo = $datosFactura['venta_id']; // puedes formatearlo si quieres (ej: '00001')

            $invoice = (new Invoice())
                ->setUblVersion('2.1')
                ->setTipoOperacion('0101') // venta interna
                ->setTipoDoc($datosFactura['tipo_comprobante'] === 'F' ? '01' : '03')
                ->setSerie($serie)
                ->setCorrelativo($correlativo)
                ->setFechaEmision(new DateTime('now', new \DateTimeZone('America/Lima')))
                ->setFormaPago(new FormaPagoContado())
                ->setTipoMoneda('PEN')
                ->setCompany($company)
                ->setClient($client);

            // AGREGAR DETALLES
            $detalles = [];
            $subtotal = 0;
            $igv_total = 0;

            foreach ($datosFactura['detalle'] as $detalle) {
                $valor_unitario = $detalle->precio_unitario / 1.18;
                $valor_total = $valor_unitario * $detalle->cantidad;
                $igv = $detalle->precio_unitario * $detalle->cantidad - $valor_total;

                $subtotal += $valor_total;
                $igv_total += $igv;

                $item = (new SaleDetail())
                    ->setCodProducto($detalle->idPlato)
                    ->setUnidad('NIU')
                    ->setCantidad($detalle->cantidad)
                    ->setDescripcion($detalle->descripcion)
                    ->setMtoValorUnitario($valor_unitario)
                    ->setMtoBaseIgv($valor_total)
                    ->setPorcentajeIgv(18.00)
                    ->setIgv($igv)
                    ->setTipAfeIgv('10')
                    ->setTotalImpuestos($igv)
                    ->setMtoValorVenta($valor_total)
                    ->setMtoPrecioUnitario($detalle->precio_unitario);
                $detalles[] = $item;
            }

            $total = $subtotal + $igv_total;

            $invoice->setMtoOperGravadas($subtotal)
                ->setMtoIGV($igv_total)
                ->setTotalImpuestos($igv_total)
                ->setValorVenta($subtotal)
                ->setSubTotal($total)
                ->setMtoImpVenta($total)
                ->setDetails($detalles);

            // AGREGAR LEYENDA (monto en letras)
            $montoEnLetras = $this->numToLetters($total) . ' SOLES'; // método que convierte números a letras
            $legend = (new Legend())
                ->setCode('1000')
                ->setValue($montoEnLetras);

            $invoice->setLegends([$legend]);

            // ENVIAR A SUNAT
            Log::info('Enviando factura/boleta a SUNAT...');
            $result = $see->send($invoice);

            if ($result->isSuccess()) {
                Log::info('Documento aceptado por SUNAT.');

                // Obtener el XML generado
                $xml = $see->getFactory()->getLastXml(); // Asegúrate de que esta línea esté presente y funcione correctamente

                // GUARDAR XML
                $rutaXmlRelativa = "xml/{$serie}-{$correlativo}.xml";
                file_put_contents(storage_path("app/public/{$rutaXmlRelativa}"), $xml);

                // GUARDAR CDR
                $cdrZip = $result->getCdrZip(); // Asegúrate de que esta línea esté presente y funcione correctamente
                $rutaCdrRelativa = "cdr/{$serie}-{$correlativo}_CDR.zip";
                file_put_contents(storage_path("app/public/{$rutaCdrRelativa}"), $cdrZip);

                $observaciones = $result->getCdrResponse()->getNotes();
                $estado = empty($observaciones) ? 1 : 3; // 1: Aceptado, 3: Aceptado con observaciones

                // Retornar las rutas relativas
                return [
                    'success' => true,
                    'message' => 'Documento aceptado por SUNAT',
                    'estado' => $estado,
                    'observaciones' => $observaciones,
                    'rutaXml' => $rutaXmlRelativa, // Ruta relativa del XML
                    'rutaCdr' => $rutaCdrRelativa, // Ruta relativa del CDR
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

    /**
     * Convierte un número a su representación en letras.
     *
     * @param float $number
     * @return string
     */
    public function numToLetters($number)
    {
        $formatter = new \NumberFormatter("es", \NumberFormatter::SPELLOUT);
        $integerPart = floor($number);
        $decimalPart = round(($number - $integerPart) * 100);

        $integerPartInWords = ucfirst($formatter->format($integerPart));
        $decimalPartInWords = str_pad($decimalPart, 2, '0', STR_PAD_LEFT);

        return "{$integerPartInWords} con {$decimalPartInWords}/100";
    }

    // el siguiente meteodo es el ejemplo base para factura

    // public function generarFactura()
    // {
    //     try {
    //         Log::info('Inicio de generación de factura.');

    //         // Cargar configuración desde config/sunat.php
    //         Log::info('Cargando configuración de Greenter...');
    //         $config = config('sunat');

    //         // Crear instancia de See
    //         $see = new See();
    //         $see->setCertificate(file_get_contents($config['certificate_path']));
    //         $see->setClaveSOL($config['ruc'], $config['sol_user'], $config['sol_password']);
    //         $see->setService($config['endpoint']); // Endpoint BETA o Producción

    //         // Crear cliente
    //         Log::info('Creando datos del cliente...');
    //         $client = (new Client())
    //             ->setTipoDoc('6')
    //             ->setNumDoc('20000000001')
    //             ->setRznSocial('EMPRESA X');

    //         // Crear dirección del emisor
    //         Log::info('Configurando dirección de la empresa...');
    //         $address = (new Address())
    //             ->setUbigueo('150101')
    //             ->setDepartamento('LIMA')
    //             ->setProvincia('LIMA')
    //             ->setDistrito('LIMA')
    //             ->setUrbanizacion('-')
    //             ->setDireccion('Av. Villa Nueva 221')
    //             ->setCodLocal('0000');

    //         // Crear empresa (emisor)
    //         Log::info('Configurando empresa emisora...');
    //         $company = (new Company())
    //             ->setRuc('20123456789')
    //             ->setRazonSocial('GREEN SAC')
    //             ->setNombreComercial('GREEN')
    //             ->setAddress($address);

    //         // Crear factura
    //         Log::info('Creando factura...');
    //         $invoice = (new Invoice())
    //             ->setUblVersion('2.1')
    //             ->setTipoOperacion('0101')
    //             ->setTipoDoc('01')
    //             ->setSerie('F001')
    //             ->setCorrelativo('1')
    //             ->setFechaEmision(new DateTime('now', new \DateTimeZone('America/Lima')))
    //             ->setFormaPago(new FormaPagoContado())
    //             ->setTipoMoneda('PEN')
    //             ->setCompany($company)
    //             ->setClient($client)
    //             ->setMtoOperGravadas(100.00)
    //             ->setMtoIGV(18.00)
    //             ->setTotalImpuestos(18.00)
    //             ->setValorVenta(100.00)
    //             ->setSubTotal(118.00)
    //             ->setMtoImpVenta(118.00);

    //         // Agregar detalle
    //         Log::info('Agregando detalle de la venta...');
    //         $item = (new SaleDetail())
    //             ->setCodProducto('P001')
    //             ->setUnidad('NIU')
    //             ->setCantidad(2)
    //             ->setMtoValorUnitario(50.00)
    //             ->setDescripcion('PRODUCTO 1')
    //             ->setMtoBaseIgv(100.00)
    //             ->setPorcentajeIgv(18.00)
    //             ->setIgv(18.00)
    //             ->setTipAfeIgv('10')
    //             ->setTotalImpuestos(18.00)
    //             ->setMtoValorVenta(100.00)
    //             ->setMtoPrecioUnitario(59.00);

    //         // Agregar leyenda
    //         Log::info('Agregando leyenda...');
    //         $legend = (new Legend())
    //             ->setCode('1000')
    //             ->setValue('SON DOSCIENTOS TREINTA Y SEIS CON 00/100 SOLES');

    //         // Asociar detalle y leyenda
    //         $invoice->setDetails([$item])
    //             ->setLegends([$legend]);

    //         // Enviar a SUNAT
    //         Log::info('Enviando factura a SUNAT...');
    //         $result = $see->send($invoice);

    //         if ($result->isSuccess()) {
    //             Log::info('Factura aceptada por SUNAT.');

    //             // Obtener XML generado
    //             $xml = $see->getFactory()->getLastXml();  // Usamos este método para obtener el XML

    //             // Guardar el XML
    //             file_put_contents(storage_path('app/xml/Factura_F001-1.xml'), $xml);

    //             // Obtener CDR en formato ZIP (contenido binario)
    //             $cdrZip = $result->getCdrZip();  // Obtiene el archivo ZIP con el CDR

    //             // Guardar el CDR usando file_put_contents
    //             file_put_contents(storage_path('app/cdr/Factura_F001-1_CDR.zip'), $cdrZip);

    //             return response()->json([
    //                 'success' => true,
    //                 'message' => 'Factura aceptada por SUNAT',
    //                 'cdr' => [
    //                     'code' => $result->getCdrResponse()->getCode(),
    //                     'description' => $result->getCdrResponse()->getDescription(),
    //                     'notes' => $result->getCdrResponse()->getNotes(),
    //                 ]
    //             ]);
    //         } else {
    //             $error = $result->getError();
    //             Log::error('Error al enviar factura: ' . $error->getMessage(), ['code' => $error->getCode()]);
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => $error->getMessage(),
    //                 'code' => $error->getCode()
    //             ]);
    //         }
    //     } catch (\Exception $e) {
    //         Log::error('Excepción en la generación de factura: ' . $e->getMessage());
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Ocurrió un error al generar la factura',
    //             'error' => $e->getMessage()
    //         ]);
    //     }
    // }
}
