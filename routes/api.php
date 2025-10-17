<?php

use App\Http\Controllers\Api\AjustesAlmacenController;
use App\Http\Controllers\Api\AjustesUniMedidaController;
use App\Http\Controllers\api\AjusteVentasController;
use App\Http\Controllers\Api\AreaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CargoController;
use App\Http\Controllers\Api\HorarioController;
use App\Http\Controllers\Api\AlmacenController;
use App\Http\Controllers\api\AsistenciasController;
use App\Http\Controllers\Api\CajaController;
use App\Http\Controllers\Api\CategoriasController;
use App\Http\Controllers\Api\CocinaController;
use App\Http\Controllers\Api\CombosController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\GestionMenusController;
use App\Http\Controllers\Api\GestionProveedoresController;
use App\Http\Controllers\api\InventarioController;
use App\Http\Controllers\api\KardexController;
use App\Http\Controllers\api\RegistroCajasController;
use App\Http\Controllers\api\SolicitudesController;
use App\Http\Controllers\api\UnidadController;
use App\Http\Controllers\Api\VenderController;
use App\Http\Controllers\api\VentasController;
use App\Http\Controllers\api\ComprasController;
use App\Http\Controllers\Api\EventosController;
use App\Http\Controllers\Api\FacturacionSunatController;
use App\Http\Controllers\Api\FinanzasController;
use App\Http\Controllers\api\GoogleCalendarController;
use App\Http\Controllers\api\PedidosWebController;
use App\Http\Controllers\api\PlanillaController;
use App\Http\Controllers\Api\ReportesController;
use App\Http\Controllers\Api\SedesController;
use App\Http\Controllers\api\WhatsAppController;
use App\Http\Controllers\Auth\GoogleController;
use Google\Service\AdSenseHost\Report;

Route::options('/{any}', function () {
    return response()->json([], 200, [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
    ]);
})->where('any', '.*');

// RUTAS PARA LOGEARSE
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// RUTAS PARA LOGEARSE CON GOOGLE
Route::get('/auth/google/redirect', [GoogleController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);

// PARA VALDIAR LOS DATOS DE GOOGLE
Route::middleware('auth:sanctum')->get('/loginGoogle', [GoogleController::class, 'datosLoginGoogle']);

// PARA CREAR EVENTSO CON SERVICIO DE GOOGLE CALENDAR
Route::middleware('auth:sanctum')->post('/crear-evento', [GoogleCalendarController::class, 'crearEvento']);


// Rutas que requieren autenticación mediante Sanctum
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/webhook/whatsapp', [WhatsAppController::class, 'manejarMensaje']);
Route::get('/pedidosWsp', [WhatsAppController::class, 'obtenerPedidos']);

// PARA ENVIAR  FACTURAS
Route::get('/sunat/enviar-factura', [FacturacionSunatController::class, 'generarFactura']);


// PARA PROBAR REPORTES DE EXCEL
Route::get('/reporteVentas', [ReportesController::class, 'reporteVentasExcel']);
Route::get('/cajas2', [CajaController::class, 'getCajas']);



// RUTAS PARA REGISTRAR ASISTENCIA ENTRADA / SALIDA
Route::post('/asistencia/ingreso', [AsistenciasController::class, 'ingreso']);
Route::post('/asistencia/salida', [AsistenciasController::class, 'salida']);

// RUTA DE PRUEBA SIN AUTH
Route::get('/comprasAll', [ComprasController::class, 'getCompras']);

Route::middleware('auth:sanctum', 'throttle:api')->group(function () {

    // RUTAS PARA ATENDER UN PEDIDO POR CHAT
    Route::get('/chat/{id}', [WhatsAppController::class, 'obtenerChats']);
    Route::post('/chat', [WhatsAppController::class, 'store']);




    Route::post('/almacen/add-stock', [AlmacenController::class, 'addStock']);

    // MODULO CONFIGURACION
    Route::get('/configuraciones', [ConfiguracionController::class, 'getConfiguracion']);
    Route::get('/miPerfil', [ConfiguracionController::class, 'getMiPerfil']);
    Route::get('/configuracion/getMiEmpresa', [ConfiguracionController::class, 'getEmpresa']);
    Route::post('/configuracion/updateMiempresa', [ConfiguracionController::class, 'actualizarConfiguracion']);

    // RUTAS PARA VENTAS 
    Route::get('/ventas', [VentasController::class, 'getVentas']);

    // RUTAS PARA EL REGISTRO DE CAJAS - MODULO VENTAS
    Route::get('/cajasTools', [RegistroCajasController::class, 'getCajasAll']);
    Route::get('/registrosCajas', [RegistroCajasController::class, 'getRegistrosCajas']);

    // RUTAS PARA  SOLICITUDES  - MODULO VENTAS
    Route::get('/solicitudes', [SolicitudesController::class, 'getSolicitudes']);
    Route::delete('/solicitudes/{id}', [SolicitudesController::class, 'elimiarmiSolicitud']);
    Route::put('/solicitudes/{id}', [SolicitudesController::class, 'actualizarMiSolicitud']);

    // RUTAS PARA AJUSTES DE VENTAS - MODULOS VENTAS
    Route::get('/metodos-pagos', [AjusteVentasController::class, 'getMetodosPagosAll']);
    Route::post('/metodos-pagos', [AjusteVentasController::class, 'guardarMetodoPago']);

    Route::put('/metodos-pagos/{id}', [AjusteVentasController::class, 'updateMetodoPago']);

    // CAMBIAR ESTADO DE SOLCIITUD -DESDE MODULO ALMACEN 

    Route::post('/solicitudes/cambioEstado', [SolicitudesController::class, 'changeState']);

    // ==========================================================

    Route::get('/misSolicitudes', [SolicitudesController::class, 'getMisSolicitudes']);
    Route::post('/misSolicitudes', [SolicitudesController::class, 'registrarSolicitud']);


    // RUTAS PARA COMPRAS 
    Route::get('/compras', [ComprasController::class, 'getCompras']);
    Route::post('/compras', [ComprasController::class, 'storeCompra']);
    Route::delete('/compras/{idCompra}', [ComprasController::class, 'eliminarCompra']);


    // RUTAS PARA PEDIDOS WEB KANBAN
    Route::get('/pedidosPendientes', [PedidosWebController::class, 'getPedidosPendientes']);
    Route::get('/pedidosEnProceso', [PedidosWebController::class, 'getPedidosEnProceso']);
    Route::get('/pedidosListos', [PedidosWebController::class, 'getPedidosListos']);

    Route::put('/pedidosPendientes/cambiarEstado', [PedidosWebController::class, 'cambiarEstado']);
    // RUTAS ACCIOENS PEDIDOS
    Route::get('/pedidosWeb/{id}', [PedidosWebController::class, 'getPedidosWeb']);
    Route::post('/pedidosWeb/notificarCliente', [PedidosWebController::class, 'notificarEstadoCliente']);



    // RUTAS PARA KARDEX 
    Route::get('/kardex', [KardexController::class, 'getKardex']);


    //RUTAS PARA INVENTARIO
    Route::get('/inventario', [InventarioController::class, 'getInventario']);
    Route::post('/inventario/{id}', [InventarioController::class, 'updateInventario']);

    Route::put('/inventario/activar/{id}', [InventarioController::class, 'activarInventario']);
    Route::put('/inventario/desactivar/{id}', [InventarioController::class, 'desactivarInventario']);

    Route::delete('/inventario/{id}', [InventarioController::class, 'deleteProduInventario']);

    // RUTAS PARA MODULO USUARIO
    Route::get('/usuarios', [UsuarioController::class, 'showUser']);
    Route::post('/storeUsuario', [UsuarioController::class, 'guardarUsuario']);
    Route::get('/getUsuarioById/{id}', [UsuarioController::class, 'getUsuarioById']);
    Route::put('/updateUsuario/{id}', [UsuarioController::class, 'updateUsuario']);
    Route::post('/usuarios/eliminar/{id}', [UsuarioController::class, 'eliminarUsuario']);
    Route::post('/usuarios/activar/{id}', [UsuarioController::class, 'activarUsuario']);
    Route::get('/usuarios/estadisticas', [UsuarioController::class, 'estadisticas']);

    // RUTAS PARA ALMACEN
    Route::get('/almacen', [AlmacenController::class, 'showAlmacen']);
    Route::put('/almacen/{id}', [AlmacenController::class, 'acualizarProducto']);
    Route::post('/almacen/save', [AlmacenController::class, 'saveAlmacen']);
    Route::post('/almacen/eliminar/{id}', [AlmacenController::class, 'eliminarActivo']);
    Route::post('/almacen/activar/{id}', [AlmacenController::class, 'activarActivo']);
    Route::post('/almacen/transferirInventario', [AlmacenController::class, 'transferirToinventario']);

    // RUTAS PARA OBTENER CARGOS,SALARIOS,AREAS, UNIDAD, CATEGORIA
    Route::get('/areas', [AreaController::class, 'getAreas']);
    Route::get('/areasAll', [AreaController::class, 'getAreasAll']);
    Route::get('/contrato', [PlanillaController::class, 'getTipoContrato']);
    Route::get('/deducciones', [PlanillaController::class, 'getDeducciones']);
    Route::get('/bonificaciones', [PlanillaController::class, 'getBonificaciones']);


    // RUTAS PARA OBTENER LA CATEGORIAS DE ALMACEN
    Route::get('/categorias', [CategoriasController::class, 'getCategorias']);
    Route::post('/categorias', [CategoriasController::class, 'saveCategorias']);
    Route::get('/categoriasAlmacen', [CategoriasController::class, 'getCategoriasAll']);
    Route::put('/categorias-estado/{id}', [AjustesAlmacenController::class, 'updateCategoriaEstado']);

    // RUTAS PARA OBTENER UNIDADES DE MEDIDA

    Route::get('/unidadMedida', [UnidadController::class, 'getUnidadMedida']);
    Route::post('/unidadMedida', [UnidadController::class, 'saveUnidadMedida']);

    Route::get('/unidadMedidaAll', [UnidadController::class, 'getUnidadMedidaAll']);
    Route::put('/unidadMedida-estado/{id}', [AjustesUniMedidaController::class, 'updateUniMedidaEstado']);


    Route::get('/departamento', [PlanillaController::class, 'getDepartamento']);
    Route::get('/provincia/{idDepartamento}', [PlanillaController::class, 'getProvincia']);
    Route::get('/distrito/{idProvincia}', [PlanillaController::class, 'getDistrito']);

    Route::get('/cargos', [CargoController::class, 'getCargos']);
    Route::get('/getSalarioCargo/{id}', [CargoController::class, 'getSalarioCargo']);
    Route::get('/horarios', [HorarioController::class, 'getHorarios']);

    // RUTAS CRUD PAR AREAS Y CARGOS
    Route::get('/areasAll', [AreaController::class, 'getAreasAll']);
    Route::post('/areasAll', [AreaController::class, 'saveArea']);

    // RUTAS PAARA SEDES
    Route::get('/sedesAll', [SedesController::class, 'getSedes']);


    // RUTAS PARA CARGOS Y ROLES
    Route::get('/rolesAll', [CargoController::class, 'getRolesAll']);
    Route::get('/cargosAll', [CargoController::class, 'getCargosAll']);
    Route::post('/cargosAll', [CargoController::class, 'saveCargos']);
    Route::put('/cargosAll', [CargoController::class, 'updateCargos']);


    // RUTAS PARA CAJAS
    Route::get('/cajas', [CajaController::class, 'getCajas']);
    Route::post('/cajas', [CajaController::class, 'saveCaja']);
    Route::put('/cajasUpdate/{id}', [CajaController::class, 'updateCaja']);
    Route::put('/cajas/{id}', [CajaController::class, 'suspenderCaja']);
    Route::put('/cajasActivar/{id}', [CajaController::class, 'activarCaja']);

    Route::get('/cajasAll', [CajaController::class, 'getCajasAll']);

    Route::post('/cajas/storeCajaApertura', [CajaController::class, 'storeCajaApertura']);
    Route::get('/caja/getCajaClose/{id}', [CajaController::class, 'getCajaClose']);
    Route::put('/cajas/closeCaja/{id}', [CajaController::class, 'closeCaja']);


    // RUTAS PARA AGREGAR A PREVENTA UNA MESA
    Route::get('/vender/getMesas', [VenderController::class, 'getMesas']);
    // ---

    // RUTAS PARA REALIZAR VENTAS O VENDER
    Route::get('/vender/getPlatos', [VenderController::class, 'getPlatos']);
    Route::post('/vender/addPlatosPreVentaMesa', [VenderController::class, 'addPlatosPreVentaMesa']);
    Route::delete('/vender/eliminarPreventaMesa/{idMesa}', [VenderController::class, 'eliminarPreventaMesa']);
    Route::get('/vender/getPreventaMesa/{idMesa}/{idCaja}', [VenderController::class, 'getPreventaMesa']);
    Route::get('/vender/mesasDisponibles', [VenderController::class, 'getMesasFree']);
    Route::put('/vender/transferirToMesa/{idMesa}', [VenderController::class, 'transferirToMesa']);
    Route::delete('/vender/preventa/deletePlatoPreventa/{idProducto}/{idMesa}', [VenderController::class, 'deletePlatoPreventa']);
    Route::post('/vender/preventa/realizarVenta', [VenderController::class, 'venderTodo']);

    // RUTAS PARA VISTA DE COCINA
    Route::get('/getPedidoCocina', [CocinaController::class, 'getPedidoCocina']);


    // RUTAS PARA MODULO PLANILLA - RECURSOS HUMANOS


    Route::get('/nomina', [PlanillaController::class, 'getPlanilla']);
    Route::get('/nominaEmpleado/{id}', [PlanillaController::class, 'getEmpleadoPerfil']);
    Route::post('/nomina/generarPago', [PlanillaController::class, 'generarPagosMensuales']);
    Route::post('/planilla', [PlanillaController::class, 'registroPlanillaEmpleado']);

    // RUTAS PARA ASISTENCIA
    Route::get('/asistencia', [AsistenciasController::class, 'getAsistencia']);

    // RUTAS PARA HORAS EXTRAS - PLANILLA
    Route::get('/horasExtras', [PlanillaController::class, 'getHorasExtras']);
    Route::post('/horasExtras', [PlanillaController::class, 'storeHorasExtras']);

    // RUTAS PARA AdelantoSueldo - PLANILLA
    Route::get('/adelantoSueldo', [PlanillaController::class, 'getAdelandoSueldo']);
    Route::post('/adelantoSueldo', [PlanillaController::class, 'storeAdelantoSueldo']);
    Route::post('/adelantoSueldo/pagar', [PlanillaController::class, 'confirmarPagoAdelantoSueldo']);

    // RUTAS PARA VACACIONES - PLANILLA
    Route::get('vacaciones', [PlanillaController::class, 'getVacaciones']);
    Route::post('vacaciones', [PlanillaController::class, 'storeVacaciones']);
    Route::post('vacaciones/venderDias', [PlanillaController::class, 'venderDias']);

    // GESTION DE PLATOS Y CATEGORIAS
    Route::get('/gestionPlatos/getPlatos', [GestionMenusController::class, 'getPlatos']);
    Route::post('/gestionPlatos/addPlatos', [GestionMenusController::class, 'addPlatos']);
    Route::put('/gestionPlatos/updatePlato/{id}', [GestionMenusController::class, 'updatePlato']);

    Route::get('/gestionPlatos/getCategoria', [GestionMenusController::class, 'getCategoria']);
    Route::get('/gestionPlatos/getCategoriaTrue', [GestionMenusController::class, 'getCategoriaTrue']);
    Route::post('/gestionPlatos/registerCategoria', [GestionMenusController::class, 'registerCategoria']);
    Route::put('/gestionPlatos/updateCategoria/{id}', [GestionMenusController::class, 'updateCategoria']);
    Route::get('/gestionPlatos/deleteCategoria/{id}', [GestionMenusController::class, 'deleteCategoria']);
    Route::get('/gestionPlatos/activarCategoria/{id}', [GestionMenusController::class, 'activarCategoria']);

    // GESTION DE COMBOS
    Route::post('/combos', [CombosController::class, 'registerCombo']);
    Route::put('/combos/{id}', [CombosController::class, 'updateCombo']);

    Route::put('/combos/desactivar/{id}', [CombosController::class, 'desactivarCombo']);
    Route::put('/combos/activar/{id}', [CombosController::class, 'activarCombo']);

    // GSTIONDE POROVEDORES
    Route::get('/proveedores/getProveedores', [GestionProveedoresController::class, 'getProveedores']);
    Route::post('/proveedores/addProveedores', [GestionProveedoresController::class, 'addProveedores']);
    Route::put('/proveedores/updateProveedores/{id}', [GestionProveedoresController::class, 'updateProveedores']);
    Route::delete('/proveedores/deleteProveedor/{id}', [GestionProveedoresController::class, 'deleteProveedor']);
    Route::put('/proveedores/activarProveedor/{id}', [GestionProveedoresController::class, 'activarProveedor']);

    // MODULO FINANZAS 
    Route::get('/finazas/getInformes', [FinanzasController::class, 'getInformes']);

    // MODULO FINANZAS - LIBRO DIARIO
    Route::get('/libroDiario', [FinanzasController::class, 'getLibroDiario']);

    // MODULO FINANZAS - LIBRO MAYOR
    Route::get('/libroMayor', [FinanzasController::class, 'getLibroMayor']);

    // MODULO FINANZAS - CUENTAS POR COBRAR
    Route::get('/cuentasPorCobrar', [FinanzasController::class, 'getCuentasPorCobrar']);
    Route::put('/cuentasPorCobrar/pagarCuota/{id}', [FinanzasController::class, 'marcarPagada']);

    // MODULO FINANZAS - CUENTAS POR PAGAR
    Route::get('/cuentasPorPagar', [FinanzasController::class, 'getCuentasPorPagar']);
    Route::put('/cuentasPorPagar/pagarCuota/{id}', [FinanzasController::class, 'pagarCuota']);

    // FINANAS- FIRMAR DOCUMENTOS PDF
    Route::post('/firmar-solicitud', [FinanzasController::class, 'addImageToPdf']);
    Route::get('/getDocFirmados', [FinanzasController::class, 'getDocFirmados']);
    Route::delete('/borrarDocumentoFirmado/{id}', [FinanzasController::class, 'borrarDocumento']);

    // MODULO FINANZAS - PRESUPUESTACION
    Route::get('/presupuestos', [FinanzasController::class, 'getPresupuestacion']);


    // MODULO INCIDENCIAS Y EVENTOS
    Route::get('/eventos', [EventosController::class, 'getEventos']);

    // ENDPOINT PARA TODOS LOS REPORTES DE MODULOS
    Route::post('/reportes/google-sheet', [ReportesController::class, 'generarReporteGoogleSheets']);

    // ENDPOINT PARA OBTENER CALCULOS DE IA o LOGICA
    Route::get('/ventasIA', [VentasController::class, 'getVentasIA']);
    Route::post('/recomendaciones', [VentasController::class, 'generarRecomendaciones']);

    Route::get('/combos/ia', [CombosController::class, 'generarComboIA']);


    // RUTAS PARA EL MODULO DE CONFIGURACION

    // INTEGRACIONES
    Route::put('/configuraciones/{id}', [ConfiguracionController::class, 'configurarIntegracion']);
    Route::put('/configuracionesOpenAi/{id}', [ConfiguracionController::class, 'configurarOpenAi']);
    Route::put('/configuracionesTwilio/{id}', [ConfiguracionController::class, 'configurarTwilio']);
    Route::post('/configuracionesSunat/{id}', [ConfiguracionController::class, 'configurarSunat']);


    Route::put('/activarServicio/{id}', [ConfiguracionController::class, 'activarServicio']);

    Route::get('/getEstadoConfig/{nombreConfi}', [ConfiguracionController::class, 'getEstadoConfig']);


    // RUTAS PARA TODOS LOS REPORTES A EXCEL

    Route::get('/reporteVentasTodo', [ReportesController::class, 'reporteVentasExcel']);
    Route::get('/reporteVentasHOY', [ReportesController::class, 'reporteVentasHOY']);
    Route::get('/reporteInventarioTodo', [ReportesController::class, 'reporteInventarioTodo']);
    Route::get('/reportePlatosTodos', [ReportesController::class, 'reportePlatosTodos']);
    Route::get('/reporteAlmacenTodo', [ReportesController::class, 'reporteAlmacenTodo']);
    Route::get('/reporteProveedores', [ReportesController::class, 'reporteProveedores']);
    Route::get('/reporteCompras', [ReportesController::class, 'reporteCompras']);
    Route::get('/reporteUsuarios', [ReportesController::class, 'reporteUsuarios']);


    Route::get('/reporteKardexExcelSalida', [ReportesController::class, 'reporteKardexSalida']);
    Route::get('/reporteKardexExcelEntrada', [ReportesController::class, 'reporteKardexEntrada']);
});
