<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\Customer;
use App\Models\LoyaltySetting;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SuspendedSale;
use App\Models\User;
use App\Services\Cash\CashSessionResolver;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Loyalty\LoyaltyBirthdayService;
use App\Services\Loyalty\LoyaltyEarningService;
use App\Services\Loyalty\LoyaltyOfferEligibilityService;
use App\Services\Loyalty\LoyaltyRedemptionService;
use App\Services\Loyalty\LoyaltyRegistrationIncentiveService;
use App\Services\Loyalty\LoyaltyReturningCustomerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PosSaleProcessor
{
    public function __construct(
        private readonly InventoryPostingService $inventoryPostingService,
        private readonly CashSessionResolver $cashSessionResolver,
        private readonly AccountsReceivableService $accountsReceivableService,
        private readonly LoyaltyEarningService $loyaltyEarningService,
        private readonly LoyaltyOfferEligibilityService $loyaltyOfferEligibilityService,
        private readonly LoyaltyBirthdayService $loyaltyBirthdayService,
        private readonly LoyaltyReturningCustomerService $loyaltyReturningCustomerService,
        private readonly LoyaltyRedemptionService $loyaltyRedemptionService,
        private readonly LoyaltyRegistrationIncentiveService $loyaltyRegistrationIncentiveService,
    ) {}

    /** @return array{sale: Sale, duplicate: bool} */
    public function process(array $data, User $user, int $companyId, int $branchId): array
    {
        $items = $this->consolidateItems($data['items']);
        $payments = $this->canonicalPayments($data['payments']);
        $requestedPoints = $this->canonicalRequestedPoints($data);

        $fingerprint = $this->fingerprint(
            $data,
            $items,
            $payments,
            $requestedPoints,
            $user->id,
            $companyId,
            $branchId,
        );

        $existing = $this->existingSale(
            $companyId,
            $data['checkout_token'],
            $fingerprint,
        );

        if ($existing !== null) {
            $this->verifyRecoveredSuspension(
                $data,
                $existing,
                $user,
                $companyId,
                $branchId,
            );

            return [
                'sale' => $existing,
                'duplicate' => true,
            ];
        }

        try {
            $sale = DB::transaction(function () use (
                $data,
                $items,
                $payments,
                $fingerprint,
                $requestedPoints,
                $user,
                $companyId,
                $branchId
            ) {
                $company = Company::query()
                    ->where('is_active', true)
                    ->find($companyId);

                if (
                    $company === null
                    || ! $user->companies()->whereKey($companyId)->exists()
                ) {
                    throw ValidationException::withMessages([
                        'company' => 'La empresa activa ya no está autorizada.',
                    ]);
                }

                if ($company->currency !== 'CRC') {
                    throw ValidationException::withMessages([
                        'currency' => 'Este cobro solo admite empresas con moneda CRC.',
                    ]);
                }

                $branch = Branch::query()
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->find($branchId);

                if (
                    $branch === null
                    || ! $user->branches()->whereKey($branchId)->exists()
                ) {
                    throw ValidationException::withMessages([
                        'branch' => 'La sucursal activa ya no está autorizada.',
                    ]);
                }

                $quote = $this->lockQuoteForCheckout($data, $companyId, $branchId);

                $cashSession = $this->cashSessionResolver->resolve(
                    $user,
                    $companyId,
                    $branchId,
                    isset($data['cash_session_id'])
                        ? (int) $data['cash_session_id']
                        : null,
                    true,
                );

                $suspendedSale = $this->lockSuspensionForCheckout(
                    $data,
                    $user,
                    $companyId,
                    $branchId,
                );

                $customerId = $data['customer_id'] ?? null;
                $customer = null;

                if ($customerId !== null) {
                    $customer = Customer::query()
                        ->where('company_id', $companyId)
                        ->where('is_active', true)
                        ->whereKey($customerId)
                        ->first();

                    if ($customer === null) {
                        throw ValidationException::withMessages([
                            'customer_id' => 'El cliente no está disponible para esta empresa.',
                        ]);
                    }
                }

                $paymentMethods = PaymentMethod::query()
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereIn(
                        'id',
                        array_column($payments, 'payment_method_id'),
                    )
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($paymentMethods->count() !== count($payments)) {
                    throw ValidationException::withMessages([
                        'payments' => 'Uno o más métodos de pago no están disponibles.',
                    ]);
                }

                $products = Product::query()
                    ->with('unit:id,abbreviation,allows_decimals')
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereIn('id', array_keys($items))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($products->count() !== count($items)) {
                    throw ValidationException::withMessages([
                        'items' => 'Uno o más productos no están disponibles.',
                    ]);
                }

                $canDiscount = $user->hasPermission(
                    'pos.aplicar_descuento',
                    $company,
                );

                $canOverridePrice = $user->hasPermission(
                    'pos.cambiar_precio',
                    $company,
                );

                if ($quote === null) {
                    $this->authorizeRequestedAdjustments($items, $data, $canDiscount, $canOverridePrice);
                }

                $resolvedLines = [];
                $baseAfterLineDiscounts = 0.0;
                $lineDiscountTotal = 0.0;

                foreach ($items as $productId => $lineData) {
                    $product = $products->get($productId);
                    $quantity = (float) $lineData['quantity'];

                    $this->validateQuantity(
                        $product,
                        $quantity,
                    );

                    $isOffer = $product->special_price !== null && $lineData['unit_price'] === null;
                    $basePrice = $isOffer ? (float) $product->special_price : match ($customer?->price_level ?? 'normal') {
                        'wholesale' => $product->wholesale_price !== null
                            ? (float) $product->wholesale_price
                            : (float) $product->sale_price,

                        'a' => $product->price_a !== null
                            ? (float) $product->price_a
                            : (float) $product->sale_price,

                        'b' => $product->price_b !== null
                            ? (float) $product->price_b
                            : (float) $product->sale_price,

                        'c' => $product->price_c !== null
                            ? (float) $product->price_c
                            : (float) $product->sale_price,

                        default => (float) $product->sale_price,
                    };

                    $unitPrice = $lineData['unit_price'] !== null
                        ? (float) $lineData['unit_price']
                        : $basePrice;

                    if ($unitPrice <= 0) {
                        throw ValidationException::withMessages([
                            'items' => "El precio de {$product->name} debe ser mayor que cero.",
                        ]);
                    }

                    $unitCost = (float) $product->cost;
                    $taxRate = (float) ($product->tax_rate ?? 0);
                    $grossTotal = $this->decimal4($unitPrice * $quantity);

                    $lineDiscount = $this->resolveDiscountAmount(
                        (float) $lineData['discount'],
                        (string) $lineData['discount_type'],
                        $grossTotal,
                        "el producto {$product->name}",
                    );

                    $lineBase = $this->decimal4(
                        $grossTotal - $lineDiscount,
                    );

                    $resolvedLines[] = [
                        'product' => $product,
                        'quantity' => $quantity,
                        'unitPrice' => $unitPrice,
                        'unitCost' => $unitCost,
                        'taxRate' => $taxRate,
                        'grossTotal' => $grossTotal,
                        'lineDiscount' => $lineDiscount,
                        'lineBase' => $lineBase,
                        'generalDiscount' => 0.0,
                        'isOffer' => $isOffer,
                    ];

                    $baseAfterLineDiscounts += $lineBase;
                    $lineDiscountTotal += $lineDiscount;
                }

                $baseAfterLineDiscounts = $this->decimal4(
                    $baseAfterLineDiscounts,
                );

                $lineDiscountTotal = $this->decimal4(
                    $lineDiscountTotal,
                );

                if ($baseAfterLineDiscounts <= 0) {
                    throw ValidationException::withMessages([
                        'items' => 'La venta debe conservar un importe positivo después de los descuentos por línea.',
                    ]);
                }

                $generalDiscount = $this->resolveDiscountAmount(
                    isset($data['discount_total'])
                        ? (float) $data['discount_total']
                        : 0.0,
                    (string) (
                        $data['discount_total_type']
                        ?? 'fixed'
                    ),
                    $baseAfterLineDiscounts,
                    'la venta',
                );

                if (
                    $generalDiscount >= $baseAfterLineDiscounts
                    && $generalDiscount > 0
                ) {
                    throw ValidationException::withMessages([
                        'discount_total' => 'El descuento general debe dejar un importe positivo en la venta.',
                    ]);
                }
                $generalAllocations = $this->allocateGeneralDiscount(
                    $resolvedLines,
                    $generalDiscount,
                    $baseAfterLineDiscounts,
                );

                $subtotal = 0.0;
                $taxTotal = 0.0;

                foreach ($resolvedLines as $index => &$line) {
                    $allocatedGeneralDiscount =
                        $generalAllocations[$index] ?? 0.0;

                    $line['generalDiscount'] =
                        $allocatedGeneralDiscount;

                    $line['discountTotal'] = $this->decimal4(
                        $line['lineDiscount']
                        + $allocatedGeneralDiscount,
                    );

                    $line['lineSubtotal'] = $this->decimal4(
                        $line['lineBase']
                        - $allocatedGeneralDiscount,
                    );

                    $line['lineTax'] = $this->decimal4(
                        $line['lineSubtotal']
                        * ($line['taxRate'] / 100),
                    );

                    $line['lineTotal'] = $this->decimal4(
                        $line['lineSubtotal']
                        + $line['lineTax'],
                    );

                    $subtotal += $line['lineSubtotal'];
                    $taxTotal += $line['lineTax'];
                }

                unset($line);

                $subtotal = $this->decimal4($subtotal);
                $taxTotal = $this->decimal4($taxTotal);

                $discountTotal = $this->decimal4(
                    $lineDiscountTotal
                    + $generalDiscount,
                );

                $unroundedTotal = $this->decimal4(
                    $subtotal + $taxTotal,
                );

                $total = round(
                    $unroundedTotal,
                    0,
                    PHP_ROUND_HALF_UP,
                );

                if ($total <= 0) {
                    throw ValidationException::withMessages([
                        'total' => 'El total de la venta debe ser mayor que cero.',
                    ]);
                }

                $roundingTotal = $this->decimal4(
                    $total - $unroundedTotal,
                );

                $resolvedPayments = $this->resolvePayments(
                    $payments,
                    $paymentMethods,
                    $total,
                    null,
                    $requestedPoints === null,
                );
                $isCredit = count($resolvedPayments) === 1 && $resolvedPayments[0]['method']->type === PaymentMethod::TYPE_CREDIT;
                if ($isCredit && $customer === null) {
                    throw ValidationException::withMessages(['customer_id' => 'Para vender a crédito debe seleccionar un cliente.']);
                }

                $sale = Sale::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'user_id' => $user->id,
                    'cash_session_id' => $cashSession?->id,
                    'customer_id' => $customerId,
                    'checkout_token' => $data['checkout_token'],
                    'request_fingerprint' => $fingerprint,
                    'sale_number' => CompanySequence::nextPosNumber(
                        $companyId,
                    ),
                    'document_type' => $data['document_type'],
                    'sale_condition' => $isCredit ? Sale::CONDITION_CREDIT : Sale::CONDITION_CASH,
                    'status' => Sale::STATUS_COMPLETED,
                    'currency_code' => $company->currency,
                    'exchange_rate' => 1,
                    'subtotal' => $subtotal,
                    'discount_total' => $discountTotal,
                    'tax_total' => $taxTotal,
                    'rounding_total' => $roundingTotal,
                    'total' => $total,
                    'paid_total' => $isCredit ? 0 : $total,
                    'balance_due' => $isCredit ? $total : 0,
                    'due_date' => $isCredit ? now()->startOfDay()->addDays((int) $customer->credit_days) : null,
                    'notes' => null,
                    'completed_at' => now(),
                ]);

                foreach ($resolvedLines as $line) {
                    $product = $line['product'];

                    SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $product->id,
                        'product_code' => $product->internal_code,
                        'barcode' => $product->barcode,
                        'cabys_code' => $product->cabys_code,
                        'description' => $product->name,
                        'unit_code' => $product->unit?->abbreviation,
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unitPrice'],
                        'is_offer' => $line['isOffer'],
                        'gross_total' => $line['grossTotal'],
                        'discount_total' => $line['discountTotal'],
                        'subtotal' => $line['lineSubtotal'],
                        'tax_rate' => $line['taxRate'],
                        'tax_total' => $line['lineTax'],
                        'total' => $line['lineTotal'],
                        'unit_cost' => $line['unitCost'],
                    ]);

                    if ($product->track_inventory) {
                        $this->inventoryPostingService->postSale(
                            $sale,
                            $product,
                            $line['quantity'],
                        );
                    }
                }

                if ($requestedPoints !== null) {
                    if ($customer === null) {
                        throw ValidationException::withMessages([
                            'customer_id' => 'Debe seleccionar un cliente para canjear puntos.',
                        ]);
                    }

                    if ($isCredit) {
                        throw ValidationException::withMessages([
                            'payments' => 'No es posible combinar el canje de puntos con una venta a crédito.',
                        ]);
                    }

                    $loyaltyMethod = PaymentMethod::query()
                        ->where('company_id', $companyId)
                        ->where('is_active', true)
                        ->where('type', PaymentMethod::TYPE_LOYALTY_POINTS)
                        ->orderBy('id')
                        ->first();

                    if ($loyaltyMethod === null) {
                        throw ValidationException::withMessages([
                            'payments' => 'El método de pago con puntos de fidelidad no está disponible.',
                        ]);
                    }

                    $hasOffers = array_reduce(
                        $resolvedLines,
                        fn (bool $carry, array $line) => $carry || $line['isOffer'],
                        false,
                    );

                    $redemption = $this->loyaltyRedemptionService->redeem(
                        $customer,
                        $company,
                        $requestedPoints,
                        number_format($total, 4, '.', ''),
                        [
                            'branch' => $branch,
                            'user' => $user,
                            'source_type' => Sale::class,
                            'source_id' => $sale->id,
                            'event_key' => "sale:{$sale->id}:loyalty:redemption",
                            'description' => "Canje de puntos en venta {$sale->sale_number}",
                            'effective_at' => $sale->completed_at,
                            'metadata' => ['sale_number' => $sale->sale_number],
                            'is_offer' => $hasOffers,
                        ],
                    );

                    $cashApplied = $this->decimal4(
                        array_sum(array_map(
                            fn (array $payment) => $payment['amount'],
                            $resolvedPayments,
                        )),
                    );

                    if ($cashApplied !== $this->decimal4($total - (float) $redemption['redeemed_amount'])) {
                        throw ValidationException::withMessages([
                            'payments' => 'La suma de los pagos debe ser exactamente igual al total de la venta menos el monto canjeado con puntos.',
                        ]);
                    }

                    SalePayment::create([
                        'sale_id' => $sale->id,
                        'cash_session_id' => $cashSession?->id,
                        'payment_method_id' => $loyaltyMethod->id,
                        'affects_cash_snapshot' => false,
                        'created_by' => $user->id,
                        'amount' => $redemption['redeemed_amount'],
                        'received_amount' => $redemption['redeemed_amount'],
                        'change_amount' => 0,
                        'cash_effect_amount' => 0,
                        'reference' => null,
                        'status' => SalePayment::STATUS_COMPLETED,
                    ]);
                }

                foreach ($resolvedPayments as $payment) {
                    if ($payment['method']->type === PaymentMethod::TYPE_CREDIT) {
                        continue;
                    }
                    SalePayment::create([
                        'sale_id' => $sale->id,
                        'cash_session_id' => $cashSession?->id,
                        'payment_method_id' => $payment['method']->id,
                        'affects_cash_snapshot' => $payment['method']->affects_cash,
                        'created_by' => $user->id,
                        'amount' => $payment['amount'],
                        'received_amount' => $payment['received_amount'],
                        'change_amount' => $payment['change_amount'],
                        'cash_effect_amount' => $payment['method']->affects_cash
                                ? $payment['amount']
                                : 0,
                        'reference' => $payment['reference'],
                        'status' => SalePayment::STATUS_COMPLETED,
                    ]);
                }

                if ($isCredit) {
                    $this->accountsReceivableService->createForSale($sale, $customer);
                }

                if ($suspendedSale !== null) {
                    $suspendedSale->update([
                        'status' => SuspendedSale::STATUS_RECOVERED,
                        'recovered_sale_id' => $sale->id,
                        'recovered_at' => now(),
                    ]);
                }

                if ($quote !== null) {
                    $quote->update(['status' => Quote::STATUS_CONVERTED, 'converted_sale_id' => $sale->id, 'converted_at' => now()]);
                }

                $this->awardReturningCustomerLoyalty($sale, $customer, $company, $branch, $user);
                $this->accrueLoyalty($sale, $customer, $company, $branch, $user);
                $this->awardBirthdayLoyalty($sale, $customer, $company, $branch, $user);
                $this->loyaltyRegistrationIncentiveService->tryAwardAfterPurchase($sale);

                return $sale;
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->existingSale(
                $companyId,
                $data['checkout_token'],
                $fingerprint,
            );

            if ($existing !== null) {
                $this->verifyRecoveredSuspension(
                    $data,
                    $existing,
                    $user,
                    $companyId,
                    $branchId,
                );

                return [
                    'sale' => $existing,
                    'duplicate' => true,
                ];
            }

            throw $exception;
        }

        return [
            'sale' => $sale,
            'duplicate' => false,
        ];
    }

    private function accrueLoyalty(Sale $sale, ?Customer $customer, Company $company, Branch $branch, User $user): void
    {
        if ($customer === null || $sale->status !== Sale::STATUS_COMPLETED) {
            return;
        }

        try {
            $setting = LoyaltySetting::query()->where('company_id', $company->id)->first();
            $offerEligibility = $this->loyaltyOfferEligibilityService->forSale($sale, (bool) $setting?->earn_on_offers);
            $this->loyaltyEarningService->earnFromEligibleAmount(
                $customer,
                $company,
                $offerEligibility['eligible_amount'],
                [
                    'branch' => $branch,
                    'user' => $user,
                    'source_type' => Sale::class,
                    'source_id' => $sale->id,
                    'event_key' => "sale:{$sale->id}:loyalty:earn",
                    'description' => "Puntos por venta {$sale->sale_number}",
                    'effective_at' => $sale->completed_at,
                    'metadata' => [
                        'sale_number' => $sale->sale_number,
                        'document_type' => $sale->document_type,
                        'offer_eligibility' => $offerEligibility,
                    ],
                ],
            );
        } catch (ValidationException $exception) {
            if (array_key_exists('loyalty', $exception->errors())) {
                return;
            }

            throw $exception;
        }
    }

    private function awardBirthdayLoyalty(Sale $sale, ?Customer $customer, Company $company, Branch $branch, User $user): void
    {
        if ($customer === null || $sale->status !== Sale::STATUS_COMPLETED) {
            return;
        }

        $this->loyaltyBirthdayService->awardIfEligible(
            $customer,
            $company,
            $sale->completed_at,
            [
                'branch' => $branch,
                'user' => $user,
                'source_type' => Sale::class,
                'source_id' => $sale->id,
                'description' => "Bono de cumpleaños por venta {$sale->sale_number}",
                'metadata' => ['sale_number' => $sale->sale_number],
            ],
        );
    }

    private function awardReturningCustomerLoyalty(Sale $sale, ?Customer $customer, Company $company, Branch $branch, User $user): void
    {
        if ($customer === null || $sale->status !== Sale::STATUS_COMPLETED) {
            return;
        }

        $this->loyaltyReturningCustomerService->awardIfEligible(
            $customer,
            $company,
            $sale->id,
            $sale->completed_at,
            [
                'branch' => $branch,
                'user' => $user,
                'source_type' => Sale::class,
                'description' => "Bono por retorno en venta {$sale->sale_number}",
                'metadata' => ['sale_number' => $sale->sale_number],
            ],
        );
    }

    private function consolidateItems(array $items): array
    {
        $consolidated = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];

            $line = [
                'quantity' => $this->decimal4(
                    (float) $item['quantity'],
                ),
                'discount' => isset($item['discount'])
                    ? $this->decimal4(
                        (float) $item['discount'],
                    )
                    : 0.0,
                'discount_type' => (string) (
                    $item['discount_type']
                    ?? 'fixed'
                ),
                'unit_price' => array_key_exists('unit_price', $item)
                    && $item['unit_price'] !== null
                        ? $this->decimal4(
                            (float) $item['unit_price'],
                        )
                        : null,
            ];

            if (! isset($consolidated[$productId])) {
                $consolidated[$productId] = $line;

                continue;
            }

            $existing = $consolidated[$productId];

            $hasAdjustments =
                $existing['discount'] > 0
                || $line['discount'] > 0
                || $existing['unit_price'] !== null
                || $line['unit_price'] !== null;

            if ($hasAdjustments) {
                throw ValidationException::withMessages([
                    'items' => 'No puede repetir un producto con descuento o precio manual en la misma venta.',
                ]);
            }

            $consolidated[$productId]['quantity'] =
                $this->decimal4(
                    $existing['quantity']
                    + $line['quantity'],
                );
        }

        ksort(
            $consolidated,
            SORT_NUMERIC,
        );

        return $consolidated;
    }

    private function authorizeRequestedAdjustments(
        array $items,
        array $data,
        bool $canDiscount,
        bool $canOverridePrice,
    ): void {
        $hasLineDiscount = collect($items)->contains(
            fn (array $line) => (float) $line['discount'] > 0,
        );

        $hasGeneralDiscount =
            isset($data['discount_total'])
            && (float) $data['discount_total'] > 0;

        if (
            ($hasLineDiscount || $hasGeneralDiscount)
            && ! $canDiscount
        ) {
            throw ValidationException::withMessages([
                'discount' => 'No tiene permiso para aplicar descuentos en el POS.',
            ]);
        }

        $hasPriceOverride = collect($items)->contains(
            fn (array $line) => $line['unit_price'] !== null,
        );

        if (
            $hasPriceOverride
            && ! $canOverridePrice
        ) {
            throw ValidationException::withMessages([
                'unit_price' => 'No tiene permiso para cambiar precios en el POS.',
            ]);
        }
    }

    private function resolveDiscountAmount(
        float $value,
        string $type,
        float $base,
        string $context,
    ): float {
        if ($value < 0) {
            throw ValidationException::withMessages([
                'discount' => "El descuento para {$context} no puede ser negativo.",
            ]);
        }

        if (! in_array(
            $type,
            ['fixed', 'percentage'],
            true,
        )) {
            throw ValidationException::withMessages([
                'discount' => "El tipo de descuento para {$context} no es válido.",
            ]);
        }

        if ($type === 'percentage') {
            if ($value > 100) {
                throw ValidationException::withMessages([
                    'discount' => "El descuento porcentual para {$context} no puede superar 100%.",
                ]);
            }

            return $this->decimal4(
                $base * ($value / 100),
            );
        }

        if ($value > $base) {
            throw ValidationException::withMessages([
                'discount' => "El descuento fijo para {$context} supera el importe disponible.",
            ]);
        }

        return $this->decimal4($value);
    }

    private function allocateGeneralDiscount(
        array $resolvedLines,
        float $generalDiscount,
        float $baseAfterLineDiscounts,
    ): array {
        if ($generalDiscount <= 0) {
            return array_fill(
                0,
                count($resolvedLines),
                0.0,
            );
        }

        $allocations = [];
        $allocated = 0.0;
        $lastPositiveIndex = null;

        foreach ($resolvedLines as $index => $line) {
            if ($line['lineBase'] > 0) {
                $lastPositiveIndex = $index;
            }

            $share = $baseAfterLineDiscounts > 0
                ? $this->decimal4(
                    $generalDiscount
                    * (
                        $line['lineBase']
                        / $baseAfterLineDiscounts
                    ),
                )
                : 0.0;

            $share = min(
                $share,
                $line['lineBase'],
            );

            $allocations[$index] = $share;

            $allocated = $this->decimal4(
                $allocated + $share,
            );
        }

        $remainder = $this->decimal4(
            $generalDiscount - $allocated,
        );

        if (
            $lastPositiveIndex !== null
            && abs($remainder) > 0.0000001
        ) {
            $adjusted = $this->decimal4(
                $allocations[$lastPositiveIndex]
                + $remainder,
            );

            if (
                $adjusted < 0
                || $adjusted
                    > $resolvedLines[$lastPositiveIndex]['lineBase']
            ) {
                throw ValidationException::withMessages([
                    'discount_total' => 'No fue posible distribuir el descuento general de forma consistente.',
                ]);
            }

            $allocations[$lastPositiveIndex] =
                $adjusted;
        }

        return $allocations;
    }

    private function validateQuantity(
        Product $product,
        float $quantity,
    ): void {
        if (
            $quantity <= 0
            || abs(
                $quantity
                - $this->decimal4($quantity),
            ) > 0.0000001
        ) {
            throw ValidationException::withMessages([
                'items' => "La cantidad de {$product->name} no es válida.",
            ]);
        }

        if (
            ! $product->unit?->allows_decimals
            && floor($quantity) !== $quantity
        ) {
            throw ValidationException::withMessages([
                'items' => "La unidad de {$product->name} requiere una cantidad entera.",
            ]);
        }
    }

    private function canonicalPayments(
        array $payments,
    ): array {
        $canonical = array_map(
            fn (array $payment) => [
                'payment_method_id' => (int) $payment['payment_method_id'],

                'amount' => number_format(
                    (float) $payment['amount'],
                    4,
                    '.',
                    '',
                ),

                'received_amount' => array_key_exists(
                    'received_amount',
                    $payment,
                )
                    && $payment['received_amount'] !== null
                        ? number_format(
                            (float) $payment['received_amount'],
                            4,
                            '.',
                            '',
                        )
                        : null,

                'reference' => isset($payment['reference'])
                    && trim(
                        (string) $payment['reference'],
                    ) !== ''
                        ? trim(
                            (string) $payment['reference'],
                        )
                        : null,
            ],
            array_values($payments),
        );

        $methodIds = array_column(
            $canonical,
            'payment_method_id',
        );

        if (
            count($methodIds)
            !== count(array_unique($methodIds))
        ) {
            throw ValidationException::withMessages([
                'payments' => 'No puede repetir una forma de pago en la misma venta.',
            ]);
        }

        return $canonical;
    }

    private function canonicalRequestedPoints(
        array $data,
    ): ?string {
        if (
            ! array_key_exists('requested_points', $data)
            || $data['requested_points'] === null
            || trim((string) $data['requested_points']) === ''
        ) {
            return null;
        }

        return number_format(
            (float) $data['requested_points'],
            4,
            '.',
            '',
        );
    }

    private function resolvePayments(
        array $payments,
        $paymentMethods,
        float $total,
        ?float $coverageTarget = null,
        bool $enforceCoverage = true,
    ): array {
        $creditPayments = array_filter($payments, fn ($payment) => $paymentMethods->get($payment['payment_method_id'])?->type === PaymentMethod::TYPE_CREDIT);
        if ($creditPayments !== []) {
            if (count($payments) !== 1 || count($creditPayments) !== 1 || (float) array_values($creditPayments)[0]['amount'] !== $total) {
                throw ValidationException::withMessages(['payments' => 'En Crédito V1 la venta debe pagarse completamente a crédito; el crédito mixto no está disponible.']);
            }
            $payment = array_values($creditPayments)[0];

            return [['method' => $paymentMethods->get($payment['payment_method_id']), 'amount' => $total, 'received_amount' => null, 'change_amount' => 0, 'reference' => $payment['reference']]];
        }

        $resolved = [];
        $applied = 0.0;
        $changeProducerSeen = false;
        $coverage = $coverageTarget ?? $total;

        foreach ($payments as $index => $payment) {
            $method = $paymentMethods->get(
                $payment['payment_method_id'],
            );

            if (
                in_array(
                    $method->type,
                    [
                        PaymentMethod::TYPE_LOYALTY_POINTS,
                    ],
                    true,
                )
            ) {
                throw ValidationException::withMessages([
                    'payments' => "El método {$method->name} todavía no está disponible en el POS.",
                ]);
            }

            if (
                $method->requires_reference
                && $payment['reference'] === null
            ) {
                throw ValidationException::withMessages([
                    'payments' => "La referencia es obligatoria para {$method->name}.",
                ]);
            }

            $amount = (float) $payment['amount'];

            $pending = $this->decimal4(
                $coverage - $applied,
            );

            if ($amount > $pending) {
                throw ValidationException::withMessages([
                    'payments' => "El monto aplicado con {$method->name} supera el saldo pendiente.",
                ]);
            }

            if ($method->allows_change) {
                $received =
                    $payment['received_amount'] === null
                        ? $amount
                        : (float) $payment['received_amount'];

                if ($received < $amount) {
                    throw ValidationException::withMessages([
                        'payments' => "El monto recibido con {$method->name} es insuficiente.",
                    ]);
                }

                $change = $this->decimal4(
                    $received - $amount,
                );

                if ($change > 0) {
                    if (
                        $changeProducerSeen
                        || $index !== array_key_last($payments)
                    ) {
                        throw ValidationException::withMessages([
                            'payments' => 'El único pago que produce vuelto debe ser el último.',
                        ]);
                    }

                    $changeProducerSeen = true;
                }
            } else {
                $received = $amount;
                $change = 0.0;
            }

            $resolved[] = [
                'method' => $method,
                'amount' => $amount,
                'received_amount' => $received,
                'change_amount' => $change,
                'reference' => $payment['reference'],
            ];

            $applied = $this->decimal4(
                $applied + $amount,
            );
        }

        if (
            $enforceCoverage
            && $applied !== $this->decimal4($coverage)
        ) {
            throw ValidationException::withMessages([
                'payments' => 'La suma de los pagos debe ser exactamente igual al total de la venta.',
            ]);
        }

        return $resolved;
    }

    private function fingerprint(
        array $data,
        array $items,
        array $payments,
        ?string $requestedPoints,
        int $userId,
        int $companyId,
        int $branchId,
    ): string {
        return hash(
            'sha256',
            json_encode([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'user_id' => $userId,

                'cash_session_id' => isset($data['cash_session_id'])
                        ? (int) $data['cash_session_id']
                        : null,

                'customer_id' => isset($data['customer_id'])
                        ? (int) $data['customer_id']
                        : null,

                'payments' => $payments,

                'requested_points' => $requestedPoints,

                'items' => array_map(
                    fn (array $line) => [
                        'quantity' => number_format(
                            $line['quantity'],
                            4,
                            '.',
                            '',
                        ),

                        'discount' => number_format(
                            $line['discount'],
                            4,
                            '.',
                            '',
                        ),

                        'discount_type' => $line['discount_type'],

                        'unit_price' => $line['unit_price'] === null
                                ? null
                                : number_format(
                                    $line['unit_price'],
                                    4,
                                    '.',
                                    '',
                                ),
                    ],
                    $items,
                ),

                'discount_total' => isset($data['discount_total'])
                        ? number_format(
                            (float) $data['discount_total'],
                            4,
                            '.',
                            '',
                        )
                        : '0.0000',

                'discount_total_type' => (string) (
                    $data['discount_total_type']
                    ?? 'fixed'
                ),

                'suspended_sale_id' => isset($data['suspended_sale_id'])
                        ? (int) $data['suspended_sale_id']
                        : null,

                'recovery_token' => $data['recovery_token']
                    ?? null,

                'quote_id' => isset($data['quote_id']) ? (int) $data['quote_id'] : null,
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function lockSuspensionForCheckout(
        array $data,
        User $user,
        int $companyId,
        int $branchId,
    ): ?SuspendedSale {
        if (
            ! isset(
                $data['suspended_sale_id'],
                $data['recovery_token'],
            )
        ) {
            return null;
        }

        $suspended = SuspendedSale::query()
            ->lockForUpdate()
            ->find($data['suspended_sale_id']);

        if (
            ! $suspended
            || (int) $suspended->company_id !== $companyId
            || (int) $suspended->branch_id !== $branchId
        ) {
            throw ValidationException::withMessages([
                'suspended_sale_id' => 'La venta suspendida no pertenece al contexto activo.',
            ]);
        }

        if (
            $suspended->status
                !== SuspendedSale::STATUS_RECOVERING
            || (int) $suspended->recovery_by
                !== (int) $user->id
            || $suspended->recovery_token === null
            || ! hash_equals(
                $suspended->recovery_token,
                $data['recovery_token'],
            )
        ) {
            throw new ConflictHttpException(
                'La concesión de recuperación no es válida.',
            );
        }

        if (
            ! $suspended->recovery_started_at
            || $suspended->recovery_started_at->lte(
                now()->subMinutes(
                    SuspendedSaleService::RECOVERY_LEASE_MINUTES,
                ),
            )
        ) {
            throw new ConflictHttpException(
                'La concesión de recuperación venció. Recupere nuevamente la venta.',
            );
        }

        return $suspended;
    }

    private function lockQuoteForCheckout(array $data, int $companyId, int $branchId): ?Quote
    {
        if (! isset($data['quote_id'])) {
            return null;
        }

        $quote = Quote::query()->lockForUpdate()->find($data['quote_id']);
        if (! $quote || (int) $quote->company_id !== $companyId || (int) $quote->branch_id !== $branchId) {
            throw ValidationException::withMessages(['quote_id' => 'La cotización no pertenece al contexto activo.']);
        }
        if ($quote->status !== Quote::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['quote_id' => 'La cotización ya no está activa.']);
        }
        if ($quote->expires_at?->isBefore(today())) {
            throw ValidationException::withMessages(['quote_id' => 'La cotización está vencida.']);
        }

        return $quote;
    }

    private function verifyRecoveredSuspension(
        array $data,
        Sale $sale,
        User $user,
        int $companyId,
        int $branchId,
    ): void {
        if (
            ! isset(
                $data['suspended_sale_id'],
                $data['recovery_token'],
            )
        ) {
            return;
        }

        $linked = SuspendedSale::query()
            ->whereKey($data['suspended_sale_id'])
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('recovery_by', $user->id)
            ->where(
                'recovery_token',
                $data['recovery_token'],
            )
            ->where(
                'status',
                SuspendedSale::STATUS_RECOVERED,
            )
            ->where(
                'recovered_sale_id',
                $sale->id,
            )
            ->exists();

        if (! $linked) {
            throw new ConflictHttpException(
                'La venta suspendida no coincide con el cobro ya procesado.',
            );
        }
    }

    private function existingSale(
        int $companyId,
        string $token,
        string $fingerprint,
    ): ?Sale {
        $sale = Sale::query()
            ->where('company_id', $companyId)
            ->where('checkout_token', $token)
            ->first();

        if (
            $sale !== null
            && ! hash_equals(
                (string) $sale->request_fingerprint,
                $fingerprint,
            )
        ) {
            throw new ConflictHttpException(
                'El token de cobro ya fue utilizado con datos diferentes.',
            );
        }

        return $sale;
    }

    private function decimal4(
        float $value,
    ): float {
        return round(
            $value,
            4,
            PHP_ROUND_HALF_UP,
        );
    }
}
