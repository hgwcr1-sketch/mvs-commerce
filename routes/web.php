<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Controladores
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ActiveBranchController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\BranchController;

// Catálogos
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerContactController;
use App\Http\Controllers\CustomerAddressController;
use App\Http\Controllers\SupplierController;

// Inventario
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryAdjustmentController;
use App\Http\Controllers\KardexController;
use App\Http\Controllers\TransferController;

// Compras
use App\Http\Controllers\PurchaseXmlImportController;
use App\Http\Controllers\PurchaseImportController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseOrderController;

// Ventas
use App\Http\Controllers\SaleController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LayawayController;
use App\Http\Controllers\ReturnController;

// Finanzas
use App\Http\Controllers\AccountsReceivableController;
use App\Http\Controllers\AccountsPayableController;

// Administración
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\CompanyCashSettingController;
use App\Http\Controllers\CashSessionController;
use App\Http\Controllers\CashMovementController;
use App\Http\Controllers\CashClosingController;
use App\Http\Controllers\CashSessionHistoryController;
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\DataImportController;
/*
|--------------------------------------------------------------------------
| Autenticación
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {

    Route::get('/login', [LoginController::class, 'create'])
        ->name('login');

    Route::post('/login', [LoginController::class, 'store'])
        ->name('login.store');

    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])
        ->name('password.request');

        Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])
    ->name('password.email');

    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])
    ->name('password.reset');

    Route::post('/reset-password', [ResetPasswordController::class, 'store'])
    ->name('password.update');

});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

    Route::post('/sucursal-activa', [ActiveBranchController::class, 'update'])
    ->middleware('auth')
    ->name('branch.active.update');

    Route::middleware(['auth', 'active.company'])->group(function () {
        

/*
|--------------------------------------------------------------------------
| Dashboard
|--------------------------------------------------------------------------
*/

Route::redirect('/', '/dashboard');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->name('dashboard');

Route::middleware(['active.branch', 'permission:pos.acceder'])->group(function () {
    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::get('/pos/productos/buscar', [PosController::class, 'searchProducts'])
        ->name('pos.products.search');
    Route::get('/pos/clientes/buscar', [PosController::class, 'searchCustomers'])
        ->name('pos.customers.search');
    Route::post('/pos/clientes/rapido', [PosController::class, 'storeQuickCustomer'])
        ->middleware('permission:clientes.crear')
        ->name('pos.customers.quick-store');
    Route::post('/pos/cobrar', [PosController::class, 'checkout'])
        ->middleware('permission:ventas.crear')
        ->name('pos.checkout');
    Route::middleware('permission:ventas.crear')->group(function () {
        Route::post('/pos/suspender', [PosController::class, 'storeSuspended'])->name('pos.suspended.store');
        Route::get('/pos/suspendidas', [PosController::class, 'suspendedIndex'])->name('pos.suspended.index');
        Route::post('/pos/suspendidas/{suspendedSale}/recuperar', [PosController::class, 'recoverSuspended'])->name('pos.suspended.recover');
        Route::post('/pos/suspendidas/{suspendedSale}/liberar', [PosController::class, 'releaseSuspended'])->name('pos.suspended.release');
        Route::post('/pos/suspendidas/{suspendedSale}/volver-a-suspender', [PosController::class, 'resuspendSale'])->name('pos.suspended.resuspend');
        Route::post('/pos/suspendidas/{suspendedSale}/cancelar', [PosController::class, 'cancelSuspended'])->middleware('permission:ventas.anular')->name('pos.suspended.cancel');
    });
    Route::get('/pos/ventas/{sale}/comprobante', [PosController::class, 'receipt'])
        ->name('pos.receipt');
});

Route::middleware('active.branch')->group(function () {
    Route::get('/caja', [CashSessionController::class, 'index'])
        ->name('cash.index');
    Route::get('/caja/abrir', [CashSessionController::class, 'create'])
        ->middleware('permission:caja.abrir')
        ->name('cash.open.create');
    Route::post('/caja/abrir', [CashSessionController::class, 'store'])
        ->middleware('permission:caja.abrir')
        ->name('cash.open.store');
    Route::get('/caja/historial', [CashSessionHistoryController::class, 'index'])
        ->middleware('permission:caja.ver')
        ->name('cash.history.index');
    Route::get('/caja/historial/{cashSession}', [CashSessionHistoryController::class, 'show'])
        ->middleware('permission:caja.ver')
        ->name('cash.history.show');
    Route::post('/caja/historial/{cashSession}/correos/{notification}/reintentar', [CashSessionHistoryController::class, 'retry'])
        ->middleware('permission:caja.administrar')
        ->name('cash.history.mail.retry');
    Route::get('/caja/sesiones/{cashSession}/movimientos', [CashMovementController::class, 'index'])
        ->middleware('permission:caja.ver')
        ->name('cash.movements.index');
    Route::get('/caja/sesiones/{cashSession}/movimientos/crear', [CashMovementController::class, 'create'])
        ->middleware('permission:caja.movimientos')
        ->name('cash.movements.create');
    Route::post('/caja/sesiones/{cashSession}/movimientos', [CashMovementController::class, 'store'])
        ->middleware('permission:caja.movimientos')
        ->name('cash.movements.store');
    Route::post('/caja/sesiones/{cashSession}/cierre/iniciar', [CashClosingController::class, 'start'])->middleware('permission:caja.cerrar')->name('cash.closing.start');
    Route::get('/caja/sesiones/{cashSession}/cierre', [CashClosingController::class, 'create'])->middleware('permission:caja.cerrar')->name('cash.closing.create');
    Route::post('/caja/sesiones/{cashSession}/cierre', [CashClosingController::class, 'submit'])->middleware('permission:caja.cerrar')->name('cash.closing.submit');
    Route::post('/caja/sesiones/{cashSession}/cierre/cancelar', [CashClosingController::class, 'cancel'])->middleware('permission:caja.cerrar')->name('cash.closing.cancel');
    Route::get('/caja/sesiones/{cashSession}/cierre/resultado', [CashClosingController::class, 'show'])->name('cash.closing.show');
    Route::get('/caja/sesiones/{cashSession}/cierre/autorizar', [CashClosingController::class, 'authorizeForm'])->middleware('permission:caja.autorizar_diferencia')->name('cash.closing.authorize.form');
    Route::post('/caja/sesiones/{cashSession}/cierre/autorizar', [CashClosingController::class, 'authorize'])->middleware('permission:caja.autorizar_diferencia')->name('cash.closing.authorize');
});

/*
|--------------------------------------------------------------------------
| Catálogos
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Productos
|--------------------------------------------------------------------------
*/

Route::get('/productos-buscar', [ProductController::class, 'search'])
    ->middleware(['active.branch', 'permission:productos.ver'])
    ->name('productos.search');

Route::resource('productos', ProductController::class)
    ->only(['create', 'store'])
    ->middleware(['active.branch', 'permission:productos.crear']);

Route::resource('productos', ProductController::class)
    ->only(['index', 'show'])
    ->middleware(['active.branch', 'permission:productos.ver']);

Route::resource('productos', ProductController::class)
    ->only(['edit', 'update'])
    ->middleware(['active.branch', 'permission:productos.editar']);

Route::resource('productos', ProductController::class)
    ->only(['destroy'])
    ->middleware(['active.branch', 'permission:productos.eliminar']);

/*
|--------------------------------------------------------------------------
| Categorías
|--------------------------------------------------------------------------
*/

Route::resource('categorias', ProductCategoryController::class)
    ->only(['index'])
    ->middleware('permission:categorias.ver');

Route::resource('categorias', ProductCategoryController::class)
    ->only(['create', 'store'])
    ->middleware('permission:categorias.crear');

Route::resource('categorias', ProductCategoryController::class)
    ->only(['edit', 'update'])
    ->middleware('permission:categorias.editar');

Route::resource('categorias', ProductCategoryController::class)
    ->only(['destroy'])
    ->middleware('permission:categorias.eliminar');


/*
|--------------------------------------------------------------------------
| Marcas
|--------------------------------------------------------------------------
*/

Route::resource('marcas', BrandController::class)
    ->only(['index'])
    ->middleware('permission:marcas.ver');

Route::resource('marcas', BrandController::class)
    ->only(['create', 'store'])
    ->middleware('permission:marcas.crear');

Route::resource('marcas', BrandController::class)
    ->only(['edit', 'update'])
    ->middleware('permission:marcas.editar');

Route::resource('marcas', BrandController::class)
    ->only(['destroy'])
    ->middleware('permission:marcas.eliminar');


/*
|--------------------------------------------------------------------------
| Unidades de Medida
|--------------------------------------------------------------------------
*/

Route::resource('unidades', UnitController::class)
    ->only(['index'])
    ->middleware('permission:unidades.ver');

Route::resource('unidades', UnitController::class)
    ->only(['create', 'store'])
    ->middleware('permission:unidades.crear');

Route::resource('unidades', UnitController::class)
    ->only(['edit', 'update'])
    ->middleware('permission:unidades.editar');

Route::resource('unidades', UnitController::class)
    ->only(['destroy'])
    ->middleware('permission:unidades.eliminar');

Route::get('/clientes-buscar', [CustomerController::class, 'search'])
    ->name('clientes.search');

    Route::patch('/clientes/{cliente}/estado', [CustomerController::class, 'toggleStatus'])
    ->name('clientes.toggle-status');

    Route::post('/clientes/{cliente}/contactos', [CustomerContactController::class, 'store'])
    ->name('clientes.contactos.store');

    Route::post('/clientes/{cliente}/direcciones', [CustomerAddressController::class, 'store'])
    ->name('clientes.direcciones.store');

Route::patch('/clientes/{cliente}/direcciones/{direccion}/principal', [CustomerAddressController::class, 'setPrimary'])
    ->name('clientes.direcciones.principal');

Route::delete('/clientes/{cliente}/direcciones/{direccion}', [CustomerAddressController::class, 'destroy'])
    ->name('clientes.direcciones.destroy');

    Route::patch('/clientes/{cliente}/contactos/{contacto}/principal', [CustomerContactController::class, 'setPrimary'])
    ->name('clientes.contactos.principal');

Route::delete('/clientes/{cliente}/contactos/{contacto}', [CustomerContactController::class, 'destroy'])
    ->name('clientes.contactos.destroy');

Route::resource('clientes', CustomerController::class);
Route::get('/ubicaciones/provincias/{country}', [CustomerController::class, 'provinces'])
    ->name('ubicaciones.provincias');

Route::get('/ubicaciones/cantones/{province}', [CustomerController::class, 'cantons'])
    ->name('ubicaciones.cantones');

Route::get('/ubicaciones/distritos/{canton}', [CustomerController::class, 'districts'])
    ->name('ubicaciones.distritos');
Route::get('/clientes/provincias/{country}', [CustomerController::class, 'provinces'])
    ->name('clientes.provincias');

Route::get('/clientes/cantones/{province}', [CustomerController::class, 'cantons'])
    ->name('clientes.cantones');

Route::get('/clientes/distritos/{canton}', [CustomerController::class, 'districts'])
    ->name('clientes.distritos');

   Route::get('/proveedores-buscar', [SupplierController::class, 'search'])
    ->name('proveedores.search')
    ->middleware('permission:proveedores.ver');

Route::patch('/proveedores/{proveedore}/toggle-status', [SupplierController::class, 'toggleStatus'])
    ->name('proveedores.toggle-status')
    ->middleware('permission:proveedores.editar');

Route::resource('proveedores', SupplierController::class)
    ->middlewareFor(['index', 'show'], 'permission:proveedores.ver')
    ->middlewareFor(['create', 'store'], 'permission:proveedores.crear')
    ->middlewareFor(['edit', 'update'], 'permission:proveedores.editar')
    ->middlewareFor(['destroy'], 'permission:proveedores.eliminar');
/*
|--------------------------------------------------------------------------
| Inventario
|--------------------------------------------------------------------------
*/

Route::resource('inventario', InventoryController::class)
    ->only(['index'])
    ->middleware('permission:inventario.ver');

Route::resource('ajustes-inventario', InventoryAdjustmentController::class)
    ->only(['create', 'store'])
    ->middleware('permission:inventario.ajustar');

Route::resource('kardex', KardexController::class)
    ->only(['index'])
    ->middleware('permission:inventario.kardex');

Route::resource('transferencias', TransferController::class)
    ->only(['index', 'create', 'store'])
    ->middleware('permission:inventario.transferir');

/*
|--------------------------------------------------------------------------
| Compras
|--------------------------------------------------------------------------
*/

Route::middleware(['active.branch', 'permission:compras.crear'])->group(function () {

    Route::get(
        '/compras/importacion/producto-nuevo',
        [PurchaseImportController::class, 'createProduct']
    )->name('compras.import.product.create');

    Route::post(
    '/compras/importacion/producto-nuevo',
    [PurchaseImportController::class, 'storeProduct']
)->name('compras.import.product.store');

    Route::post(
        '/compras/importacion/confirmar',
        [PurchaseImportController::class, 'confirm']
    )->name('compras.import.confirm');

    Route::post(
        '/compras/importacion/proveedor-creado',
        [PurchaseImportController::class, 'supplierCreated']
    )->name('compras.import.supplier.created');

    Route::get('/compras/importacion/revision',
        [PurchaseImportController::class, 'review']
    )->name('compras.import.review');

    Route::post('/compras/importar-excel',
        [PurchaseImportController::class, 'store']
    )->name('compras.import.excel');

    Route::get('/compras/importar-excel/plantilla',
        [PurchaseImportController::class, 'downloadTemplate']
    )->name('compras.import.template');
});

Route::get('/compras-buscar-productos', [PurchaseController::class, 'searchProducts'])
    ->middleware(['active.branch', 'permission:compras.ver'])
    ->name('compras.search-products');

Route::get('/compras/importar-xml',
    [PurchaseXmlImportController::class, 'create'])
    ->middleware(['active.branch', 'permission:compras.crear'])
    ->name('compras.import.xml.create');

Route::post('/compras/importar-xml',
    [PurchaseXmlImportController::class, 'store']
)->name('compras.import.xml');

Route::resource('compras', PurchaseController::class)
    ->middleware('active.branch')
    ->middlewareFor(['index', 'show'], 'permission:compras.ver')
    ->middlewareFor(['create', 'store'], 'permission:compras.crear')
    ->middlewareFor(['edit', 'update'], 'permission:compras.editar')
    ->middlewareFor(['destroy'], 'permission:compras.anular');


Route::get('compras/{compra}/pdf', [PurchaseController::class, 'pdf'])
    ->middleware(['active.branch', 'permission:compras.ver'])
    ->name('compras.pdf');

    Route::get('compras/{compra}/print', [PurchaseController::class, 'print'])
    ->middleware(['active.branch', 'permission:compras.ver'])
    ->name('compras.print');


Route::resource('ordenes-compra', PurchaseOrderController::class);

/*
|--------------------------------------------------------------------------
| Ventas
|--------------------------------------------------------------------------
*/

Route::resource('ventas', SaleController::class)
    ->only(['index', 'show'])
    ->middleware(['active.branch', 'permission:ventas.ver']);

Route::post('/ventas/{venta}/anular', [SaleController::class, 'void'])
    ->middleware(['active.branch', 'permission:ventas.anular'])
    ->name('ventas.void');

Route::resource('cotizaciones', QuoteController::class);

Route::resource('facturas', InvoiceController::class);

Route::resource('apartados', LayawayController::class);

Route::get('/ventas/{venta}/devolucion', [ReturnController::class, 'create'])
    ->middleware(['active.branch', 'permission:devoluciones.crear'])
    ->name('ventas.return.create');

Route::post('/ventas/{venta}/devolucion', [ReturnController::class, 'store'])
    ->middleware(['active.branch', 'permission:devoluciones.crear'])
    ->name('ventas.return.store');
/*
|--------------------------------------------------------------------------
| Finanzas
|--------------------------------------------------------------------------
*/

Route::resource('cuentas-por-cobrar', AccountsReceivableController::class);

Route::resource('cuentas-por-pagar', AccountsPayableController::class);

/*
|--------------------------------------------------------------------------
| Administración
|--------------------------------------------------------------------------
*/

Route::resource('usuarios', UserController::class)
    ->middlewareFor(['index', 'show'], 'permission:usuarios.ver')
    ->middlewareFor(['create', 'store'], 'permission:usuarios.crear')
    ->middlewareFor(['edit', 'update'], 'permission:usuarios.editar')
    ->middlewareFor(['destroy'], 'permission:usuarios.eliminar');

Route::resource('roles', RoleController::class)
    ->middlewareFor(['index', 'show'], 'permission:roles.ver')
    ->middlewareFor(['create'], 'permission:roles.crear')
    ->middlewareFor(['store'], [
        'permission:roles.crear',
        'permission:roles.permisos',
    ])
    ->middlewareFor(['edit'], 'permission:roles.editar')
    ->middlewareFor(['update'], [
        'permission:roles.editar',
        'permission:roles.permisos',
    ])
    ->middlewareFor(['destroy'], 'permission:roles.eliminar');

Route::resource('sucursales', BranchController::class)
    ->parameters(['sucursales' => 'branch'])
    ->names('branches');

Route::resource('empresa', CompanyController::class);

Route::prefix('configuracion/caja')
    ->name('settings.cash.')
    ->middleware('permission:caja.administrar')
    ->group(function () {
        Route::get('/', [CompanyCashSettingController::class, 'edit'])->name('edit');
        Route::match(['put', 'patch'], '/', [CompanyCashSettingController::class, 'update'])->name('update');
    });

Route::resource('configuracion', SettingController::class);

Route::prefix('configuracion/pos/formas-pago')
    ->name('settings.pos.payment-methods.')
    ->middleware('permission:formas_pago.administrar')
    ->group(function () {
        Route::get('/', [PaymentMethodController::class, 'index'])->name('index');
        Route::get('/crear', [PaymentMethodController::class, 'create'])->name('create');
        Route::post('/', [PaymentMethodController::class, 'store'])->name('store');
        Route::get('/{payment_method}/editar', [PaymentMethodController::class, 'edit'])->name('edit');
        Route::put('/{payment_method}', [PaymentMethodController::class, 'update'])->name('update');
        Route::patch('/{payment_method}/estado', [PaymentMethodController::class, 'toggleStatus'])->name('toggle-status');
        Route::delete('/{payment_method}', [PaymentMethodController::class, 'destroy'])->name('destroy');
    });

Route::prefix('configuracion/caja/cajas')
    ->name('settings.cash-registers.')
    ->middleware('permission:caja.administrar')
    ->group(function () {
        Route::get('/', [CashRegisterController::class, 'index'])->name('index');
        Route::get('/crear', [CashRegisterController::class, 'create'])->name('create');
        Route::post('/', [CashRegisterController::class, 'store'])->name('store');
        Route::get('/{cashRegister}/editar', [CashRegisterController::class, 'edit'])->name('edit');
        Route::match(['put', 'patch'], '/{cashRegister}', [CashRegisterController::class, 'update'])->name('update');
        Route::patch('/{cashRegister}/estado', [CashRegisterController::class, 'toggleStatus'])->name('toggle-status');
    });

Route::resource('agenda', AgendaController::class);

Route::get('/importar-datos', [DataImportController::class, 'index'])
    ->name('importaciones.index');

Route::get('/importar-datos/inventario', [DataImportController::class, 'inventory'])
    ->middleware('permission:inventario.ver')
    ->name('importaciones.inventario');

Route::get('/importar-datos/inventario/plantilla', [DataImportController::class, 'inventoryTemplate'])
    ->middleware('permission:inventario.ver')
    ->name('importaciones.inventario.template');

Route::get('/importar-datos/inventario/ejemplo', [DataImportController::class, 'inventoryExample'])
    ->middleware('permission:inventario.ver')
    ->name('importaciones.inventario.example');

Route::get('/importar-datos/inventario/instrucciones', [DataImportController::class, 'inventoryInstructions'])
    ->middleware('permission:inventario.ver')
    ->name('importaciones.inventario.instructions');

Route::post('/importar-datos/inventario/revisar', [DataImportController::class, 'inventoryPreview'])
    ->middleware('permission:inventario.ver')
    ->name('importaciones.inventario.preview');

Route::post('/importar-datos/inventario/confirmar', [DataImportController::class, 'inventoryImport'])
    ->middleware('permission:inventario.ver')
    ->name('importaciones.inventario.import');  
Route::resource('reportes', ReportController::class);

});
