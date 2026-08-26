<?php

use App\Http\Controllers\AccountsPayableController;
/*
|--------------------------------------------------------------------------
| Controladores
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\AccountsReceivableController;
use App\Http\Controllers\ActiveBranchController;
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\BranchController;
// Catálogos
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CashClosingController;
use App\Http\Controllers\CashMovementController;
use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\CashSessionController;
use App\Http\Controllers\CashSessionHistoryController;
use App\Http\Controllers\CompanyCashSettingController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CustomerAddressController;
// Inventario
use App\Http\Controllers\CustomerContactController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataCenterController;
use App\Http\Controllers\DataExportController;
use App\Http\Controllers\DataImportController;
// Compras
use App\Http\Controllers\InventoryAdjustmentController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\KardexController;
// Ventas
use App\Http\Controllers\LayawayController;
use App\Http\Controllers\LoyaltyAdjustmentController;
use App\Http\Controllers\LoyaltyCustomerPortalController;
use App\Http\Controllers\LoyaltyDashboardController;
use App\Http\Controllers\LoyaltyMovementController;
use App\Http\Controllers\LoyaltyMultiplierController;
use App\Http\Controllers\LoyaltyOpportunityController;
use App\Http\Controllers\LoyaltyPortalAccessController;
use App\Http\Controllers\LoyaltyPortalSessionController;
use App\Http\Controllers\LoyaltyPortalManagementController;
use App\Http\Controllers\LoyaltyPromotionController;
use App\Http\Controllers\LoyaltyRewardController;
use App\Http\Controllers\LoyaltyRewardRedemptionController;
use App\Http\Controllers\LoyaltyRuleCenterController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PlatformAdminController;
// Finanzas
use App\Http\Controllers\PosController;
use App\Http\Controllers\SaleReceiptMailController;
// Administración
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\LabelCenterController;
use App\Http\Controllers\ProductSupplierController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseImportController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseXmlImportController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ReportCenterController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

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

/*
|--------------------------------------------------------------------------
| Portal del cliente de Fidelización (F33/F34)
|--------------------------------------------------------------------------
|
| Acceso público mediante token seguro asociado a empresa y cliente.
| El token se almacena solo como hash; la URL no contiene IDs internos
| ni datos personales. Limitada por tasa para frenar fuerza bruta.
*/

Route::get('/fidelidad/portal/acceso/{token}', [LoyaltyPortalAccessController::class, 'access'])
    ->middleware('throttle:30,1')
    ->name('loyalty.portal.access');

Route::prefix('portal-clientes/{company}')->name('loyalty.customer.')->middleware('throttle:30,1')->group(function () {
    Route::get('/ingresar', [LoyaltyPortalSessionController::class, 'loginForm'])->name('login');
    Route::post('/ingresar', [LoyaltyPortalSessionController::class, 'login'])->name('login.store');
    Route::get('/', [LoyaltyPortalSessionController::class, 'home'])->name('home');
    Route::post('/salir', [LoyaltyPortalSessionController::class, 'logout'])->name('logout');
    Route::patch('/perfil', [LoyaltyPortalSessionController::class, 'profile'])->name('profile');
    Route::get('/compras/{sale}/comprobante.pdf', [LoyaltyPortalSessionController::class, 'receiptPdf'])->name('receipt.pdf');
    Route::post('/compras/{sale}/comprobante/correo', [LoyaltyPortalSessionController::class, 'sendReceipt'])->name('receipt.mail');
    Route::get('/recuperar', [LoyaltyPortalSessionController::class, 'forgotForm'])->name('password.request');
    Route::post('/recuperar', [LoyaltyPortalSessionController::class, 'forgot'])->name('password.email');
    Route::get('/restablecer/{token}', [LoyaltyPortalSessionController::class, 'resetForm'])->name('password.reset');
    Route::post('/restablecer/{token}', [LoyaltyPortalSessionController::class, 'reset'])->name('password.update');
});
Route::post('/portal-clientes/activar', [LoyaltyPortalSessionController::class, 'activate'])->middleware('throttle:10,1')->name('loyalty.customer.activate');

Route::post('/sucursal-activa', [ActiveBranchController::class, 'update'])
    ->middleware('auth')
    ->name('branch.active.update');

Route::prefix('panel-maestro')->name('platform.')->middleware(['auth', 'platform.admin'])->group(function () {
    Route::get('/', [PlatformAdminController::class, 'index'])->name('index');
    Route::get('/empresas-nueva', [PlatformAdminController::class, 'createCompany'])->name('companies.create');
    Route::post('/empresas', [PlatformAdminController::class, 'storeCompany'])->name('companies.store');
    Route::get('/empresas/{company}', [PlatformAdminController::class, 'show'])->name('companies.show');
    Route::patch('/empresas/{company}', [PlatformAdminController::class, 'updateCompany'])->name('companies.update');
    Route::patch('/empresas/{company}/sucursales/{branch}', [PlatformAdminController::class, 'updateBranch'])->name('branches.update');
    Route::patch('/empresas/{company}/usuarios/{user}', [PlatformAdminController::class, 'updateUser'])->name('users.update');
    Route::patch('/empresas/{company}/modulos', [PlatformAdminController::class, 'updateModules'])->name('modules.update');
});

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
        Route::get('/pos/fidelidad/consulta', [PosController::class, 'loyaltySummary'])
            ->name('pos.loyalty.summary');
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
    });

    Route::middleware('active.branch')->group(function () {
        Route::get('/pos/ventas/{sale}/comprobante', [PosController::class, 'receipt'])->name('pos.receipt');
        Route::get('/pos/ventas/{sale}/comprobante.pdf', [PosController::class, 'receiptPdf'])->name('pos.receipt.pdf');
        Route::post('/pos/ventas/{sale}/comprobante/correo', SaleReceiptMailController::class)->name('pos.receipt.mail');
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

    Route::middleware(['active.branch', 'permission:productos.etiquetas.imprimir'])->prefix('productos/etiquetas')->name('labels.')->group(function () {
        Route::get('/', [LabelCenterController::class, 'index'])->name('index');
        Route::post('/vista-previa', [LabelCenterController::class, 'preview'])->name('preview');
        Route::patch('/productos/{product}', [LabelCenterController::class, 'updateProduct'])->name('products.update');
        Route::put('/configuracion', [LabelCenterController::class, 'updateSettings'])
            ->middleware('permission:productos.etiquetas.configurar')->name('settings.update');
    });

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

    Route::get('/productos/{producto}/proveedores', [ProductSupplierController::class, 'index'])
        ->middleware(['active.branch', 'permission:productos.ver'])
        ->name('productos.proveedores.index');

    Route::post('/productos/{producto}/proveedores', [ProductSupplierController::class, 'store'])
        ->middleware(['active.branch', 'permission:productos.editar'])
        ->name('productos.proveedores.store');

    Route::put('/productos/{producto}/proveedores/{productSupplier}', [ProductSupplierController::class, 'update'])
        ->middleware(['active.branch', 'permission:productos.editar'])
        ->name('productos.proveedores.update');

    Route::delete('/productos/{producto}/proveedores/{productSupplier}', [ProductSupplierController::class, 'destroy'])
        ->middleware(['active.branch', 'permission:productos.editar'])
        ->name('productos.proveedores.destroy');

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

    Route::prefix('fidelidad/kardex')
        ->name('loyalty.kardex.')
        ->middleware('permission:fidelidad.ver')
        ->group(function () {
            Route::get('/', [LoyaltyMovementController::class, 'index'])->name('index');
            Route::get('/{movement}', [LoyaltyMovementController::class, 'show'])->name('show');
        });

    Route::prefix('fidelidad')->name('loyalty.')->group(function () {
        Route::get('/', LoyaltyDashboardController::class)->middleware('permission:fidelidad.dashboard')->name('dashboard');
        Route::get('/portal/{cliente}', [LoyaltyCustomerPortalController::class, 'show'])
            ->middleware('permission:fidelidad.ver')
            ->name('portal.show');
        Route::prefix('portal-clientes')->name('portal-management.')->group(function () {
            Route::get('/', [LoyaltyPortalManagementController::class, 'index'])->name('index');
            Route::get('/vista-previa/{customer}', [LoyaltyPortalManagementController::class, 'preview'])->middleware('permission:fidelidad.portal.ver')->name('preview');
            Route::put('/configuracion', [LoyaltyPortalManagementController::class, 'updateSetting'])->middleware('permission:fidelidad.portal.configurar')->name('settings.update');
            Route::post('/publicaciones', [LoyaltyPortalManagementController::class, 'storePost'])->middleware('permission:fidelidad.portal.contenido')->name('posts.store');
            Route::put('/publicaciones/{post}', [LoyaltyPortalManagementController::class, 'updatePost'])->middleware('permission:fidelidad.portal.contenido')->name('posts.update');
            Route::delete('/publicaciones/{post}', [LoyaltyPortalManagementController::class, 'destroyPost'])->middleware('permission:fidelidad.portal.contenido')->name('posts.destroy');
            Route::post('/enlaces', [LoyaltyPortalManagementController::class, 'storeLink'])->middleware('permission:fidelidad.portal.enlaces')->name('links.store');
            Route::put('/enlaces/{link}', [LoyaltyPortalManagementController::class, 'updateLink'])->middleware('permission:fidelidad.portal.enlaces')->name('links.update');
            Route::delete('/enlaces/{link}', [LoyaltyPortalManagementController::class, 'destroyLink'])->middleware('permission:fidelidad.portal.enlaces')->name('links.destroy');
        });
        Route::get('/oportunidades', [LoyaltyOpportunityController::class, 'index'])->middleware('permission:fidelidad.oportunidades')->name('opportunities.index');
        Route::post('/oportunidades/{customer}/contactar', [LoyaltyOpportunityController::class, 'contact'])->middleware('permission:fidelidad.contactar')->name('opportunities.contact');
        Route::get('/configuracion', [SettingController::class, 'loyaltySettings'])->middleware('permission:fidelidad.configuracion')->name('settings');
        Route::get('/reglas', [LoyaltyRuleCenterController::class, 'index'])->middleware('permission:fidelidad.configuracion')->name('rules.index');
        Route::put('/reglas', [LoyaltyRuleCenterController::class, 'update'])->middleware('permission:fidelidad.configuracion')->name('rules.update');
        Route::middleware('permission:fidelidad.ajustes')->prefix('ajustes')->name('adjustments.')->group(function () {
            Route::get('/', [LoyaltyAdjustmentController::class, 'index'])->name('index');
            Route::post('/', [LoyaltyAdjustmentController::class, 'store'])->name('store');
        });
        Route::middleware('permission:fidelidad.portal')->prefix('accesos')->name('accesses.')->group(function () {
            Route::get('/', [LoyaltyPortalAccessController::class, 'index'])->name('index');
            Route::post('/', [LoyaltyPortalAccessController::class, 'store'])->name('store');
            Route::patch('/{cliente}/revocar', [LoyaltyPortalAccessController::class, 'revoke'])->name('revoke');
        });
        Route::middleware('permission:fidelidad.multiplicadores')->prefix('multiplicadores')->name('multipliers.')->group(function () {
            Route::get('/', [LoyaltyMultiplierController::class, 'index'])->name('index');
            Route::post('/', [LoyaltyMultiplierController::class, 'store'])->name('store');
            Route::put('/{multiplier}', [LoyaltyMultiplierController::class, 'update'])->name('update');
            Route::patch('/{multiplier}/estado', [LoyaltyMultiplierController::class, 'toggle'])->name('toggle');
        });
        Route::middleware('permission:fidelidad.premios')->prefix('premios')->name('rewards.')->group(function () {
            Route::get('/', [LoyaltyRewardController::class, 'index'])->name('index');
            Route::post('/', [LoyaltyRewardController::class, 'store'])->name('store');
            Route::put('/{reward}', [LoyaltyRewardController::class, 'update'])->name('update');
            Route::patch('/{reward}/estado', [LoyaltyRewardController::class, 'toggle'])->name('toggle');
        });
        Route::middleware('permission:fidelidad.promociones')->prefix('promociones')->name('promotions.')->group(function () {
            Route::get('/', [LoyaltyPromotionController::class, 'index'])->name('index');
            Route::post('/', [LoyaltyPromotionController::class, 'store'])->name('store');
            Route::put('/{promotion}', [LoyaltyPromotionController::class, 'update'])->name('update');
            Route::patch('/{promotion}/estado', [LoyaltyPromotionController::class, 'toggle'])->name('toggle');
        });
        Route::middleware('permission:fidelidad.canjes')->prefix('canjes')->name('redemptions.')->group(function () {
            Route::get('/', [LoyaltyRewardRedemptionController::class, 'index'])->name('index');
            Route::post('/', [LoyaltyRewardRedemptionController::class, 'store'])->name('store');
        });
    });

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
    )->middleware(['active.branch', 'permission:compras.crear'])
        ->name('compras.import.xml');

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

    Route::get('/pedidos/preparar-compra', [PurchaseOrderController::class, 'prepare'])->middleware(['active.branch', 'permission:pedidos.preparar_compra'])->name('pedidos.preparar-compra');
    Route::post('/pedidos/preparar-compra', [PurchaseOrderController::class, 'store'])->middleware(['active.branch', 'permission:pedidos.preparar_compra'])->name('pedidos.preparar-compra.store');
    Route::get('/ordenes-compra', [PurchaseOrderController::class, 'index'])->middleware(['active.branch', 'permission:compras.ordenes'])->name('ordenes-compra.index');
    Route::get('/ordenes-compra/{purchaseOrder}/convertir', [PurchaseOrderController::class, 'convertForm'])->middleware(['active.branch', 'permission:compras.ordenes', 'permission:compras.crear'])->name('ordenes-compra.convertir');
    Route::post('/ordenes-compra/{purchaseOrder}/convertir', [PurchaseOrderController::class, 'convert'])->middleware(['active.branch', 'permission:compras.ordenes', 'permission:compras.crear'])->name('ordenes-compra.convertir.store');
    Route::get('/ordenes-compra/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware(['active.branch', 'permission:compras.ordenes'])->name('ordenes-compra.show');

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

    Route::middleware('active.branch')->group(function () {
        Route::get('/pedidos', [OrderController::class, 'index'])->middleware('permission:pedidos.ver')->name('pedidos.index');
        Route::post('/pedidos', [OrderController::class, 'store'])->middleware('permission:pedidos.crear')->name('pedidos.store');
        Route::get('/pedidos/{order}', [OrderController::class, 'show'])->middleware('permission:pedidos.ver')->name('pedidos.show');
        Route::post('/pedidos/{order}/lineas/{item}/asociar-proveedor', [ProductSupplierController::class, 'storeFromOrder'])->middleware('permission:productos.editar')->name('pedidos.items.suppliers.store');
        Route::patch('/pedidos/{order}/lineas/{item}/revision', [OrderController::class, 'reviewItem'])->name('pedidos.items.review');

        Route::get('/cotizaciones', [QuoteController::class, 'index'])->middleware('permission:cotizaciones.ver')->name('cotizaciones.index');
        Route::post('/cotizaciones', [QuoteController::class, 'store'])->middleware('permission:cotizaciones.crear')->name('cotizaciones.store');
        Route::get('/cotizaciones/{cotizacione}', [QuoteController::class, 'show'])->middleware('permission:cotizaciones.ver')->name('cotizaciones.show');
        Route::get('/cotizaciones/{quote}/imprimir', [QuoteController::class, 'print'])->middleware('permission:cotizaciones.ver')->name('cotizaciones.print');
        Route::get('/cotizaciones/{quote}/cargar', [QuoteController::class, 'load'])->middleware('permission:cotizaciones.crear')->name('cotizaciones.load');
        Route::post('/cotizaciones/{quote}/cancelar', [QuoteController::class, 'cancel'])->middleware('permission:cotizaciones.editar')->name('cotizaciones.cancel');
    });

    Route::resource('facturas', InvoiceController::class);

    Route::middleware('active.branch')->group(function () {
        Route::get('cuentas-por-pagar', [AccountsPayableController::class, 'index'])->middleware('permission:cuentas_pagar.ver')->name('cuentas-por-pagar.index');
        Route::get('cuentas-por-pagar/{accountPayable}', [AccountsPayableController::class, 'show'])->middleware('permission:cuentas_pagar.ver')->name('cuentas-por-pagar.show');
        Route::post('cuentas-por-pagar/{accountPayable}/abonos', [AccountsPayableController::class, 'payment'])->middleware('permission:cuentas_pagar.pagar')->name('cuentas-por-pagar.payments.store');
        Route::put('cuentas-por-pagar-configuracion', [AccountsPayableController::class, 'updateAlertDays'])->middleware('permission:cuentas_pagar.editar')->name('cuentas-por-pagar.alert-days.update');

        Route::get('apartados', [LayawayController::class, 'index'])->middleware('permission:apartados.ver')->name('apartados.index');
        Route::get('apartados/crear', [LayawayController::class, 'create'])->middleware('permission:apartados.crear')->name('apartados.create');
        Route::post('apartados', [LayawayController::class, 'store'])->middleware('permission:apartados.crear')->name('apartados.store');
        Route::get('apartados/{apartado}', [LayawayController::class, 'show'])->middleware('permission:apartados.ver')->name('apartados.show');
        Route::post('apartados/{apartado}/abonos', [LayawayController::class, 'payment'])->middleware('permission:apartados.abonar')->name('apartados.payments.store');
        Route::post('apartados/{apartado}/cancelar', [LayawayController::class, 'cancel'])->middleware('permission:apartados.cancelar')->name('apartados.cancel');
        Route::post('apartados/{apartado}/entregar', [LayawayController::class, 'deliver'])->middleware('permission:apartados.entregar')->name('apartados.deliver');
        Route::put('apartados-configuracion', [LayawayController::class, 'updateSettings'])->middleware('permission:empresa.editar')->name('apartados.settings.update');
    });

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

    Route::middleware(['active.branch', 'permission:cuentas_cobrar.ver'])->group(function () {
        Route::get('cuentas-por-cobrar', [AccountsReceivableController::class, 'index'])->name('cuentas-por-cobrar.index');
        Route::get('cuentas-por-cobrar/{accountReceivable}', [AccountsReceivableController::class, 'show'])->name('cuentas-por-cobrar.show');
        Route::post('cuentas-por-cobrar/{accountReceivable}/abonos', [AccountsReceivableController::class, 'payment'])->middleware('permission:cuentas_cobrar.abonar')->name('cuentas-por-cobrar.payments.store');
        Route::put('cuentas-por-cobrar-configuracion', [AccountsReceivableController::class, 'updateAlertDays'])->middleware('permission:cuentas_cobrar.editar')->name('cuentas-por-cobrar.alert-days.update');
    });

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

    Route::resource('configuracion', SettingController::class)
        ->only(['index'])
        ->middleware('permission:configuracion.ver');

    Route::put('configuracion/whatsapp', [SettingController::class, 'updateWhatsApp'])
        ->middleware('permission:configuracion.editar')
        ->name('configuracion.whatsapp.update');

    Route::put('configuracion/fidelidad/plantillas', [SettingController::class, 'updateLoyaltyTemplates'])
        ->middleware('permission:fidelidad.configuracion')
        ->name('configuracion.loyalty-templates.update');

    Route::resource('configuracion', SettingController::class)
        ->only(['update'])
        ->middleware('permission:configuracion.editar');

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

    Route::prefix('centro-de-datos')->name('data-center.')->group(function () {
        Route::get('/', [DataCenterController::class, 'index'])->name('index');
        Route::get('/importar', [DataCenterController::class, 'imports'])->name('imports');
        Route::get('/exportar', [DataCenterController::class, 'exports'])->name('exports');
        Route::get('/reportes', [DataCenterController::class, 'reports'])->name('reports');
        Route::get('/reportes/{category}', [ReportCenterController::class, 'show'])
            ->middleware('permission:reportes.ver')
            ->name('reports.show');
        Route::get('/exportar/{dataset}/{format}', [DataExportController::class, 'download'])
            ->middleware('permission:reportes.exportar')
            ->whereIn('format', ['xlsx', 'csv'])
            ->name('exports.download');
    });

    Route::redirect('/importar-datos', '/centro-de-datos/importar')
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
        ->middleware('permission:inventario.ajustar')
        ->name('importaciones.inventario.import');
    Route::resource('reportes', ReportController::class);

});
