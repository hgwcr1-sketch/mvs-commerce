<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SuspendedSale;
use App\Models\User;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Cash\CashSessionResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PosSaleProcessor
{
    public function __construct(
        private readonly InventoryPostingService $inventoryPostingService,
        private readonly CashSessionResolver $cashSessionResolver,
    ) {
    }

    /** @return array{sale: Sale, duplicate: bool} */
    public function process(array $data, User $user, int $companyId, int $branchId): array
    {
        $items = $this->consolidateItems($data['items']);
        $payments = $this->canonicalPayments($data['payments']);
        $fingerprint = $this->fingerprint($data, $items, $payments, $user->id, $companyId, $branchId);

        $existing = $this->existingSale($companyId, $data['checkout_token'], $fingerprint);

        if ($existing !== null) {
            $this->verifyRecoveredSuspension($data, $existing, $user, $companyId, $branchId);
            return ['sale' => $existing, 'duplicate' => true];
        }

        try {
            $sale = DB::transaction(function () use ($data, $items, $payments, $fingerprint, $user, $companyId, $branchId) {
                $company = Company::query()->where('is_active', true)->find($companyId);

                if ($company === null || !$user->companies()->whereKey($companyId)->exists()) {
                    throw ValidationException::withMessages(['company' => 'La empresa activa ya no está autorizada.']);
                }

                if ($company->currency !== 'CRC') {
                    throw ValidationException::withMessages(['currency' => 'Este cobro solo admite empresas con moneda CRC.']);
                }

                $branch = Branch::query()
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->find($branchId);

                if ($branch === null || !$user->branches()->whereKey($branchId)->exists()) {
                    throw ValidationException::withMessages(['branch' => 'La sucursal activa ya no está autorizada.']);
                }

                $cashSession = $this->cashSessionResolver->resolve($user, $companyId, $branchId, isset($data['cash_session_id']) ? (int) $data['cash_session_id'] : null, true);

                $suspendedSale = $this->lockSuspensionForCheckout($data, $user, $companyId, $branchId);

                $customerId = $data['customer_id'] ?? null;
                if ($customerId !== null && !Customer::query()
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereKey($customerId)
                    ->exists()) {
                    throw ValidationException::withMessages(['customer_id' => 'El cliente no está disponible para esta empresa.']);
                }

                $paymentMethods = PaymentMethod::query()
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereIn('id', array_column($payments, 'payment_method_id'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($paymentMethods->count() !== count($payments)) {
                    throw ValidationException::withMessages(['payments' => 'Uno o más métodos de pago no están disponibles.']);
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
                    throw ValidationException::withMessages(['items' => 'Uno o más productos no están disponibles.']);
                }

                $resolvedLines = [];
                $subtotal = 0.0;
                $taxTotal = 0.0;
                $totalLineDiscount = 0.0;

                // Verificar permisos para descuento y cambio de precio
                $canDiscount = $user->hasPermission('pos.aplicar_descuento', $company);
                $canPrice = $user->hasPermission('pos.cambiar_precio', $company);
                $requestedGeneralDiscount = isset($data['discount_total']) ? (float) $data['discount_total'] : 0.0;

                if ($requestedGeneralDiscount > 0 && !$canDiscount) {
                    throw ValidationException::withMessages(['discount_total' => 'No tiene permiso para aplicar descuentos generales.']);
                }

                foreach ($items as $productId => $lineData) {
                    $product = $products->get($productId);
                    $quantity = $lineData['quantity'];
                    $this->validateQuantity($product, $quantity);

                    // Precio: usar el del producto por defecto, o el manual si tiene permiso
                    $unitPrice = (float) $product->sale_price;
                    if ($canPrice && isset($lineData['unit_price']) && $lineData['unit_price'] > 0) {
                        $unitPrice = (float) $lineData['unit_price'];
                    }

                    $unitCost = (float) $product->cost;
                    $taxRate = (float) ($product->tax_rate ?? 0);
                    $grossTotal = $this->decimal4($unitPrice * $quantity);

                    // Descuento por línea
                    $lineDiscount = 0.0;
                    if ($canDiscount && isset($lineData['discount']) && $lineData['discount'] > 0) {
                        $discountAmount = (float) $lineData['discount'];
                        $discountType = $lineData['discountType'] ?? 'fixed';
                        $grossTotal = $line['grossTotal'];
                        
                        // Calcular descuento monto real según el tipo
                        if ($discountType === 'percentage') {
                            // Percentage: discount = gross * percentage / 100
                            // But percentage is stored as 0-100, so we need the actual % value
                            // The frontend sends discount as the UI interprets as %
                            // We need to know it's a percentage. If discount > 100, treat as fixed; else could be %
                            // To be safe: if user specifies percentage, they should send the % value
                            // But we need a way to distinguish. Let's use: if discount <= 100, could be %; 
                            // but that's ambiguous. Better: always store monetary amount, and use discount_type
                            // to indicate. If percentage, we need the % value separately.
                            
                            // Actually, let's re-think: the discount field stores the monetary amount.
                            // The discount_type tells us how to interpret it.
                            // But for percentage, we need the % value, not the monetary amount.
                            // 
                            // Solution: For percentage discounts, we store the % value in discount field
                            // (0-100), and use discount_type to indicate it's a percentage.
                            // The actual monetary discount = gross_total * discount / 100
                            
                            // But wait, the validation says "descuento nunca superior al importe bruto"
                            // For percentage, this means discount% <= 100, which is always true if <= 100.
                            // 
                            // Let's store: if discount_type = percentage, the discount field contains the % value (0-100)
                            // And we calculate: monetary_discount = gross_total * discount / 100
                            
                            // But the current validation "discount never > gross total" doesn't work well for %.
                            // Let's adjust: for percentage, max is 100 (meaning 100% = free item)
                            
                            if ($discountAmount > 100) {
                                throw ValidationException::withMessages(['items' => "El descuento porcentual no puede ser mayor a 100%."]);
                            }
                            $lineDiscount = $this->decimal4($grossTotal * $discountAmount / 100);
                            if ($lineDiscount > $grossTotal) {
                                $lineDiscount = $this->decimal4($grossTotal); // cap at gross
                            }
                        } else {
                            // Fixed amount
                            if ($discountAmount > $grossTotal) {
                                throw ValidationException::withMessages(['items' => "El descuento de {$product->name} supera el importe de la línea."]);
                            }
                            $lineDiscount = $this->decimal4($discountAmount);
                        }
                    }

                    $lineSubtotal = $this->decimal4($grossTotal - $lineDiscount);
                    $lineTax = $this->decimal4($lineSubtotal * ($taxRate / 100));
                    $lineTotal = $this->decimal4($lineSubtotal + $lineTax);

                    $resolvedLines[] = compact(
                        'product', 'quantity', 'unitPrice', 'unitCost', 'taxRate',
                        'grossTotal', 'lineDiscount', 'lineSubtotal', 'lineTax', 'lineTotal',
                    );
                    $subtotal += $lineSubtotal;
                    $taxTotal += $lineTax;
                    $totalLineDiscount += $lineDiscount;
                }

                $subtotal = $this->decimal4($subtotal);
                $taxTotal = $this->decimal4($taxTotal);

                // Aplicar descuento general
                $generalDiscount = $canDiscount ? $this->decimal4(min($requestedGeneralDiscount, $subtotal)) : 0.0;

                $totalDiscount = $this->decimal4($totalLineDiscount + $generalDiscount);
                $netAmount = $this->decimal4($subtotal - $generalDiscount);
                $unroundedTotal = $this->decimal4($netAmount + $taxTotal);
                $total = round($unroundedTotal, 0, PHP_ROUND_HALF_UP);
                $roundingTotal = $this->decimal4($total - $unroundedTotal);
                $resolvedPayments = $this->resolvePayments($payments, $paymentMethods, $total);

                $sale = Sale::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'user_id' => $user->id,
                    'cash_session_id' => $cashSession?->id,
                    'customer_id' => $customerId,
                    'checkout_token' => $data['checkout_token'],
                    'request_fingerprint' => $fingerprint,
                    'sale_number' => CompanySequence::nextPosNumber($companyId),
                    'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
                    'sale_condition' => Sale::CONDITION_CASH,
                    'status' => Sale::STATUS_COMPLETED,
                    'currency_code' => $company->currency,
                    'exchange_rate' => 1,
                    'subtotal' => $subtotal,
                    'discount_total' => $totalDiscount,
                    'tax_total' => $taxTotal,
                    'rounding_total' => $roundingTotal,
                    'total' => $total,
                    'paid_total' => $total,
                    'balance_due' => 0,
                    'due_date' => null,
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
                        'gross_total' => $line['grossTotal'],
                        'discount_total' => $line['lineDiscount'],
                        'subtotal' => $line['lineSubtotal'],
                        'tax_rate' => $line['taxRate'],
                        'tax_total' => $line['lineTax'],
                        'total' => $line['lineTotal'],
                        'unit_cost' => $line['unitCost'],
                    ]);

                    if ($product->track_inventory) {
                        $this->inventoryPostingService->postSale($sale, $product, $line['quantity']);
                    }
                }

                foreach ($resolvedPayments as $payment) {
                    SalePayment::create([
                        'sale_id' => $sale->id,
                        'cash_session_id' => $cashSession?->id,
                        'payment_method_id' => $payment['method']->id,
                        'affects_cash_snapshot' => $payment['method']->affects_cash,
                        'created_by' => $user->id,
                        'amount' => $payment['amount'],
                        'received_amount' => $payment['received_amount'],
                        'change_amount' => $payment['change_amount'],
                        'cash_effect_amount' => $payment['method']->affects_cash ? $payment['amount'] : 0,
                        'reference' => $payment['reference'],
                        'status' => SalePayment::STATUS_COMPLETED,
                    ]);
                }

                if ($suspendedSale !== null) {
                    $suspendedSale->update([
                        'status' => SuspendedSale::STATUS_RECOVERED,
                        'recovered_sale_id' => $sale->id,
                        'recovered_at' => now(),
                    ]);
                }

                return $sale;
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->existingSale($companyId, $data['checkout_token'], $fingerprint);
            if ($existing !== null) {
                $this->verifyRecoveredSuspension($data, $existing, $user, $companyId, $branchId);
                return ['sale' => $existing, 'duplicate' => true];
            }

            throw $exception;
        }

        return ['sale' => $sale, 'duplicate' => false];
    }

    private function consolidateItems(array $items): array
    {
        $consolidated = [];
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $discountAmount = 0.0;
            $discountType = 'fixed'; // default

            if (isset($item['discount'])) {
                $discountAmount = (float) $item['discount'];
            }
            if (isset($item['discount_type'])) {
                $discountType = $item['discount_type'];
            }

            if (!isset($consolidated[$productId])) {
                $consolidated[$productId] = [
                    'quantity' => 0.0,
                    'discount' => 0.0,
                    'discountType' => $discountType,
                    'unit_price' => isset($item['unit_price']) ? (float) $item['unit_price'] : 0.0,
                ];
            }

            // Accumulate quantity
            $consolidated[$productId]['quantity'] = $this->decimal4($consolidated[$productId]['quantity'] + (float) $item['quantity']);

            // If new discount type is percentage, convert; keep fixed as-is
            // We store the monetary equivalent in 'discount'
            if ($discountType === 'percentage') {
                // Percentage will be converted when calculating line discount
                // For now, store 0 and mark the type; actual calc happens later
                $consolidated[$productId]['discountType'] = 'percentage';
            } else {
                $consolidated[$productId]['discount'] = $discountAmount;
                $consolidated[$productId]['discountType'] = 'fixed';
            }
        }
        ksort($consolidated, SORT_NUMERIC);

        return $consolidated;
    }

    private function validateQuantity(Product $product, float $quantity): void
    {
        if ($quantity <= 0 || abs($quantity - $this->decimal4($quantity)) > 0.0000001) {
            throw ValidationException::withMessages(['items' => "La cantidad de {$product->name} no es válida."]);
        }
        if (!$product->unit?->allows_decimals && floor($quantity) !== $quantity) {
            throw ValidationException::withMessages(['items' => "La unidad de {$product->name} requiere una cantidad entera."]);
        }
    }

    private function canonicalPayments(array $payments): array
    {
        $canonical = array_map(fn (array $payment) => [
            'payment_method_id' => (int) $payment['payment_method_id'],
            'amount' => number_format((float) $payment['amount'], 4, '.', ''),
            'received_amount' => array_key_exists('received_amount', $payment) && $payment['received_amount'] !== null
                ? number_format((float) $payment['received_amount'], 4, '.', '')
                : null,
            'reference' => isset($payment['reference']) && trim((string) $payment['reference']) !== ''
                ? trim((string) $payment['reference'])
                : null,
        ], array_values($payments));

        $methodIds = array_column($canonical, 'payment_method_id');
        if (count($methodIds) !== count(array_unique($methodIds))) {
            throw ValidationException::withMessages(['payments' => 'No puede repetir una forma de pago en la misma venta.']);
        }

        return $canonical;
    }

    private function resolvePayments(array $payments, $paymentMethods, float $total): array
    {
        $resolved = [];
        $applied = 0.0;
        $changeProducerSeen = false;

        foreach ($payments as $index => $payment) {
            $method = $paymentMethods->get($payment['payment_method_id']);
            if (in_array($method->type, [PaymentMethod::TYPE_CREDIT, PaymentMethod::TYPE_LOYALTY_POINTS], true)) {
                throw ValidationException::withMessages(['payments' => "El método {$method->name} todavía no está disponible en el POS."]);
            }

            if ($method->requires_reference && $payment['reference'] === null) {
                throw ValidationException::withMessages(['payments' => "La referencia es obligatoria para {$method->name}."]);
            }

            $amount = (float) $payment['amount'];
            $pending = $this->decimal4($total - $applied);
            if ($amount > $pending) {
                throw ValidationException::withMessages(['payments' => "El monto aplicado con {$method->name} supera el saldo pendiente."]);
            }

            if ($method->allows_change) {
                $received = $payment['received_amount'] === null ? $amount : (float) $payment['received_amount'];
                if ($received < $amount) {
                    throw ValidationException::withMessages(['payments' => "El monto recibido con {$method->name} es insuficiente."]);
                }
                $change = $this->decimal4($received - $amount);
                if ($change > 0) {
                    if ($changeProducerSeen || $index !== array_key_last($payments)) {
                        throw ValidationException::withMessages(['payments' => 'El único pago que produce vuelto debe ser el último.']);
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
            $applied = $this->decimal4($applied + $amount);
        }

        if ($applied !== $this->decimal4($total)) {
            throw ValidationException::withMessages(['payments' => 'La suma de los pagos debe ser exactamente igual al total de la venta.']);
        }

        return $resolved;
    }

    private function fingerprint(array $data, array $items, array $payments, int $userId, int $companyId, int $branchId): string
    {
        return hash('sha256', json_encode([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'cash_session_id' => isset($data['cash_session_id']) ? (int) $data['cash_session_id'] : null,
            'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            'payments' => $payments,
            'items' => array_map(fn (array $line) => [
                'quantity' => number_format($line['quantity'], 4, '.', ''),
                'discount' => number_format($line['discount'] ?? 0, 4, '.', ''),
                'unit_price' => number_format($line['unit_price'] ?? 0, 4, '.', ''),
            ], $items),
            'discount_total' => isset($data['discount_total']) ? (float) $data['discount_total'] : 0,
            'suspended_sale_id' => isset($data['suspended_sale_id']) ? (int) $data['suspended_sale_id'] : null,
            'recovery_token' => $data['recovery_token'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    private function lockSuspensionForCheckout(array $data, User $user, int $companyId, int $branchId): ?SuspendedSale
    {
        if (!isset($data['suspended_sale_id'], $data['recovery_token'])) {
            return null;
        }

        $suspended = SuspendedSale::query()->lockForUpdate()->find($data['suspended_sale_id']);
        if (!$suspended || (int) $suspended->company_id !== $companyId || (int) $suspended->branch_id !== $branchId) {
            throw ValidationException::withMessages(['suspended_sale_id' => 'La venta suspendida no pertenece al contexto activo.']);
        }
        if ($suspended->status !== SuspendedSale::STATUS_RECOVERING
            || (int) $suspended->recovery_by !== (int) $user->id
            || $suspended->recovery_token === null
            || !hash_equals($suspended->recovery_token, $data['recovery_token'])) {
            throw new ConflictHttpException('La concesión de recuperación no es válida.');
        }
        if (!$suspended->recovery_started_at || $suspended->recovery_started_at->lte(now()->subMinutes(SuspendedSaleService::RECOVERY_LEASE_MINUTES))) {
            throw new ConflictHttpException('La concesión de recuperación venció. Recupere nuevamente la venta.');
        }

        return $suspended;
    }

    private function verifyRecoveredSuspension(array $data, Sale $sale, User $user, int $companyId, int $branchId): void
    {
        if (!isset($data['suspended_sale_id'], $data['recovery_token'])) {
            return;
        }
        $linked = SuspendedSale::query()->whereKey($data['suspended_sale_id'])
            ->where('company_id', $companyId)->where('branch_id', $branchId)
            ->where('recovery_by', $user->id)->where('recovery_token', $data['recovery_token'])
            ->where('status', SuspendedSale::STATUS_RECOVERED)->where('recovered_sale_id', $sale->id)->exists();
        if (!$linked) {
            throw new ConflictHttpException('La venta suspendida no coincide con el cobro ya procesado.');
        }
    }

    private function existingSale(int $companyId, string $token, string $fingerprint): ?Sale
    {
        $sale = Sale::query()->where('company_id', $companyId)->where('checkout_token', $token)->first();
        if ($sale !== null && !hash_equals((string) $sale->request_fingerprint, $fingerprint)) {
            throw new ConflictHttpException('El token de cobro ya fue utilizado con datos diferentes.');
        }

        return $sale;
    }

    private function decimal4(float $value): float
    {
        return round($value, 4, PHP_ROUND_HALF_UP);
    }
}
