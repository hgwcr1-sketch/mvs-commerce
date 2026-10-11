<?php

use App\Http\Controllers\AccountsPayableController;
use App\Http\Controllers\AccountsReceivableController;
/*
|--------------------------------------------------------------------------
| Controladores
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\ActiveBranchController;
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BrandController;
// Catálogos
use App\Http\Controllers\CashClosingController;
use App\Http\Controllers\CashDrawerController;
use App\Http\Controllers\CashMovementController;
use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\CashSessionController;
use App\Http\Controllers\CashSessionHistoryController;
use App\Http\Controllers\Cobros\CobrosAlertController;
use App\Http\Controllers\Cobros\CobrosAuditController;
use App\Http\Controllers\Cobros\CobrosDashboardController;
use App\Http\Controllers\Cobros\CollectorPaymentController;
use App\Http\Controllers\Cobros\CollectorCreditController;
use App\Http\Controllers\Cobros\CollectorCreditDeliveryController;
use App\Http\Controllers\Cobros\DailyClosingController;
use App\Http\Controllers\Cobros\CollectorPortfolioController;
use App\Http\Controllers\Cobros\ForceChangePasswordController;
use App\Http\Controllers\Cobros\CreditRequestController;
use App\Http\Controllers\Cobros\InitialPortfolioController;
use App\Http\Controllers\Cobros\OwnerAssignmentController;
// Inventario
use App\Http\Controllers\Cobros\OwnerCollectorController;
use App\Http\Controllers\Cobros\OwnerCustomerController;
use App\Http\Controllers\Cobros\OwnerDashboardController;
use App\Http\Controllers\Cobros\VisitController;
use App\Http\Controllers\CompanyCashSettingController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyLicenseController;
use App\Http\Controllers\CompanySwitchController;
// Compras
use App\Http\Controllers\ControlCenterController;
use App\Http\Controllers\CustomerAddressController;
use App\Http\Controllers\CustomerContactController;
use App\Http\Controllers\CustomerController;
// Ventas
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataCenterController;
use App\Http\Controllers\DataExportController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\InventoryAdjustmentController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryCountController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\KardexController;
use App\Http\Controllers\LabelCenterController;
use App\Http\Controllers\LayawayController;
use App\Http\Controllers\LoyaltyAdjustmentController;
use App\Http\Controllers\LoyaltyCustomerPortalController;
use App\Http\Controllers\LoyaltyDashboardController;
use App\Http\Controllers\LoyaltyMovementController;
use App\Http\Controllers\LoyaltyMultiplierController;
use App\Http\Controllers\LoyaltyOpportunityController;
use App\Http\Controllers\LoyaltyPortalAccessController;
// MVS Print
use App\Http\Controllers\LoyaltyPortalManagementController;
use App\Http\Controllers\LoyaltyPortalPasskeyController;
use App\Http\Controllers\LoyaltyPortalSessionController;
// Finanzas
use App\Http\Controllers\LoyaltyPromotionController;
use App\Http\Controllers\LoyaltyRegistrationIncentiveController;
// Administración
use App\Http\Controllers\LoyaltyRewardController;
use App\Http\Controllers\LoyaltyRewardRedemptionController;
use App\Http\Controllers\LoyaltyRuleCenterController;
use App\Http\Controllers\MvsPrint\MvsPrintConfigController;
use App\Http\Controllers\MvsPrint\MvsPrintDownloadController;
use App\Http\Controllers\MvsPrint\MvsPrintTerminalsController;
use App\Http\Controllers\MvsPrint\MvsPrintTicketController;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PlatformAdminController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductSupplierController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseImportController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseVerificationController;
use App\Http\Controllers\PurchaseXmlImportController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ReportCenterController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RouteosController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleReceiptMailController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\Cobros\Authenticate;
use App\Http\Middleware\Cobros\EnsureActiveUser;
use App\Http\Middleware\Cobros\RedirectIfAuthenticated;
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
        ->middleware('throttle:10,1')
        ->name('login.store');

    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])
        ->name('password.request');

    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.email');

    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('/reset-password', [ResetPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.update');

});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// BLOQUE 16A-2A - landing pública MVS CobrOS (anónima; ?ref= opcional, solo sesión).
Route::get('/bienvenida-cobros', App\Http\Controllers\Cobros\PublicLandingController::class)
    ->name('cobros.landing');

// PRE-19B-5D - páginas públicas DEMO y Videos (anónimas; sin motor de
// simulador aún: muestran «Simulador en preparación» / «Próximamente»).
Route::get('/demo-cobros', [App\Http\Controllers\Cobros\PublicShowcaseController::class, 'demo'])
    ->name('cobros.demo');
Route::get('/videos-cobros', [App\Http\Controllers\Cobros\PublicShowcaseController::class, 'videos'])
    ->name('cobros.videos');

// BLOQUE 16A-2B f1 - registro público CobrOS: wizard en sesión, sin alta,
// licencia ni acceso operativo hasta pago confirmado (fase posterior).
Route::get('/registro-cobros', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@start')
    ->name('cobros.register');
Route::get('/registro-cobros/paso', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@show')
    ->name('cobros.register.step');
Route::post('/registro-cobros/pais-modalidad', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@storeCountryModality')
    ->middleware('throttle:10,1')->name('cobros.register.country');
Route::post('/registro-cobros/datos-personales', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@storePersonalData')
    ->middleware('throttle:10,1')->name('cobros.register.personal');
Route::post('/registro-cobros/rutas/guardar', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@saveRoute')
    ->middleware('throttle:10,1')->name('cobros.register.routes.save');
Route::post('/registro-cobros/rutas/eliminar', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@removeRoute')
    ->middleware('throttle:10,1')->name('cobros.register.routes.remove');
Route::post('/registro-cobros/cartera', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@storePortfolioPlan')
    ->middleware('throttle:10,1')->name('cobros.register.portfolio');
Route::get('/registro-cobros/recuperar', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@showRecover')
    ->name('cobros.register.recover');
Route::post('/registro-cobros/recuperar', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@storeRecover')
    ->middleware('throttle:6,1')->name('cobros.register.recover.store');
Route::get('/registro-cobros/recuperar/{token}', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@consumeRecoveryToken')
    ->name('cobros.register.recover.token');

// BLOQUE 16C-2B f1 - enlace de invitación laboral hacia el registro público.
// Valida el token sin consumirlo, conserva la referencia durante los pasos y
// la reanudación, y mantiene esa referencia separada de la ?ref= comercial.
Route::get('/registro-cobros/invitacion/{token}', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@invitation')
    ->where('token', '[A-Za-z0-9_-]{1,128}')
    ->middleware('throttle:30,1')->name('cobros.register.invitation');

// BLOQUE 16B-2A - machotes VACÍOS de clientes y créditos para uso previo a la
// alta: descargas anónimas que solo generan plantillas (sin datos de ninguna
// empresa, sin catálogos privados y sin exponer endpoints operativos).
Route::get('/registro-cobros/plantilla-clientes', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@customerTemplate')
    ->middleware('throttle:10,1')->name('cobros.register.templates.customers');
Route::get('/registro-cobros/plantilla-creditos', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@creditTemplate')
    ->middleware('throttle:10,1')->name('cobros.register.templates.credits');

// BLOQUE 16B-2B f1 - carga PRIVADA de archivos de cartera (borrador de registro
// + UUID de ruta del borrador). Solo guardar/reemplazar/eliminar; nada se
// importa hasta la activación (procesamiento idempotente posterior).
Route::post('/registro-cobros/archivos/clientes', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@uploadCustomers')
    ->middleware('throttle:10,1')->name('cobros.register.uploads.customers');
Route::post('/registro-cobros/archivos/creditos', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@uploadCredits')
    ->middleware('throttle:10,1')->name('cobros.register.uploads.credits');
Route::post('/registro-cobros/archivos/{kind}/reemplazar', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@replaceUpload')
    ->middleware('throttle:10,1')->name('cobros.register.uploads.replace');
Route::post('/registro-cobros/archivos/{kind}/eliminar', App\Http\Controllers\Cobros\PublicRegistrationController::class.'@deleteUpload')
    ->middleware('throttle:10,1')->name('cobros.register.uploads.delete');

// BLOQUE 17B-2B — Webhook de Stripe para MVS Billing (público; CSRF excluido
// en bootstrap/app.php via validateCsrfTokens; firma verificada en controlador).
Route::post('/billing/webhook/stripe', App\Http\Controllers\Billing\StripeWebhookController::class.'@handle')
    ->middleware('throttle:60,1')
    ->name('billing.webhook.stripe');

// CobrOS authentication uses the existing web guard with product-specific entry/exit.
Route::prefix('cobros')->name('cobros.')->group(function () {
    Route::middleware([RedirectIfAuthenticated::class, 'cobros.entry'])->group(function () {
        Route::get('/login', [App\Http\Controllers\Cobros\LoginController::class, 'create'])->name('login');
        Route::post('/login', [App\Http\Controllers\Cobros\LoginController::class, 'authenticate'])
            ->middleware('throttle:10,1')->name('login.store');
    });
    Route::post('/logout', [App\Http\Controllers\Cobros\LoginController::class, 'destroy'])
        ->middleware(Authenticate::class)->name('logout');

    // Forced password change — only requires auth + active user, not full CobrOS permissions.
    Route::middleware([Authenticate::class, EnsureActiveUser::class])->group(function () {
        Route::get('/cambiar-contrasena', [ForceChangePasswordController::class, 'show'])->name('password.force');
        Route::post('/cambiar-contrasena', [ForceChangePasswordController::class, 'store'])
            ->middleware('throttle:10,1')->name('password.force.store');
    });
});

// BLOQUE 17B-4B — selección de métodos de pago del contrato CobrOS.
// Reutiliza la autenticación/autorización de CobrOS (empresa activa + permiso).
// Sin 'company.licensed' ni 'company.module:cobros' a propósito: esta pantalla
// es la puerta de pago/renovación y exigir licencia operable la volvería
// inalcanzable justo cuando hay que pagar. Sin 'cobros.route': no es operativa.
Route::middleware([Authenticate::class, 'cobros.active.company', EnsureActiveUser::class, 'cobros.entry', 'permission:cobros.acceder'])
    ->prefix('cobros')->name('cobros.')
    ->group(function () {
        Route::get('/contratos/{contract}/metodos-pago', [App\Http\Controllers\Cobros\PaymentMethodSelectionController::class, 'show'])
            ->name('payment.methods');
        // BLOQUE 17B-4C — sesión Stripe Checkout (solo TEST, solo el pagador).
        Route::post('/contratos/{contract}/checkout', [App\Http\Controllers\Cobros\PaymentMethodSelectionController::class, 'checkout'])
            ->middleware('throttle:10,1')->name('payment.checkout');
        // BLOQUE 17B-5A - solicitud SINPE pendiente (el pagador declara la transferencia;
        // nunca confirma ni activa licencias; importe/estado los define el servicio).
        Route::post('/contratos/{contract}/sinpe/comprobante', [App\Http\Controllers\Cobros\PaymentMethodSelectionController::class, 'reportSinpe'])
            ->middleware('throttle:10,1')->name('payment.sinpe.report');
    });

// BLOQUE 13-2B — cambio seguro de empresa/prestamista sin cerrar sesión.
// Sin 'active.company' a propósito: el selector debe poder abrirse cuando la
// empresa de la sesión quedó obsoleta (membresía revocada o empresa inactiva).
Route::middleware([Authenticate::class, EnsureActiveUser::class])->group(function () {
    Route::get('/cambiar-empresa', [CompanySwitchController::class, 'index'])->name('company.switch');
    Route::post('/cambiar-empresa', [CompanySwitchController::class, 'store'])->name('company.switch.store');
});

// Entrada a otro producto: autenticación, licencia global, módulo contratado y permiso propio de CobrOS.
// Sin 'active.branch' a propósito: el portal del dueño opera a nivel de empresa completa.
// Usa 'cobros.active.company' (no redirige a onboarding de Commerce).
Route::middleware([Authenticate::class, 'cobros.active.company', 'company.licensed', 'company.module:cobros', 'permission:cobros.acceder', EnsureActiveUser::class, 'cobros.entry', 'cobros.route', 'cobros.modality'])->prefix('cobros')->name('cobros.')->group(function () {
    // Selector de ruta del cobrador: exige UNA ruta activa/licenciada en sesión.
    Route::middleware('permission:cobros.cobrar')->group(function () {
        Route::get('/rutas', [App\Http\Controllers\Cobros\RouteSelectorController::class, 'index'])->name('routes.select');
        Route::post('/rutas', [App\Http\Controllers\Cobros\RouteSelectorController::class, 'store'])->name('routes.store');
    });
    // Campana CobrOS: click en una notificación → marca SOLO ese recipient del
    // usuario autenticado como leído (read_at Core) y redirige a su destino.
    Route::get('/notifications/{recipient}/open', [CobrosAlertController::class, 'open'])
        ->name('notifications.open');
    Route::get('/supervisor', [CobrosDashboardController::class, 'supervisor'])
        ->middleware('permission:cobros.supervisar')->name('supervisor.index');
    Route::get('/supervisor/audit', [CobrosAuditController::class, 'index'])
        ->middleware('permission:cobros.supervisar')->name('supervisor.audit');
    Route::get('/supervisor/alerts', [CobrosAlertController::class, 'index'])
        ->middleware('permission:cobros.supervisar')->name('supervisor.alerts');
    Route::get('/collector', [CollectorPortfolioController::class, 'index'])
        ->middleware('permission:cobros.cobrar')->name('collector.index');
    Route::get('/collector/portfolio', [CollectorPortfolioController::class, 'index'])
        ->middleware('permission:cobros.cobrar')->name('collector.portfolio');
    Route::get('/collector/portfolio/{customer}', [CollectorPortfolioController::class, 'show'])
        ->middleware('permission:cobros.cobrar')->name('collector.portfolio.show');
    // 2) MIS CLIENTES: con y sin crédito activo (expediente histórico legítimo).
    Route::get('/collector/mis-clientes', [CollectorPortfolioController::class, 'mine'])
        ->middleware('permission:cobros.cobrar')->name('collector.my-customers');
    Route::post('/collector/portfolio/{customer}/location', [CollectorPortfolioController::class, 'storeLocation'])
        ->middleware('permission:cobros.cobrar')->name('collector.portfolio.location');
    // Teléfono (cliente o contacto indicado) desde el recibo: mismo tenant + cartera.
    Route::post('/collector/customers/{customer}/phone', [CollectorPortfolioController::class, 'storePhone'])
        ->middleware('permission:cobros.cobrar')->name('collector.customer.phone');
    // Bloque 6: configuración operativa de día/frecuencia de visita (jamás toca la CxC).
    Route::post('/collector/portfolio/{customer}/visit-schedule', [CollectorPortfolioController::class, 'storeVisitSchedule'])
        ->middleware('permission:cobros.cobrar')->name('collector.customer.visit-schedule');
    Route::get('/collector/payments/create/{account}', [CollectorPaymentController::class, 'create'])
        ->middleware('permission:cobros.cobrar')->name('collector.payments.create');
    Route::post('/collector/payments/{account}', [CollectorPaymentController::class, 'store'])
        ->middleware(['permission:cobros.cobrar', 'throttle:30,1'])->name('collector.payments.store');
    Route::get('/collector/receipts', [CollectorPaymentController::class, 'history'])
        ->middleware('permission:cobros.cobrar')->name('collector.payments.history');
    Route::get('/collector/receipts/{receipt}', [CollectorPaymentController::class, 'receipt'])
        ->middleware('permission:cobros.acceder')->name('collector.payments.receipt');
    // Recibo formal independiente (print-friendly): solo el recibo, sin navbar/menús.
    Route::get('/collector/receipts/{receipt}/print', [CollectorPaymentController::class, 'receiptPrint'])
        ->middleware('permission:cobros.acceder')->name('collector.payments.receipt.print');
    Route::post('/collector/receipts/{receipt}/annul', [CollectorPaymentController::class, 'annul'])
        ->middleware(['permission:cobros.anular', 'throttle:10,1'])->name('collector.payments.annul');
    // Fase 6B — "Nuevo cliente" del cobrador (se asigna automáticamente a su cartera).
    Route::get('/collector/customers/create', [CollectorPortfolioController::class, 'createCustomer'])
        ->middleware('permission:cobros.cobrar')->name('collector.customers.create');
    Route::post('/collector/customers', [CollectorPortfolioController::class, 'storeCustomer'])
        ->middleware(['permission:cobros.cobrar', 'throttle:30,1'])->name('collector.customers.store');
    // BLOQUE 7A - solicitud de credito del cobrador: crear, revisar, enviar y listar.
    // Sin PATCH/PUT: una solicitud enviada NO tiene endpoint de edicion.
    Route::get('/collector/credit-requests', [CreditRequestController::class, 'index'])
        ->middleware('permission:cobros.cobrar')->name('collector.credit-requests.index');
    Route::get('/collector/credit-requests/create', [CreditRequestController::class, 'create'])
        ->middleware('permission:cobros.cobrar')->name('collector.credit-requests.create');
    // 7A typeahead de CLIENTE EXISTENTE: solo clientes de la empresa activa.
    Route::get('/collector/credit-requests/customers', [CreditRequestController::class, 'searchCustomers'])
        ->middleware(['permission:cobros.cobrar', 'throttle:60,1'])->name('collector.credit-requests.customers');
    Route::get('/collector/credit-requests/bureau-check', [CreditRequestController::class, 'bureauCheck'])
        ->middleware(['permission:cobros.cobrar', 'throttle:60,1'])->name('collector.credit-requests.bureau-check');
    // 9C-B collector_authorized: pantalla de evaluación del COBRADOR (datos,
    // fotos privadas, consentimiento + consulta y confirmación manual).
    Route::get('/collector/credit-requests/{solicitud}/evaluacion', [CreditRequestController::class, 'evaluate'])
        ->middleware('permission:cobros.cobrar')->name('collector.credit-requests.evaluate');
    Route::get('/collector/credit-requests/{solicitud}/foto/{lado}', [CreditRequestController::class, 'photo'])
        ->middleware('permission:cobros.cobrar')->name('collector.credit-requests.photo');
    // 10A - expediente PERMANENTE DEL CLIENTE (acceso desde ficha "VER CLIENTE").
    Route::get('/collector/customers/{customer}/expediente', [App\Http\Controllers\Cobros\CreditRequestExpedienteController::class, 'show'])
        ->middleware('permission:cobros.cobrar')->name('collector.customers.expediente');
    Route::post('/collector/customers/{customer}/expediente', [App\Http\Controllers\Cobros\CreditRequestExpedienteController::class, 'save'])
        ->middleware(['permission:cobros.cobrar', 'throttle:30,1'])->name('collector.customers.expediente.save');
    Route::get('/collector/customers/{customer}/expediente/documento/{tipo}', [App\Http\Controllers\Cobros\CreditRequestExpedienteController::class, 'document'])
        ->middleware('permission:cobros.cobrar')->name('collector.customers.expediente.document');
    // 10A - "Mis préstamos" del cobrador (lista de créditos propios desembolsados).
    Route::get('/collector/prestamos', [CollectorCreditController::class, 'index'])
        ->middleware('permission:cobros.cobrar')->name('collector.credits.index');
    Route::post('/collector/credit-requests/review', [CreditRequestController::class, 'review'])
        ->middleware(['permission:cobros.cobrar', 'throttle:30,1'])->name('collector.credit-requests.review');
    Route::post('/collector/credit-requests', [CreditRequestController::class, 'store'])
        ->middleware(['permission:cobros.cobrar', 'throttle:30,1'])->name('collector.credit-requests.store');
    // BLOQUE 9C - el COBRADOR prepara, decide y entrega su propio préstamo:
    // CONTINUAR CON EL PRÉSTAMO o NO REALIZARLO, sin aprobación individual
    // del dueño (su pantalla queda como supervisión informativa).
    Route::get('/collector/credit-requests/{solicitud}/preparar', [CollectorCreditController::class, 'prepare'])
        ->middleware('permission:cobros.cobrar')->name('collector.credit-requests.prepare');
    Route::post('/collector/credit-requests/preview', [CollectorCreditController::class, 'preview'])
        ->middleware(['permission:cobros.cobrar', 'throttle:60,1'])->name('collector.credit-requests.preview');
    Route::post('/collector/credit-requests/{solicitud}/continuar', [CollectorCreditController::class, 'approve'])
        ->middleware(['permission:cobros.cobrar', 'throttle:30,1'])->name('collector.credit-requests.continuar');
    Route::post('/collector/credit-requests/{solicitud}/no-realizar', [CollectorCreditController::class, 'notRealizar'])
    ->middleware(['permission:cobros.cobrar', 'throttle:30,1'])->name('collector.credit-requests.not-realizar');
    // 13) Modalidad collector_authorized: el cobrador revisa sus propios datos.
    Route::post('/collector/credit-requests/{solicitud}/verificar', [CollectorCreditController::class, 'verify'])
    ->middleware(['permission:cobros.cobrar', 'throttle:30,1'])->name('collector.credit-requests.verify');
    Route::post('/collector/creditos/{credit}/entregar', [CollectorCreditController::class, 'disburse'])
        ->middleware(['permission:cobros.cobrar', 'throttle:30,1'])->name('collector.creditos.disburse');
    Route::get('/collector/creditos/{credit}/entrega', [CollectorCreditDeliveryController::class, 'delivery'])
        ->middleware('permission:cobros.cobrar')->name('collector.creditos.entrega');
    Route::get('/collector/creditos/{credit}/comprobante', [CollectorCreditDeliveryController::class, 'receipt'])
        ->middleware('permission:cobros.cobrar')->name('collector.creditos.comprobante');
    Route::get('/collector/creditos/{credit}/comprobante.pdf', [CollectorCreditDeliveryController::class, 'pdf'])
        ->middleware('permission:cobros.cobrar')->name('collector.creditos.comprobante_pdf');
    // 13-1A - importación DIRECTA del cobrador (solo sus rutas; aviso al prestamista).
    Route::get('/collector/customers/importar', [App\Http\Controllers\Cobros\CollectorCustomerImportController::class, 'index'])
        ->middleware('permission:cobros.cobrar')->name('collector.customers.import');
    Route::get('/collector/customers/importar/plantilla', [App\Http\Controllers\Cobros\CollectorCustomerImportController::class, 'template'])
        ->middleware('permission:cobros.cobrar')->name('collector.customers.import.template');
    Route::post('/collector/customers/importar/vista-previa', [App\Http\Controllers\Cobros\CollectorCustomerImportController::class, 'preview'])
        ->middleware(['permission:cobros.cobrar', 'throttle:30,1'])->name('collector.customers.import.preview');
    Route::post('/collector/customers/importar/confirmar', [App\Http\Controllers\Cobros\CollectorCustomerImportController::class, 'confirm'])
        ->middleware(['permission:cobros.cobrar', 'throttle:30,1'])->name('collector.customers.import.confirm');
    Route::get('/collector/customers/importar/ejecuciones/{run}', [App\Http\Controllers\Cobros\CollectorCustomerImportController::class, 'status'])
        ->middleware('permission:cobros.cobrar')->name('collector.customers.import.status');
    Route::get('/collector/credits/importar', [App\Http\Controllers\Cobros\CollectorCreditImportController::class, 'index'])
        ->middleware('permission:cobros.cobrar')->name('collector.credits.import');
    Route::get('/collector/credits/importar/plantilla', [App\Http\Controllers\Cobros\CollectorCreditImportController::class, 'template'])
        ->middleware('permission:cobros.cobrar')->name('collector.credits.import.template');
    Route::post('/collector/credits/importar/vista-previa', [App\Http\Controllers\Cobros\CollectorCreditImportController::class, 'preview'])
        ->middleware(['permission:cobros.cobrar', 'throttle:30,1'])->name('collector.credits.import.preview');
    Route::post('/collector/credits/importar/confirmar', [App\Http\Controllers\Cobros\CollectorCreditImportController::class, 'confirm'])
        ->middleware(['permission:cobros.cobrar', 'throttle:30,1'])->name('collector.credits.import.confirm');
    Route::get('/visits/today', [VisitController::class, 'today'])
        ->middleware('permission:cobros.cobrar')->name('visits.today');
    Route::get('/visits/customer/{customer}', [VisitController::class, 'customerHistory'])
        ->middleware('permission:cobros.cobrar')->name('visits.customer');
    Route::post('/visits/customer/{customer}', [VisitController::class, 'store'])
        ->middleware('permission:cobros.cobrar')->name('visits.store');
    // Gestión rápida desde la ficha: reutiliza 2B (mismos estados y bitácora).
    Route::post('/visits/customer/{customer}/manage', [VisitController::class, 'manage'])
        ->middleware('permission:cobros.cobrar')->name('visits.manage');
    // Cartera inicial POR COBRADOR (5A/5B): el cobrador inicia/carga/finaliza.
    // Consulta común identificación => nombre (solo lectura; sin proveedor => manual).
    Route::get('/clientes/identificacion-nombre', [App\Http\Controllers\Cobros\CustomerIdentificationLookupController::class, '__invoke'])
        ->middleware(['permission:cobros.acceder', 'throttle:30,1'])->name('customers.identification-name');
    Route::get('/mi-cartera-inicial', [InitialPortfolioController::class, 'index'])
        ->middleware('permission:cobros.acceder')->name('initial-portfolio');
    Route::post('/mi-cartera-inicial/iniciar', [InitialPortfolioController::class, 'start'])
        ->middleware('permission:cobros.acceder')->name('initial-portfolio.start');
    Route::post('/mi-cartera-inicial/finalizar', [InitialPortfolioController::class, 'finalize'])
        ->middleware('permission:cobros.acceder')->name('initial-portfolio.finalize');
    Route::get('/mi-cartera-inicial/clientes/nuevo', [InitialPortfolioController::class, 'createClient'])
        ->middleware('permission:cobros.acceder')->name('initial-portfolio.client');
    Route::post('/mi-cartera-inicial/clientes', [InitialPortfolioController::class, 'storeClient'])
        ->middleware('permission:cobros.acceder')->name('initial-portfolio.client.store');
    Route::post('/mi-cartera-inicial/registros/{record}/correccion', [InitialPortfolioController::class, 'requestCorrection'])
        ->middleware('permission:cobros.acceder')->name('initial-portfolio.correction.request');
    // Prestamista: resumen, revisión y resolución de correcciones (no gate).
    Route::get('/mi-cartera-inicial/resumen', [InitialPortfolioController::class, 'summary'])
        ->middleware('permission:cobros.portal_dueno')->name('initial-portfolio.summary');
    Route::post('/mi-cartera-inicial/revisar', [InitialPortfolioController::class, 'review'])
        ->middleware('permission:cobros.portal_dueno')->name('initial-portfolio.review');
    Route::post('/mi-cartera-inicial/correcciones/{correction}/resolver', [InitialPortfolioController::class, 'resolveCorrection'])
        ->middleware('permission:cobros.portal_dueno')->name('initial-portfolio.correction.resolve');
    Route::post('/mi-cartera-inicial/reabrir', [InitialPortfolioController::class, 'reopenForCollector'])
        ->middleware('permission:cobros.portal_dueno')->name('initial-portfolio.reopen.owner');
    Route::patch('/visits/{visit}', [VisitController::class, 'update'])
        ->middleware('permission:cobros.cobrar')->name('visits.update');
    // Bloque 6: reprogramar UNA ocurrencia de la Agenda (frecuencia base intacta).
    Route::post('/visits/{customer}/agenda-reschedule', [VisitController::class, 'agendaReschedule'])
        ->middleware('permission:cobros.cobrar')->name('visits.agenda.reschedule');
    // Cierre del día: declaración por medio de pago + evidencias, enviado al dueño.
    Route::get('/closing', [DailyClosingController::class, 'show'])
        ->middleware('permission:cobros.cobrar')->name('closing.show');
    Route::post('/closing', [DailyClosingController::class, 'store'])
        ->middleware(['permission:cobros.cobrar', 'throttle:30,1'])->name('closing.store');
    Route::get('/closing/{closing}/evidence/{kind}', [DailyClosingController::class, 'evidence'])
        ->middleware('permission:cobros.acceder')->name('closing.evidence');
    Route::get('/accesos-rapidos', [\App\Http\Controllers\Cobros\CobrosQuickNavController::class, 'edit'])
        ->middleware('permission:cobros.cobrar')->name('quick-nav.edit');
    Route::put('/accesos-rapidos', [\App\Http\Controllers\Cobros\CobrosQuickNavController::class, 'update'])
        ->middleware(['permission:cobros.cobrar', 'throttle:30,1'])->name('quick-nav.update');
    Route::middleware('permission:cobros.portal_dueno')->prefix('owner/routes')->name('owner.routes')->group(function () {
        Route::get('/', [App\Http\Controllers\Cobros\OwnerRouteController::class, 'index']);
        Route::get('/create', [App\Http\Controllers\Cobros\OwnerRouteController::class, 'create'])->name('.create');
        Route::post('/', [App\Http\Controllers\Cobros\OwnerRouteController::class, 'store'])->name('.store');
        Route::get('/{route}/edit', [App\Http\Controllers\Cobros\OwnerRouteController::class, 'edit'])->name('.edit');
        Route::put('/{route}', [App\Http\Controllers\Cobros\OwnerRouteController::class, 'update'])->name('.update');
    });
    Route::get('/owner', [OwnerDashboardController::class, 'index'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.dashboard');
    Route::get('/owner/audit', [CobrosAuditController::class, 'index'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.audit');
    Route::get('/owner/reports', [OwnerDashboardController::class, 'reports'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.reports');
    Route::get('/owner/alerts', [CobrosAlertController::class, 'index'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.alerts');
    // 13-1A - aviso «Migración pendiente de revisar» (importaciones directas de cobradores).
    Route::get('/owner/migraciones', [App\Http\Controllers\Cobros\MigrationNoticeController::class, 'index'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.migrations.notices');
    Route::post('/owner/migraciones/{notice}/revisada', [App\Http\Controllers\Cobros\MigrationNoticeController::class, 'review'])
        ->middleware(['permission:cobros.portal_dueno', 'throttle:30,1'])->name('owner.migrations.notices.review');
    // BLOQUE 8 - verificacion de solicitudes de credito del dueno/prestamista.
    Route::middleware('permission:cobros.portal_dueno')->prefix('owner/solicitudes')->name('owner.solicitudes')->group(function () {
        Route::get('/', [App\Http\Controllers\Cobros\OwnerCreditRequestController::class, 'index'])->name('.index');
        // 9C.3 - oficina ejecuta la consulta de evaluación (policy-aware, server-side).
        Route::get('/consulta', [App\Http\Controllers\Cobros\OwnerCreditRequestController::class, 'bureauCheck'])
            ->middleware('throttle:60,1')->name('.bureau-check');
        Route::get('/{id}', [App\Http\Controllers\Cobros\OwnerCreditRequestController::class, 'show'])->name('.show');
        Route::get('/{id}/foto/{lado}', [App\Http\Controllers\Cobros\OwnerCreditRequestController::class, 'photo'])->name('.photo');
        Route::post('/{id}/verificar', [App\Http\Controllers\Cobros\OwnerCreditRequestController::class, 'verify'])->middleware('throttle:30,1')->name('.verify');
        Route::post('/{id}/corregir', [App\Http\Controllers\Cobros\OwnerCreditRequestController::class, 'correct'])->middleware('throttle:30,1')->name('.correct');
        Route::post('/{id}/rechazar', [App\Http\Controllers\Cobros\OwnerCreditRequestController::class, 'reject'])->middleware('throttle:30,1')->name('.reject');
    });
    Route::get('/owner/collectors', [OwnerCollectorController::class, 'index'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.collectors');
    // Fase 6B — drill-down del dueño por cobrador (cartera y métricas del mes).
    Route::get('/owner/collectors/{user}', [OwnerCollectorController::class, 'show'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.collectors.show');
    Route::post('/owner/collectors', [OwnerCollectorController::class, 'store'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.collectors.store');
    Route::patch('/owner/collectors/{user}/toggle', [OwnerCollectorController::class, 'toggle'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.collectors.toggle');
    Route::put('/owner/collectors/{user}/branches', [OwnerCollectorController::class, 'updateBranches'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.collectors.branches');
    // Política de AUTORIDAD de préstamos por cobrador (solo el dueño la cambia).
    Route::patch('/owner/collectors/{user}/autoridad', [OwnerCollectorController::class, 'updateAuthority'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.collectors.authority');
    // 9B.3 — reparto interno de la mora: general (empresa) y override por cobrador.
    Route::get('/owner/configuracion/mora', [App\Http\Controllers\Cobros\OwnerLateFeeSettingsController::class, 'edit'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.latefee');
    Route::put('/owner/configuracion/mora', [App\Http\Controllers\Cobros\OwnerLateFeeSettingsController::class, 'update'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.latefee.update');
    Route::patch('/owner/collectors/{user}/mora', [OwnerCollectorController::class, 'updateLateFee'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.collectors.latefee');
    Route::post('/owner/collectors/{user}/regenerate-access', [OwnerCollectorController::class, 'regenerateAccess'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.collectors.regenerate');
    // Revisión del dueño sobre un cierre diario recibido (trazabilidad).
    Route::post('/owner/closings/{closing}/review', [OwnerCollectorController::class, 'reviewClosing'])
        ->middleware(['permission:cobros.portal_dueno', 'throttle:30,1'])->name('owner.closings.review');
    Route::get('/owner/assignments', [OwnerAssignmentController::class, 'index'])
        ->middleware('permission:cobros.asignar')->name('owner.assignments');
    Route::post('/owner/assignments', [OwnerAssignmentController::class, 'store'])
        ->middleware('permission:cobros.asignar')->name('owner.assignments.store');
    Route::delete('/owner/assignments/{customer}', [OwnerAssignmentController::class, 'destroy'])
        ->middleware('permission:cobros.asignar')->name('owner.assignments.destroy');
    // Fase 6B — módulo Clientes del dueño/administrador en /cobros/*.
    Route::get('/owner/customers', [OwnerCustomerController::class, 'index'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers');
    Route::get('/owner/customers/create', [OwnerCustomerController::class, 'create'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers.create');
    Route::post('/owner/customers', [OwnerCustomerController::class, 'store'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers.store');
    Route::patch('/owner/customers/collector-credit-setting', [OwnerCustomerController::class, 'updateCollectorCreditSetting'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers.credit-setting');
    // 12B - importación de clientes dentro del shell CobrOS (reutiliza el motor de importaciones).
    Route::get('/owner/customers/importar', [App\Http\Controllers\Cobros\CustomerImportController::class, 'index'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers.import');
    Route::get('/owner/customers/importar/plantilla', [App\Http\Controllers\Cobros\CustomerImportController::class, 'template'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers.import.template');
    Route::post('/owner/customers/importar/revisar', [App\Http\Controllers\Cobros\CustomerImportController::class, 'preview'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers.import.preview');
    Route::post('/owner/customers/importar/confirmar', [App\Http\Controllers\Cobros\CustomerImportController::class, 'confirm'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers.import.confirm');
    Route::get('/owner/customers/importar/ejecuciones/{run}', [App\Http\Controllers\Cobros\CustomerImportController::class, 'status'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers.import.status');
    Route::post('/owner/customers/importar/ejecuciones/{run}/reanudar', [App\Http\Controllers\Cobros\CustomerImportController::class, 'retry'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers.import.retry');
    Route::get('/owner/customers/importar/ejecuciones/{run}/reporte', [App\Http\Controllers\Cobros\CustomerImportController::class, 'report'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers.import.report');
    // 12B-2 - importación de CRÉDITOS históricos (prestamista, sin migración 5A/5B).
    Route::get('/owner/credits/importar', [App\Http\Controllers\Cobros\CreditImportController::class, 'index'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.credits.import');
    Route::get('/owner/credits/importar/plantilla', [App\Http\Controllers\Cobros\CreditImportController::class, 'template'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.credits.import.template');
    Route::post('/owner/credits/importar/vista-previa', [App\Http\Controllers\Cobros\CreditImportController::class, 'preview'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.credits.import.preview');
    Route::post('/owner/credits/importar/confirmar', [App\Http\Controllers\Cobros\CreditImportController::class, 'confirm'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.credits.import.confirm');
    Route::get('/owner/customers/{customer}', [OwnerCustomerController::class, 'show'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers.show');
    // 10A - expediente PERMANENTE del cliente (oficina).
    Route::get('/owner/customers/{customer}/expediente', [App\Http\Controllers\Cobros\CreditRequestExpedienteController::class, 'show'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers.expediente');
    Route::post('/owner/customers/{customer}/expediente', [App\Http\Controllers\Cobros\CreditRequestExpedienteController::class, 'save'])
        ->middleware(['permission:cobros.portal_dueno', 'throttle:30,1'])->name('owner.customers.expediente.save');
    Route::get('/owner/customers/{customer}/expediente/documento/{tipo}', [App\Http\Controllers\Cobros\CreditRequestExpedienteController::class, 'document'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers.expediente.document');
    Route::get('/owner/customers/{customer}/edit', [OwnerCustomerController::class, 'edit'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers.edit');
    // El parámetro se llama {cliente}: UpdateCobrosCustomerRequest lo usa para
    // el 404 cross-company y para ignorar la unicidad del propio cliente.
    Route::put('/owner/customers/{cliente}', [OwnerCustomerController::class, 'update'])
        ->middleware('permission:cobros.portal_dueno')->name('owner.customers.update');
    // BLOQUE 9 — crédito: definir condiciones → aprobar → desembolsar.
    // Solo prestamista (portal_dueno); el cobrador jamás aprueba ni desembolsa.
    Route::middleware('permission:cobros.portal_dueno')->prefix('owner/creditos')->name('owner.creditos')->group(function () {
        Route::get('/desde/{solicitud}', [App\Http\Controllers\Cobros\OwnerCreditController::class, 'define'])->name('.define');
        Route::post('/preview', [App\Http\Controllers\Cobros\OwnerCreditController::class, 'preview'])->middleware('throttle:60,1')->name('.preview');
        Route::post('/{solicitud}/aprobar', [App\Http\Controllers\Cobros\OwnerCreditController::class, 'approve'])->middleware('throttle:30,1')->name('.approve');
        Route::get('/{credit}', [App\Http\Controllers\Cobros\OwnerCreditController::class, 'show'])->name('.show');
        Route::post('/{credit}/desembolsar', [App\Http\Controllers\Cobros\OwnerCreditController::class, 'disburse'])->middleware('throttle:30,1')->name('.disburse');
        // BLOQUE 9A.2 - entrega con firma, comprobante PDF privado y WhatsApp.
        Route::get('/{credit}/entrega', [App\Http\Controllers\Cobros\OwnerCreditDeliveryController::class, 'delivery'])->name('.entrega');
        Route::get('/{credit}/comprobante', [App\Http\Controllers\Cobros\OwnerCreditDeliveryController::class, 'receipt'])->name('.comprobante');
        Route::get('/{credit}/comprobante.pdf', [App\Http\Controllers\Cobros\OwnerCreditDeliveryController::class, 'pdf'])->name('.comprobante_pdf');
    });
    Route::get('/', [CobrosDashboardController::class, 'index'])
        ->name('index');
});

Route::middleware('auth')->group(function () {
    Route::get('/empresa/create', [CompanyController::class, 'create'])->name('empresa.create');
    Route::post('/empresa', [CompanyController::class, 'store'])->name('empresa.store');
    Route::get('/ubicaciones/provincias/{country}', [CustomerController::class, 'provinces'])->name('ubicaciones.provincias');
    Route::get('/ubicaciones/cantones/{province}', [CustomerController::class, 'cantons'])->name('ubicaciones.cantones');
    Route::get('/ubicaciones/distritos/{canton}', [CustomerController::class, 'districts'])->name('ubicaciones.distritos');
    Route::get('/geo/divisiones', [App\Http\Controllers\GeoDivisionController::class, 'index'])->name('geo.divisiones');
});

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
    Route::get('/registro', [LoyaltyPortalSessionController::class, 'registerForm'])->name('register');
    Route::post('/registro', [LoyaltyPortalSessionController::class, 'register'])->middleware('throttle:10,1')->name('register.store');
    Route::get('/', [LoyaltyPortalSessionController::class, 'home'])->name('home');
    Route::post('/salir', [LoyaltyPortalSessionController::class, 'logout'])->name('logout');
    Route::patch('/perfil', [LoyaltyPortalSessionController::class, 'profile'])->name('profile');
    Route::get('/compras/{sale}/comprobante.pdf', [LoyaltyPortalSessionController::class, 'receiptPdf'])->name('receipt.pdf');
    Route::post('/compras/{sale}/comprobante/correo', [LoyaltyPortalSessionController::class, 'sendReceipt'])->name('receipt.mail');
    Route::get('/recuperar', [LoyaltyPortalSessionController::class, 'forgotForm'])->name('password.request');
    Route::post('/recuperar', [LoyaltyPortalSessionController::class, 'forgot'])->name('password.email');
    Route::get('/restablecer/{token}', [LoyaltyPortalSessionController::class, 'resetForm'])->name('password.reset');
    Route::post('/restablecer/{token}', [LoyaltyPortalSessionController::class, 'reset'])->name('password.update');
    Route::get('/cambiar-clave', [LoyaltyPortalSessionController::class, 'forceChangeForm'])->name('password.force');
    Route::post('/cambiar-clave', [LoyaltyPortalSessionController::class, 'forceChange'])->name('password.force.store');
    Route::get('/passkeys', [LoyaltyPortalPasskeyController::class, 'manage'])->name('passkeys.manage');
    Route::delete('/passkeys/{passkey}', [LoyaltyPortalPasskeyController::class, 'revoke'])->whereNumber('passkey')->name('passkeys.revoke');
    Route::patch('/passkeys/{passkey}', [LoyaltyPortalPasskeyController::class, 'rename'])->whereNumber('passkey')->name('passkeys.rename');
});
Route::middleware('throttle:20,1')->group(function () {
    Route::post('/portal-clientes/{company}/passkeys/iniciar-registro', [LoyaltyPortalPasskeyController::class, 'startRegistration'])
        ->name('loyalty.customer.passkeys.start');
    Route::post('/portal-clientes/{company}/passkeys/finalizar-registro', [LoyaltyPortalPasskeyController::class, 'finishRegistration'])
        ->name('loyalty.customer.passkeys.finish');
    Route::post('/portal-clientes/{company}/passkeys/iniciar-autenticacion', [LoyaltyPortalPasskeyController::class, 'startAuthentication'])
        ->name('loyalty.customer.passkeys.auth.start');
    Route::post('/portal-clientes/{company}/passkeys/finalizar-autenticacion', [LoyaltyPortalPasskeyController::class, 'finishAuthentication'])
        ->name('loyalty.customer.passkeys.auth.finish');
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
    Route::post('/planes', [PlatformAdminController::class, 'storePlan'])->name('plans.store');
    // 18E-1B: elección explícita de proveedor de pago por producto+país
    // (solo Platform Admin; upsert; nunca recibe ni muestra credenciales).
    Route::patch('/billing-providers', [App\Http\Controllers\PlatformBillingProviderController::class, 'update'])
        ->name('billing-providers.update');
    Route::patch('/empresas/{company}/modulos', [PlatformAdminController::class, 'updateModules'])->name('modules.update');
    Route::patch('/empresas/{company}/cobros-contrato', [PlatformAdminController::class, 'updateCobrosContract'])->name('cobros-contract.update');
    // 18B-2: aprobación/rechazo manual SINPE (solo pendientes de ESTA empresa;
    // exige verificación externa del operador; nunca automático ni por WhatsApp).
    Route::post('/empresas/{company}/pagos/sinpe/{confirmation}/aprobar', [PlatformAdminController::class, 'approveSinpePayment'])
        ->name('sinpe.approve');
    Route::post('/empresas/{company}/pagos/sinpe/{confirmation}/rechazar', [PlatformAdminController::class, 'rejectSinpePayment'])
        ->name('sinpe.reject');
    // Reapertura excepcional POR COBRADOR (auditable) de la cartera inicial.
    Route::post('/empresas/{company}/cobradores/{user}/cartera-inicial/reabrir', [App\Http\Controllers\Cobros\InitialPortfolioController::class, 'reopen'])
        ->name('initial-portfolio.reopen');
    Route::post('/empresas/{company}/cobradores', [PlatformAdminController::class, 'storeCollector'])->name('collectors.store');
    Route::patch('/empresas/{company}/cobradores/{user}/toggle', [PlatformAdminController::class, 'toggleCollector'])->name('collectors.toggle');
    Route::put('/empresas/{company}/cobradores/{user}/branches', [PlatformAdminController::class, 'updateCollectorBranches'])->name('collectors.branches');
    Route::post('/empresas/{company}/cobradores/{user}/regenerate-access', [PlatformAdminController::class, 'regenerateCollectorAccess'])->name('collectors.regenerate');
    // Licencias individuales: renovar, suspender y reactivar por cobrador.
    Route::post('/empresas/{company}/cobradores/{user}/licencia/renovar', [PlatformAdminController::class, 'renewCollectorLicense'])->name('collectors.license.renew');
    Route::patch('/empresas/{company}/cobradores/{user}/licencia/suspender', [PlatformAdminController::class, 'suspendCollectorLicense'])->name('collectors.license.suspend');
    Route::patch('/empresas/{company}/cobradores/{user}/licencia/reactivar', [PlatformAdminController::class, 'resumeCollectorLicense'])->name('collectors.license.resume');
    Route::patch('/empresas/{company}/licencia', [PlatformAdminController::class, 'updateLicense'])->name('licenses.update');
});

Route::middleware(['auth', 'active.company'])->group(function () {
    Route::get('/licencia', [CompanyLicenseController::class, 'show'])->name('license.status');
});

Route::middleware(['auth', 'active.company', 'company.licensed'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    Route::redirect('/', '/dashboard');

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    Route::get('/centro-de-control', [ControlCenterController::class, 'index'])
        ->middleware(['active.branch', 'permission:dashboard.admin'])
        ->name('control-center.index');

    /*
    |--------------------------------------------------------------------------
    | Centro de Notificaciones y Alertas MVS
    |--------------------------------------------------------------------------
    */
    Route::prefix('notificaciones')->name('notifications.')->middleware(['active.branch'])->group(function () {
        Route::get('/', [NotificationCenterController::class, 'index'])
            ->middleware('permission:notificaciones.ver')
            ->name('index');
        Route::get('/recientes', [NotificationCenterController::class, 'recent'])
            ->middleware('permission:notificaciones.ver')
            ->name('recent');
        Route::get('/no-leidas', [NotificationCenterController::class, 'unreadCount'])
            ->middleware('permission:notificaciones.ver')
            ->name('unread-count');
        Route::post('/marcar-leidas', [NotificationCenterController::class, 'markAsRead'])
            ->middleware('permission:notificaciones.ver')
            ->name('mark-read');
        Route::post('/{alert}/descartar', [NotificationCenterController::class, 'dismiss'])
            ->middleware('permission:notificaciones.ver')
            ->name('dismiss');
        Route::get('/preferencias', [NotificationCenterController::class, 'preferences'])
            ->middleware('permission:notificaciones.configurar')
            ->name('preferences');
        Route::put('/preferencias', [NotificationCenterController::class, 'updatePreferences'])
            ->middleware('permission:notificaciones.configurar')
            ->name('preferences.update');
    });

    /*
    |--------------------------------------------------------------------------
    | MVS RouteOS — Ventas, Rutas y Cobros
    |--------------------------------------------------------------------------
    */
    Route::middleware(['active.branch', 'permission:routeos.acceder'])->prefix('routeos')->name('routeos.')->group(function () {
        Route::get('/', [RouteosController::class, 'index'])
            ->name('index');
    });

    Route::middleware(['active.branch', 'permission:pos.acceder'])->group(function () {
        Route::get('/pos', [PosController::class, 'index'])->middleware('pos.cash-session')->name('pos.index');
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
            ->middleware(['permission:ventas.crear', 'pos.cash-session'])
            ->name('pos.checkout');
        Route::post('/pos/apartado', [PosController::class, 'storeLayaway'])
            ->middleware('permission:apartados.crear')
            ->name('pos.apartados.store');
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
        Route::get('/caja/requerida', [CashSessionController::class, 'required'])->name('cash.required');
        Route::get('/caja', [CashSessionController::class, 'index'])
            ->name('cash.index');
        Route::get('/caja/abrir', [CashSessionController::class, 'create'])
            ->middleware('permission:caja.abrir')
            ->name('cash.open.create');
        Route::post('/caja/abrir', [CashSessionController::class, 'store'])
            ->middleware('permission:caja.abrir')
            ->name('cash.open.store');
        Route::get('/caja/receipt/{type}', [CashDrawerController::class, 'receipt'])
            ->name('cash.drawer-receipt');
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

    Route::post('/clientes/{cliente}/pin', [CustomerController::class, 'generateOneTimeToken'])->name('clientes.pin.generate');
    Route::post('/clientes/{cliente}/pin/verificar', [CustomerController::class, 'verifyOneTimeToken'])->name('clientes.pin.verify');
    Route::resource('clientes', CustomerController::class);
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

    Route::middleware(['active.branch'])->prefix('tomas-inventario')->name('inventory-counts.')->group(function () {
        Route::get('/', [InventoryCountController::class, 'index'])->middleware('permission:inventario.conteo.ver')->name('index');
        Route::get('/crear', [InventoryCountController::class, 'create'])->middleware('permission:inventario.conteo.iniciar')->name('create');
        Route::post('/', [InventoryCountController::class, 'store'])->middleware('permission:inventario.conteo.iniciar')->name('store');
        Route::get('/{inventoryCount}', [InventoryCountController::class, 'show'])->middleware('permission:inventario.conteo.ver')->name('show');
        Route::get('/{inventoryCount}/editar', [InventoryCountController::class, 'edit'])->middleware('permission:inventario.conteo.contar')->name('edit');
        Route::put('/{inventoryCount}', [InventoryCountController::class, 'update'])->middleware('permission:inventario.conteo.contar')->name('update');
        Route::delete('/{inventoryCount}', [InventoryCountController::class, 'destroy'])->middleware('permission:inventario.conteo.cancelar')->name('destroy');

        Route::post('/{inventoryCount}/productos', [InventoryCountController::class, 'addItem'])->middleware('permission:inventario.conteo.contar')->name('add-item');
        Route::put('/{inventoryCount}/items/{item}/cantidad', [InventoryCountController::class, 'updateCountedQuantity'])->middleware('permission:inventario.conteo.contar')->name('update-quantity');
        Route::put('/{inventoryCount}/items/{item}/reconteo', [InventoryCountController::class, 'recount'])->middleware('permission:inventario.conteo.contar')->name('recount');
        Route::put('/{inventoryCount}/items/{item}/notas', [InventoryCountController::class, 'updateNotes'])->middleware('permission:inventario.conteo.contar')->name('update-notes');
        Route::delete('/{inventoryCount}/items/{item}', [InventoryCountController::class, 'removeItem'])->middleware('permission:inventario.conteo.contar')->name('remove-item');

        Route::post('/{inventoryCount}/revisar', [InventoryCountController::class, 'startReview'])->middleware('permission:inventario.conteo.revisar')->name('review');
        Route::post('/{inventoryCount}/recontar', [InventoryCountController::class, 'backToCounting'])->middleware('permission:inventario.conteo.revisar')->name('back-to-counting');
        Route::post('/{inventoryCount}/confirmar', [InventoryCountController::class, 'confirm'])->middleware('permission:inventario.conteo.confirmar')->name('confirm');
        Route::post('/{inventoryCount}/cancelar', [InventoryCountController::class, 'cancel'])->middleware('permission:inventario.conteo.cancelar')->name('cancel');
    });

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
            Route::post('/publicaciones/{post}/imagenes', [LoyaltyPortalManagementController::class, 'addPostImage'])->middleware('permission:fidelidad.portal.contenido')->name('posts.images.add');
            Route::delete('/publicaciones/{post}/imagenes/{image}', [LoyaltyPortalManagementController::class, 'deletePostImage'])->middleware('permission:fidelidad.portal.contenido')->name('posts.images.delete');
            Route::put('/publicaciones/{post}/imagenes/orden', [LoyaltyPortalManagementController::class, 'reorderPostImages'])->middleware('permission:fidelidad.portal.contenido')->name('posts.images.reorder');
            Route::post('/enlaces', [LoyaltyPortalManagementController::class, 'storeLink'])->middleware('permission:fidelidad.portal.enlaces')->name('links.store');
            Route::put('/enlaces/{link}', [LoyaltyPortalManagementController::class, 'updateLink'])->middleware('permission:fidelidad.portal.enlaces')->name('links.update');
            Route::delete('/enlaces/{link}', [LoyaltyPortalManagementController::class, 'destroyLink'])->middleware('permission:fidelidad.portal.enlaces')->name('links.destroy');
        });
        Route::get('/oportunidades', [LoyaltyOpportunityController::class, 'index'])->middleware('permission:fidelidad.oportunidades')->name('opportunities.index');
        Route::post('/oportunidades/{customer}/contactar', [LoyaltyOpportunityController::class, 'contact'])->middleware('permission:fidelidad.contactar')->name('opportunities.contact');
        Route::get('/configuracion', [SettingController::class, 'loyaltySettings'])->middleware('permission:fidelidad.configuracion')->name('settings');
        Route::get('/reglas', [LoyaltyRuleCenterController::class, 'index'])->middleware('permission:fidelidad.configuracion')->name('rules.index');
        Route::put('/reglas', [LoyaltyRuleCenterController::class, 'update'])->middleware('permission:fidelidad.configuracion')->name('rules.update');
        Route::put('/incentivo-registro', [LoyaltyRegistrationIncentiveController::class, 'update'])->middleware('permission:fidelidad.configuracion')->name('registration-incentive.update');
        Route::middleware('permission:fidelidad.ajustes')->prefix('ajustes')->name('adjustments.')->group(function () {
            Route::get('/', [LoyaltyAdjustmentController::class, 'index'])->name('index');
            Route::post('/', [LoyaltyAdjustmentController::class, 'store'])->name('store');
        });
        Route::middleware('permission:fidelidad.portal')->prefix('accesos')->name('accesses.')->group(function () {
            Route::get('/', [LoyaltyPortalAccessController::class, 'index'])->name('index');
            Route::get('/clientes/buscar', [LoyaltyPortalAccessController::class, 'searchCustomers'])->name('customers.search');
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

    Route::get('/transferencias/productos/buscar', [TransferController::class, 'searchProducts'])
        ->middleware(['active.branch', 'permission:inventario.transferir'])
        ->name('transferencias.products.search');

    Route::resource('transferencias', TransferController::class)
        ->only(['index', 'create', 'store'])
        ->middleware(['active.branch', 'permission:inventario.transferir']);

    Route::post('/transferencias/{transfer}/prepare', [TransferController::class, 'prepare'])
        ->middleware(['active.branch', 'permission:inventario.transferir'])
        ->name('transferencias.prepare');
    Route::post('/transferencias/{transfer}/dispatch', [TransferController::class, 'dispatch'])
        ->middleware(['active.branch', 'permission:inventario.transferir'])
        ->name('transferencias.dispatch');
    Route::post('/transferencias/{transfer}/review', [TransferController::class, 'review'])
        ->middleware(['active.branch', 'permission:inventario.transferir'])
        ->name('transferencias.review');
    Route::post('/transferencias/{transfer}/receive', [TransferController::class, 'receive'])
        ->middleware(['active.branch', 'permission:inventario.transferir'])
        ->name('transferencias.receive');
    Route::post('/transferencias/{transfer}/cancel', [TransferController::class, 'cancel'])
        ->middleware(['active.branch', 'permission:inventario.transferir'])
        ->name('transferencias.cancel');
    Route::get('/transferencias/{transfer}/show', [TransferController::class, 'show'])
        ->middleware(['active.branch', 'permission:inventario.transferir'])
        ->name('transferencias.show');

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

    Route::middleware('active.branch')->prefix('compras/verificaciones')->name('purchase-verifications.')->group(function () {
        Route::get('/', [PurchaseVerificationController::class, 'index'])->name('index');
        Route::get('/{purchaseVerification}', [PurchaseVerificationController::class, 'show'])->name('show');
        Route::post('/{purchaseVerification}/iniciar', [PurchaseVerificationController::class, 'start'])->middleware('permission:compras.recepcion.verificar')->name('start');
        Route::put('/{purchaseVerification}/verificar', [PurchaseVerificationController::class, 'verify'])->middleware('permission:compras.recepcion.verificar')->name('verify');
        Route::post('/{purchaseVerification}/cerrar', [PurchaseVerificationController::class, 'close'])->middleware('permission:compras.recepcion.resolver')->name('close');
        Route::post('/{purchaseVerification}/etiquetas', [LabelCenterController::class, 'fromVerification'])->middleware('permission:productos.etiquetas.imprimir')->name('labels');
    });

    Route::get('/compras/{purchase}/verificacion/responsables', [PurchaseVerificationController::class, 'assignable'])
        ->middleware(['active.branch', 'permission:compras.recepcion.asignar'])->name('purchase-verifications.assignable');
    Route::post('/compras/{purchase}/verificacion', [PurchaseVerificationController::class, 'store'])
        ->middleware(['active.branch', 'permission:compras.recepcion.asignar'])->name('purchase-verifications.store');

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
        Route::put('/cotizaciones/{cotizacione}', [QuoteController::class, 'update'])->middleware('active.branch')->name('cotizaciones.actualizar');
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
        ->names('branches')
        ->middlewareFor(['index', 'show'], 'permission:configuracion.ver')
        ->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'permission:configuracion.editar');

    Route::resource('empresa', CompanyController::class)
        ->except(['create', 'store']);

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
        Route::get('/paquete-migracion', [DataExportController::class, 'migrationPackage'])
            ->middleware('permission:reportes.exportar')
            ->name('exports.migration-package');
    });

    Route::redirect('/importar-datos', '/centro-de-datos/importar')
        ->name('importaciones.index');

    Route::get('/importar-datos/clientes', [DataImportController::class, 'customers'])
        ->middleware('permission:clientes.crear')
        ->name('importaciones.clientes');
    Route::get('/importar-datos/clientes/plantilla', [DataImportController::class, 'customerTemplate'])
        ->middleware('permission:clientes.crear')
        ->name('importaciones.clientes.template');
    Route::post('/importar-datos/clientes/revisar', [DataImportController::class, 'customerPreview'])
        ->middleware('permission:clientes.crear')
        ->name('importaciones.clientes.preview');
    Route::post('/importar-datos/clientes/confirmar', [DataImportController::class, 'customerImport'])
        ->middleware('permission:clientes.crear')
        ->name('importaciones.clientes.import');
    Route::get('/importar-datos/clientes/ejecuciones/{run}', [DataImportController::class, 'customerStatus'])
        ->middleware('permission:clientes.crear')->name('importaciones.clientes.status');
    Route::post('/importar-datos/clientes/ejecuciones/{run}/reanudar', [DataImportController::class, 'customerRetry'])
        ->middleware('permission:clientes.crear')->name('importaciones.clientes.retry');
    Route::get('/importar-datos/clientes/ejecuciones/{run}/reporte', [DataImportController::class, 'customerReport'])
        ->middleware('permission:clientes.crear')->name('importaciones.clientes.report');

    Route::get('/importar-datos/productos', [DataImportController::class, 'products'])
        ->middleware('permission:productos.crear')
        ->name('importaciones.productos');
    Route::get('/importar-datos/productos/plantilla', [DataImportController::class, 'productTemplate'])
        ->middleware('permission:productos.crear')
        ->name('importaciones.productos.template');
    Route::post('/importar-datos/productos/revisar', [DataImportController::class, 'productPreview'])
        ->middleware('permission:productos.crear')
        ->name('importaciones.productos.preview');
    Route::post('/importar-datos/productos/confirmar', [DataImportController::class, 'productImport'])
        ->middleware('permission:productos.crear')
        ->name('importaciones.productos.import');

    Route::get('/importar-datos/ventas-historicas', [DataImportController::class, 'historicalSales'])
        ->middleware('permission:ventas.crear')->name('importaciones.ventas-historicas');
    Route::get('/importar-datos/ventas-historicas/plantilla', [DataImportController::class, 'historicalSaleTemplate'])
        ->middleware('permission:ventas.crear')->name('importaciones.ventas-historicas.template');
    Route::post('/importar-datos/ventas-historicas/revisar', [DataImportController::class, 'historicalSalePreview'])
        ->middleware('permission:ventas.crear')->name('importaciones.ventas-historicas.preview');
    Route::post('/importar-datos/ventas-historicas/confirmar', [DataImportController::class, 'historicalSaleImport'])
        ->middleware('permission:ventas.crear')->name('importaciones.ventas-historicas.import');

    Route::get('/importar-datos/inventario', [DataImportController::class, 'inventory'])
        ->middleware('permission:inventario.ver')
        ->name('importaciones.inventario');

    Route::get('/importar-datos/inventario-migracion', [DataImportController::class, 'inventoryMigration'])
        ->middleware('permission:inventario.ajustar')->name('importaciones.inventario-migracion');
    Route::get('/importar-datos/inventario-migracion/plantilla', [DataImportController::class, 'inventoryMigrationTemplate'])
        ->middleware('permission:inventario.ajustar')->name('importaciones.inventario-migracion.template');
    Route::post('/importar-datos/inventario-migracion/revisar', [DataImportController::class, 'inventoryMigrationPreview'])
        ->middleware('permission:inventario.ajustar')->name('importaciones.inventario-migracion.preview');
    Route::post('/importar-datos/inventario-migracion/confirmar', [DataImportController::class, 'inventoryMigrationImport'])
        ->middleware('permission:inventario.ajustar')->name('importaciones.inventario-migracion.import');

    Route::get('/importar-datos/fidelidad-migracion', [DataImportController::class, 'loyaltyMigration'])
        ->middleware('permission:fidelidad.configuracion')->name('importaciones.fidelidad-migracion');
    Route::get('/importar-datos/fidelidad-migracion/plantilla', [DataImportController::class, 'loyaltyMigrationTemplate'])
        ->middleware('permission:fidelidad.configuracion')->name('importaciones.fidelidad-migracion.template');
    Route::post('/importar-datos/fidelidad-migracion/revisar', [DataImportController::class, 'loyaltyMigrationPreview'])
        ->middleware('permission:fidelidad.configuracion')->name('importaciones.fidelidad-migracion.preview');
    Route::post('/importar-datos/fidelidad-migracion/resolver-clientes', [DataImportController::class, 'loyaltyMigrationResolve'])
        ->middleware('permission:fidelidad.configuracion')->name('importaciones.fidelidad-migracion.resolve');
    Route::post('/importar-datos/fidelidad-migracion/confirmar', [DataImportController::class, 'loyaltyMigrationImport'])
        ->middleware('permission:fidelidad.configuracion')->name('importaciones.fidelidad-migracion.import');
    Route::get('/importar-datos/fidelidad-migracion/estado/{run}', [DataImportController::class, 'loyaltyMigrationStatus'])
        ->middleware('permission:fidelidad.configuracion')->name('importaciones.fidelidad-migracion.status');
    Route::post('/importar-datos/fidelidad-migracion/estado/{run}/reintentar', [DataImportController::class, 'loyaltyMigrationRetry'])
        ->middleware('permission:fidelidad.configuracion')->name('importaciones.fidelidad-migracion.retry');
    Route::get('/importar-datos/fidelidad-migracion/errores', [DataImportController::class, 'loyaltyMigrationErrors'])
        ->middleware('permission:fidelidad.configuracion')->name('importaciones.fidelidad-migracion.errors');

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

    /*
    |--------------------------------------------------------------------------
    | MVS Print — Impresión local por terminal (QZ Tray)
    |--------------------------------------------------------------------------
    |
    | Infraestructura aislada de configuración de terminales de impresión.
    | El backend solo valida, guarda configuración y firma peticiones QZ;
    | la impresión física la ejecuta el navegador mediante QZ Tray local.
    | Aún no conectada al checkout del POS (fase posterior).
    */
    Route::middleware(['active.branch', 'permission:mvs.print.configurar'])
        ->prefix('mvs/print')
        ->name('mvs.print.')
        ->group(function () {
            Route::get('/', [MvsPrintTerminalsController::class, 'index'])->name('index');
            Route::post('/terminals', [MvsPrintTerminalsController::class, 'store'])->name('terminals.store');
            Route::patch('/terminals/{terminal}', [MvsPrintTerminalsController::class, 'update'])->name('terminals.update');
            Route::patch('/terminals/{terminal}/toggle', [MvsPrintTerminalsController::class, 'toggleStatus'])->name('terminals.toggle');
            Route::delete('/terminals/{terminal}', [MvsPrintTerminalsController::class, 'destroy'])->name('terminals.destroy');
            Route::get('/terminals/{terminal}/test-print', [MvsPrintTerminalsController::class, 'testPrintPayload'])->name('terminals.test-print');
            Route::get('/terminals/{terminal}/open-drawer', [MvsPrintTerminalsController::class, 'openDrawerPayload'])->name('terminals.open-drawer');
            Route::post('/terminals/{terminal}/heartbeat', [MvsPrintTerminalsController::class, 'heartbeat'])->name('terminals.heartbeat');
            Route::get('/descargar', MvsPrintDownloadController::class)->name('download');
        });

    /*
    |--------------------------------------------------------------------------
    | MVS Print — Ticket + Config para auto_print desde el POS
    |--------------------------------------------------------------------------
    |
    | Endpoints read-only accesibles desde el POS (solo active.branch).
    | El endpoint de ticket retorna payload ESC/POS de una venta existente.
    | El endpoint de config retorna la configuración de la terminal para
    | que el frontend resuelva auto_print, auto_cut, open_drawer y paper_width.
    */
    Route::middleware(['active.branch'])
        ->prefix('mvs/print')
        ->name('mvs.print.')
        ->group(function () {
            Route::get('/ticket/{sale}', MvsPrintTicketController::class)
                ->name('ticket');
            Route::get('/ticket/layaway/{layaway}', [MvsPrintTicketController::class, 'layaway'])
                ->name('ticket.layaway');
            Route::get('/ticket/layaway/{layaway}/payment/{payment}', [MvsPrintTicketController::class, 'layawayPayment'])
                ->name('ticket.layaway.payment');
            Route::get('/config', [MvsPrintConfigController::class, 'show'])
                ->name('config');
            // QZ security - accesible desde POS (pos.acceder) sin requerir mvs.print.configurar
            Route::post('/signature', [MvsPrintTerminalsController::class, 'signature'])->name('signature');
            Route::get('/certificate', [MvsPrintTerminalsController::class, 'certificate'])->name('certificate');
        });

});
