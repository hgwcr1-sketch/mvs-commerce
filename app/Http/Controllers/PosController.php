<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuickStoreCustomerRequest;
use App\Http\Requests\ResuspendSaleRequest;
use App\Http\Requests\StorePosSaleRequest;
use App\Http\Requests\StoreSuspendedSaleRequest;
use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\LoyaltyPortalCredential;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SuspendedSale;
use App\Services\Cash\CashSessionResolver;
use App\Services\CompanyCashSettingsProvisioner;
use App\Services\Loyalty\LoyaltyPortalDeliveryService;
use App\Services\Loyalty\LoyaltyPosSummaryService;
use App\Services\PaymentMethodProvisioner;
use App\Services\PhoneNumberService;
use App\Services\Sales\CreditNoteService;
use App\Services\Sales\LayawayService;
use App\Services\Sales\PosSaleProcessor;
use App\Services\Sales\SaleReceiptService;
use App\Services\Sales\SuspendedSaleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PosController extends Controller
{
    public function index(Request $request, CashSessionResolver $cashSessionResolver): View
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        $company = Company::query()->findOrFail($companyId);

        /**
         * Si el admin global (platform admin o con permiso dashboard.admin)
         * entra al POS sin haber seleccionado una sucursal concreta,
         * exigir selección explícita en lugar de asignar la primera por defecto.
         */
        $isGlobalAdmin = $request->user()->isPlatformAdmin()
            || $request->user()->hasPermission('dashboard.admin', $company);

        if (! $branchId && $isGlobalAdmin) {
            return redirect()->route('dashboard')
                ->with('warning', 'Debe seleccionar una sucursal concreta para operar el POS.');
        }

        app(PaymentMethodProvisioner::class)->provision($company);
        $branch = Branch::query()
            ->where('company_id', $companyId)
            ->findOrFail($branchId);
        $paymentMethods = PaymentMethod::forCompany($companyId)
            ->active()
            ->ordered()
            ->get(['id', 'code', 'name', 'type', 'allows_change', 'requires_reference']);
        $cashSettings = app(CompanyCashSettingsProvisioner::class)->provision($company);
        $cashSessions = $cashSessionResolver->applicable($request->user(), $companyId, $branchId);
        $cashSession = $cashSessions->count() === 1 ? $cashSessions->first() : null;

        return view('pos.index', [
            'company' => $company,
            'branch' => $branch,
            'cashier' => $request->user(),
            'paymentMethods' => $paymentMethods,
            'canCancelSuspended' => $request->user()->hasPermission('ventas.anular', $company),
            'cashSession' => $cashSession,
            'cashSessions' => $cashSessions,
            'cashSettings' => $cashSettings,
            'canOpenCash' => $request->user()->hasPermission('caja.abrir', $company),
            'canDiscount' => $request->user()->hasPermission('pos.aplicar_descuento', $company),
            'canOverridePrice' => $request->user()->hasPermission('pos.cambiar_precio', $company),
            'canCreateLayaway' => $request->user()->hasPermission('apartados.crear', $company),
            'layawayValidityDays' => (int) ($company->layaway_validity_days ?? 30),
        ]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        if ($search === '') {
            return response()->json([]);
        }

        $search = mb_substr($search, 0, 100);
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');
        $quoteMode = $request->boolean('quote_mode') && $request->user()->hasPermission(
            'cotizaciones.crear',
            Company::query()->findOrFail($companyId),
        );
        $like = '%'.$search.'%';
        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $canViewOtherBranches = $request->user()->hasPermission(
            'inventario.ver_otras_sucursales',
            Company::query()->findOrFail($companyId),
        );

        $products = Product::query()
            ->where('products.company_id', $companyId)
            ->where('products.is_active', true)
            ->where(function ($query) use ($like, $likeOperator) {
                $query->where('products.name', $likeOperator, $like)
                    ->orWhere('products.internal_code', $likeOperator, $like)
                    ->orWhere('products.barcode', $likeOperator, $like)
                    ->orWhereHas('barcodes', function ($barcodeQuery) use ($like, $likeOperator) {
                        $barcodeQuery
                            ->where('is_active', true)
                            ->where('barcode', $likeOperator, $like);
                    });
            })
            ->with([
                'unit:id,abbreviation,allows_decimals',
                'style:id,name',
                'size:id,name',
                'color:id,name',
                'barcodes' => function ($query) use ($like, $likeOperator) {
                    $query
                        ->where('is_active', true)
                        ->where('barcode', $likeOperator, $like)
                        ->select(['id', 'product_id', 'barcode']);
                },
            ])
            ->select([
                'products.id',
                'products.unit_id',
                'products.style_id',
                'products.size_id',
                'products.color_id',
                'products.name',
                'products.internal_code',
                'products.barcode',
                'products.image',
                'products.sale_price',
                'products.special_price',
                'products.wholesale_price',
                'products.price_a',
                'products.price_b',
                'products.price_c',
                'products.tax_rate',
                'products.track_inventory',
            ])
            ->addSelect([
                'available_stock' => DB::table('branch_product')
                    ->select('stock')
                    ->whereColumn('branch_product.product_id', 'products.id')
                    ->where('branch_product.branch_id', $branchId)
                    ->limit(1),
            ])
            ->when(! $quoteMode, fn ($query) => $query->orderByRaw(
                'CASE WHEN products.track_inventory = ? OR COALESCE((SELECT branch_product.stock FROM branch_product WHERE branch_product.product_id = products.id AND branch_product.branch_id = ? LIMIT 1), 0) > 0 THEN 0 ELSE 1 END',
                [false, $branchId],
            ))
            ->orderByRaw(
                'CASE WHEN products.barcode = ? OR EXISTS (SELECT 1 FROM product_barcodes WHERE product_barcodes.product_id = products.id AND product_barcodes.is_active = ? AND product_barcodes.barcode = ?) THEN 0 ELSE 1 END',
                [$search, true, $search],
            )
            ->orderBy('products.name')
            ->limit(10)
            ->get();

        $otherBranchStock = collect();

        if ($canViewOtherBranches && $products->isNotEmpty()) {
            $otherBranchStock = DB::table('branch_product')
                ->join('branches', 'branches.id', '=', 'branch_product.branch_id')
                ->whereIn('branch_product.product_id', $products->pluck('id'))
                ->where('branches.company_id', $companyId)
                ->where('branches.is_active', true)
                ->where('branches.id', '!=', $branchId)
                ->where('branch_product.stock', '>', 0)
                ->orderBy('branches.name')
                ->get([
                    'branch_product.product_id',
                    'branches.id as branch_id',
                    'branches.name as branch_name',
                    'branch_product.stock as available_stock',
                ])
                ->groupBy('product_id');
        }

        return response()->json($products->map(function (Product $product) use (
            $search,
            $canViewOtherBranches,
            $otherBranchStock,
        ) {
            $matchedBarcode = null;

            if ($product->barcode !== null && str_contains(mb_strtolower($product->barcode), mb_strtolower($search))) {
                $matchedBarcode = $product->barcode;
            } elseif ($product->barcodes->isNotEmpty()) {
                $matchedBarcode = $product->barcodes->first()->barcode;
            }

            $availableStock = (float) ($product->available_stock ?? 0);
            $imagePath = $this->safeProductImagePath($product->image);
            $hasImage = $imagePath !== null && Storage::disk('public')->exists($imagePath);
            $result = [
                'id' => $product->id,
                'name' => $product->name,
                'internal_code' => $product->internal_code,
                'matched_barcode' => $matchedBarcode,
                'sale_price' => (float) ($product->special_price ?? $product->sale_price),
                'is_offer' => $product->special_price !== null,
                'wholesale_price' => $product->wholesale_price !== null
                    ? (float) $product->wholesale_price
                    : null,
                'price_a' => $product->price_a !== null
                    ? (float) $product->price_a
                    : null,
                'price_b' => $product->price_b !== null
                    ? (float) $product->price_b
                    : null,
                'price_c' => $product->price_c !== null
                    ? (float) $product->price_c
                    : null,
                'tax_rate' => (float) ($product->tax_rate ?? 0), 'controls_inventory' => (bool) $product->track_inventory,
                'available_stock' => $availableStock,
                'unit' => $product->unit?->abbreviation,
                'allows_decimals' => (bool) $product->unit?->allows_decimals,
                'style_name' => $product->style?->name,
                'size_name' => $product->size?->name,
                'color_name' => $product->color?->name,
                'can_add_to_cart' => ! $product->track_inventory || $availableStock > 0,
                'has_image' => $hasImage,
                'image_url' => $hasImage ? asset('storage/'.$imagePath) : null,
            ];

            if ($canViewOtherBranches) {
                $result['other_branch_stock'] = collect($otherBranchStock->get($product->id, []))
                    ->map(fn ($stock) => [
                        'branch_id' => (int) $stock->branch_id,
                        'branch_name' => $stock->branch_name,
                        'available_stock' => (float) $stock->available_stock,
                    ])
                    ->values()
                    ->all();
            }

            return $result;
        })->values());
    }

    public function searchCustomers(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        if ($search === '') {
            return response()->json([]);
        }

        $search = mb_substr($search, 0, 100);
        $like = '%'.$search.'%';

        $customers = Customer::forCompany((int) session('active_company_id'))
            ->where('is_active', true)
            ->where(function ($query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhere('identification', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('mobile', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('public_code', 'like', $like);
            })
            ->orderByRaw('CASE WHEN public_code = ? THEN 0 WHEN identification = ? THEN 1 ELSE 2 END', [$search, $search])
            ->orderBy('name')
            ->limit(10)
            ->get([
                'id',
                'name',
                'identification',
                'phone',
                'mobile',
                'email',
                'customer_type',
                'credit_limit',
                'credit_days',
                'price_level',
                'public_code',
            ]);

        return response()->json($customers->map(fn (Customer $customer) => [
            'id' => $customer->id,
            'name' => $customer->name,
            'identification' => $customer->identification,
            'phone' => $customer->phone,
            'mobile' => $customer->mobile,
            'email' => $customer->email,
            'public_code' => $customer->public_code,
            'customer_type' => $customer->customer_type,
            'credit_limit' => (float) $customer->credit_limit,
            'credit_days' => (int) ($customer->credit_days ?? 0),
            'credit_used' => (float) AccountReceivable::query()->forCompany((int) session('active_company_id'))->where('customer_id', $customer->id)->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED])->sum('balance_due'),
            'credit_due_date' => (int) ($customer->credit_days ?? 0) > 0 ? today()->addDays((int) $customer->credit_days)->toDateString() : null,
            'price_level' => $customer->price_level ?? 'normal',
        ])->values());
    }

    public function storeQuickCustomer(QuickStoreCustomerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $createPortalAccess = $request->boolean('create_portal_access');
        unset($data['create_portal_access']);

        $customer = Customer::create([
            'company_id' => (int) session('active_company_id'),
            'name' => $data['name'],
            'customer_type' => $data['customer_type'],
            'identification_type' => $data['identification_type'] ?? null,
            'identification' => $data['identification'] ?? null,
            'phone' => $data['phone'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'email' => $data['email'] ?? null,
            'accepts_email_invoice' => false,
            'credit_limit' => 0,
            'credit_days' => 0,
            'price_level' => 'normal',
            'points' => 0,
            'is_active' => true,
        ]);

        $portal = null;
        if ($createPortalAccess) {
            $portal = $this->createPortalAccessForQuickCustomer($customer);
            if ($portal && ($portal['created'] ?? false)) {
                $company = Company::query()->findOrFail($customer->company_id);
                $delivery = app(LoyaltyPortalDeliveryService::class)->build($company, $customer, $portal['username'], $portal['password']);
                $portal = array_merge($portal, $delivery);
            }
        }

        return response()->json([
            'success' => true,
            'message' => $portal && isset($portal['error']) ? 'Cliente creado correctamente. '.$portal['error'] : 'Cliente creado correctamente.',
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'identification' => $customer->identification,
                'phone' => $customer->phone,
                'mobile' => $customer->mobile,
                'email' => $customer->email,
                'customer_type' => $customer->customer_type,
                'price_level' => $customer->price_level,
            ],
            'portal_access' => $portal,
        ], 201);
    }

    private function createPortalAccessForQuickCustomer(Customer $customer): ?array
    {
        $companyId = (int) $customer->company_id;
        if (LoyaltyPortalCredential::query()->where('customer_id', $customer->id)->exists()) {
            return ['created' => false, 'error' => 'Este cliente ya tiene acceso al Portal.'];
        }
        $phones = app(PhoneNumberService::class);
        $phoneNormalized = $phones->normalizePhone($customer->phone ?? $customer->mobile);
        $emailNormalized = $customer->email ? mb_strtolower(trim($customer->email)) : null;
        $username = $phoneNormalized ?: ($emailNormalized && filter_var($emailNormalized, FILTER_VALIDATE_EMAIL) ? $emailNormalized : null);
        if (! $username) {
            return ['created' => false, 'error' => 'No se pudo crear acceso al Portal: el cliente no tiene teléfono ni correo válido.'];
        }
        $exists = LoyaltyPortalCredential::query()
            ->where('company_id', $companyId)
            ->where(function ($q) use ($username, $emailNormalized) {
                $q->where('username', $username);
                if ($emailNormalized) {
                    $q->orWhere('email', $emailNormalized);
                }
            })->exists();
        if ($exists) {
            return ['created' => false, 'error' => 'El usuario o correo ya está registrado en esta empresa.'];
        }
        $plainPassword = chr(random_int(97, 122)).chr(random_int(65, 90)).str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $credential = LoyaltyPortalCredential::create([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'username' => $username,
            'email' => $emailNormalized ?? $username.'@portal.local',
            'password' => $plainPassword,
            'is_active' => true,
            'must_change_password' => true,
        ]);

        return ['created' => true, 'username' => $username, 'password' => $plainPassword, 'email' => $credential->email];
    }

    public function loyaltySummary(Request $request, LoyaltyPosSummaryService $service): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer'],
            'total' => ['required', 'numeric', 'min:0'],
            'has_offers' => ['nullable', 'boolean'],
        ]);

        $companyId = (int) session('active_company_id');

        $customer = Customer::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->find($validated['customer_id']);

        if ($customer === null) {
            return response()->json(['available' => false, 'reason' => 'customer'], 404);
        }

        $company = Company::query()->findOrFail($companyId);

        return response()->json($service->summary(
            $customer,
            $company,
            number_format((float) $validated['total'], 4, '.', ''),
            (bool) ($validated['has_offers'] ?? false),
        ));
    }

    public function searchCreditNotes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer'],
        ]);

        $companyId = (int) session('active_company_id');
        $company = Company::query()->findOrFail($companyId);

        if (! $request->user()->hasPermission('notas_credito.aplicar', $company)) {
            abort(403);
        }

        $customer = Customer::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->find($validated['customer_id']);

        if ($customer === null) {
            return response()->json(['message' => 'Cliente no encontrado.'], 404);
        }

        $notes = CreditNote::query()
            ->forCompany($companyId)
            ->forCustomer($customer->id)
            ->available()
            ->with('saleReturn:id,sale_id,reason')
            ->with('branch:id,name')
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();

        return response()->json($notes->map(fn (CreditNote $note) => [
            'id' => $note->id,
            'number' => $note->credit_note_number,
            'balance' => $note->balance,
            'issued_at' => $note->issued_at?->toIso8601String(),
            'branch_name' => $note->branch?->name,
            'sale_return_reason' => $note->saleReturn?->reason,
        ])->values());
    }

    /**
     * Prevalidación de NC Consumer Final por número + código (4B-3).
     *
     * Es una validación SIN locks y SIN writes: reutiliza la misma autorización
     * lock-free del backend certificado 4B-2 y comparte su rate limit. La
     * validación definitiva (saldo, estado, toggle, vencimiento, empresa)
     * ocurre SIEMPRE dentro del checkout bajo lock (applyBatchToSale).
     *
     * El monto es OPCIONAL: la resolución de credenciales (número + código) es
     * independiente del "Monto a aplicar". Sin monto, el endpoint devuelve el
     * saldo disponible para que la UI proponga MIN(saldo NC, pendiente venta).
     * Si se envía monto, valida también 0 < monto <= saldo.
     *
     * Devuelve datos NO secretos (id, número, saldo) para que el cajero pueda
     * preparar la línea y sugerir el monto; jamás el código ni el hash.
     */
    public function validateBearerCreditNote(Request $request, PosSaleProcessor $processor, CreditNoteService $creditNotes): JsonResponse
    {
        $validated = $request->validate([
            'credit_note_number' => ['required', 'string', 'max:60'],
            'application_code' => ['required', 'string', 'max:40'],
            'amount' => ['nullable', 'numeric', 'regex:/^\d+(?:\.\d{1,4})?$/', 'gt:0'],
        ]);

        $companyId = (int) session('active_company_id');

        if (! $request->user()->hasPermission('notas_credito.aplicar', Company::query()->findOrFail($companyId))) {
            abort(403);
        }

        $processor->guardBearerAttemptRateLimit($companyId, $request->user()->id);

        $amount = array_key_exists('amount', $validated) && $validated['amount'] !== null && $validated['amount'] !== ''
            ? (string) $validated['amount']
            : null;

        try {
            $note = $creditNotes->authorizeBearerApplication(
                $companyId,
                $validated['credit_note_number'],
                $validated['application_code'],
                $amount,
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?? 'No se pudo validar la nota de crédito.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'credit_note_id' => $note->id,
            'credit_note_number' => $note->credit_note_number,
            'balance' => $note->balance,
            'issued_at' => $note->issued_at?->toIso8601String(),
            'expires_at' => $note->expires_at?->toDateString(),
        ]);
    }

    public function checkout(StorePosSaleRequest $request, PosSaleProcessor $processor): JsonResponse
    {
        try {
            $result = $processor->process(
                $request->validated(),
                $request->user(),
                (int) session('active_company_id'),
                (int) session('active_branch_id'),
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?? 'El cobro contiene datos inválidos.',
                'errors' => $exception->errors(),
            ], 422);
        }
        $sale = $result['sale']->load('payments.paymentMethod');
        $payments = $sale->payments;
        $firstPayment = $payments->first();

        return response()->json([
            'success' => true,
            'duplicate' => $result['duplicate'],
            'message' => $result['duplicate'] ? 'Esta venta ya había sido procesada.' : 'Venta cobrada correctamente.',
            'sale_id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'subtotal' => $sale->subtotal,
            'tax_total' => $sale->tax_total,
            'rounding_total' => $sale->rounding_total,
            'total' => $sale->total,
            'paid_total' => $sale->paid_total,
            'total_change' => number_format($payments->sum(fn ($payment) => (float) $payment->change_amount), 4, '.', ''),
            'payments' => $payments->map(fn ($payment) => [
                'method_name' => $payment->paymentMethod->name,
                'amount' => $payment->amount,
                'received_amount' => $payment->received_amount,
                'change_amount' => $payment->change_amount,
                'reference' => $payment->reference,
            ])->values(),
            'received_amount' => $firstPayment?->received_amount,
            'change_amount' => $firstPayment?->change_amount,
            'receipt_url' => route('pos.receipt', $sale),
        ]);
    }

    public function storeLayaway(Request $request, LayawayService $service): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'gt:0'],
            'items.*.discount' => ['prohibited'],
            'items.*.discount_type' => ['prohibited'],
            'discount_total' => ['prohibited'],
            'discount_total_type' => ['prohibited'],
            'initial_amount' => ['required', 'numeric', 'gt:0'],
            'payment_method_id' => ['required', 'integer'],
            'received_amount' => ['nullable', 'numeric', 'gte:0'],
            'cash_session_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:150'],
            'client_token' => ['nullable', 'uuid'],
        ]);

        try {
            $layaway = $service->create(
                $data,
                $request->user(),
                (int) session('active_company_id'),
                (int) session('active_branch_id'),
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?? 'El apartado contiene datos inválidos.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'El producto o cliente seleccionado ya no está disponible.',
            ], 422);
        } catch (ConflictHttpException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        $company = Company::query()->findOrFail((int) session('active_company_id'));
        $firstPayment = $layaway->payments()->latest('id')->first();

        return response()->json([
            'success' => true,
            'message' => "Apartado {$layaway->number} creado correctamente.",
            'layaway_id' => $layaway->id,
            'layaway_number' => $layaway->number,
            'total' => $layaway->total,
            'paid_total' => $layaway->paid_total,
            'balance_due' => $layaway->balance_due,
            'received_amount' => $firstPayment?->received_amount,
            'change_amount' => $firstPayment?->change_amount,
            'show_url' => $request->user()->hasPermission('apartados.ver', $company)
                ? route('apartados.show', $layaway)
                : null,
        ], 201);
    }

    public function storeSuspended(StoreSuspendedSaleRequest $request, SuspendedSaleService $service): JsonResponse
    {
        $sale = $service->suspend($request->validated(), $request->user(), (int) session('active_company_id'), (int) session('active_branch_id'));

        return response()->json(['success' => true, 'message' => "Venta {$sale->suspension_number} suspendida correctamente.", 'suspended_sale' => $sale], 201);
    }

    public function suspendedIndex(Request $request, SuspendedSaleService $service): JsonResponse
    {
        $sales = $service->list($request->user(), (int) session('active_company_id'), (int) session('active_branch_id'));

        return response()->json($sales->map(fn (SuspendedSale $sale) => [
            'id' => $sale->id, 'suspension_number' => $sale->suspension_number,
            'suspended_at' => $sale->suspended_at?->toIso8601String(), 'cashier' => $sale->user->name,
            'customer' => $sale->customer?->name ?? 'Consumidor Final', 'items_count' => $sale->items_count,
            'estimated_total' => $sale->estimated_total, 'status' => $sale->status,
        ])->values());
    }

    public function recoverSuspended(Request $request, SuspendedSale $suspendedSale, SuspendedSaleService $service): JsonResponse
    {
        $data = $request->validate(['recovery_token' => ['nullable', 'uuid']]);

        return response()->json($service->claimForRecovery($suspendedSale, $request->user(), (int) session('active_company_id'), (int) session('active_branch_id'), $data['recovery_token'] ?? null));
    }

    public function cancelSuspended(Request $request, SuspendedSale $suspendedSale, SuspendedSaleService $service): JsonResponse
    {
        $validator = validator($request->all(), ['reason' => ['required', 'string', 'max:255']]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        $service->cancel($suspendedSale, $request->user(), (int) session('active_company_id'), (int) session('active_branch_id'), $data['reason']);

        return response()->json(['success' => true, 'message' => 'Venta suspendida cancelada correctamente.']);
    }

    public function releaseSuspended(Request $request, SuspendedSale $suspendedSale, SuspendedSaleService $service): JsonResponse
    {
        $data = $request->validate(['recovery_token' => ['required', 'uuid']]);
        $service->release($suspendedSale, $request->user(), (int) session('active_company_id'), (int) session('active_branch_id'), $data['recovery_token']);

        return response()->json(['success' => true, 'message' => 'La venta suspendida volvió a quedar disponible.']);
    }

    public function resuspendSale(ResuspendSaleRequest $request, SuspendedSale $suspendedSale, SuspendedSaleService $service): JsonResponse
    {
        $sale = $service->resuspend($suspendedSale, $request->validated(), $request->user(), (int) session('active_company_id'), (int) session('active_branch_id'));

        return response()->json([
            'success' => true,
            'message' => "Venta {$sale->suspension_number} actualizada y suspendida nuevamente.",
            'suspended_sale' => $sale,
        ]);
    }

    public function receipt(Request $request, Sale $sale, SaleReceiptService $receipts): View
    {
        $companyId = (int) session('active_company_id');
        $company = Company::query()->findOrFail($companyId);
        $sale = $receipts->authorizedSale($sale, $request->user(), $companyId, (int) session('active_branch_id'));
        $format = $receipts->format($sale, $request->query('format'));
        $autoPrint = $request->boolean('print') || $sale->branch->receipt_auto_print;
        $loyalty = $receipts->loyaltySummary($sale);
        $receiptData = $receipts->buildReceiptData($sale);

        return view('pos.receipt', compact('sale', 'company', 'format', 'autoPrint', 'loyalty', 'receiptData'));
    }

    public function receiptPdf(Request $request, Sale $sale, SaleReceiptService $receipts)
    {
        $companyId = (int) session('active_company_id');
        $company = Company::query()->findOrFail($companyId);
        $sale = $receipts->authorizedSale($sale, $request->user(), $companyId, (int) session('active_branch_id'));
        $format = $receipts->format($sale, $request->query('format', 'letter'));
        $pdf = $receipts->pdf($sale, $company, $format);

        return $pdf->download("comprobante-{$sale->sale_number}.pdf");
    }

    private function safeProductImagePath(?string $image): ?string
    {
        if ($image === null) {
            return null;
        }

        $path = str_replace('\\', '/', trim($image));

        if ($path === '' || ! str_starts_with($path, 'products/') || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }
}
