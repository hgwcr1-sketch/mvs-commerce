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
use App\Models\User;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PosSaleProcessor
{
    public function __construct(
        private readonly InventoryPostingService $inventoryPostingService,
    ) {
    }

    /** @return array{sale: Sale, duplicate: bool} */
    public function process(array $data, User $user, int $companyId, int $branchId): array
    {
        $items = $this->consolidateItems($data['items']);
        $fingerprint = $this->fingerprint($data, $items, $user->id, $companyId, $branchId);

        $existing = $this->existingSale($companyId, $data['checkout_token'], $fingerprint);

        if ($existing !== null) {
            return ['sale' => $existing, 'duplicate' => true];
        }

        try {
            $sale = DB::transaction(function () use ($data, $items, $fingerprint, $user, $companyId, $branchId) {
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

                $customerId = $data['customer_id'] ?? null;
                if ($customerId !== null && !Customer::query()
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereKey($customerId)
                    ->exists()) {
                    throw ValidationException::withMessages(['customer_id' => 'El cliente no está disponible para esta empresa.']);
                }

                $paymentMethod = PaymentMethod::query()
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->where('type', PaymentMethod::TYPE_CASH)
                    ->where('allows_change', true)
                    ->find($data['payment_method_id']);

                if ($paymentMethod === null) {
                    throw ValidationException::withMessages(['payment_method_id' => 'Seleccione un método de Efectivo válido.']);
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

                foreach ($items as $productId => $quantity) {
                    $product = $products->get($productId);
                    $this->validateQuantity($product, $quantity);

                    $unitPrice = (float) $product->sale_price;
                    $unitCost = (float) $product->cost;
                    $taxRate = (float) ($product->tax_rate ?? 0);
                    $grossTotal = $this->decimal4($unitPrice * $quantity);
                    $lineSubtotal = $grossTotal;
                    $lineTax = $this->decimal4($lineSubtotal * ($taxRate / 100));
                    $lineTotal = $this->decimal4($lineSubtotal + $lineTax);

                    $resolvedLines[] = compact(
                        'product', 'quantity', 'unitPrice', 'unitCost', 'taxRate',
                        'grossTotal', 'lineSubtotal', 'lineTax', 'lineTotal',
                    );
                    $subtotal += $lineSubtotal;
                    $taxTotal += $lineTax;
                }

                $subtotal = $this->decimal4($subtotal);
                $taxTotal = $this->decimal4($taxTotal);
                $unroundedTotal = $this->decimal4($subtotal + $taxTotal);
                $total = round($unroundedTotal, 0, PHP_ROUND_HALF_UP);
                $roundingTotal = $this->decimal4($total - $unroundedTotal);
                $receivedAmount = (float) $data['received_amount'];

                if ($receivedAmount < $total) {
                    throw ValidationException::withMessages(['received_amount' => 'El monto recibido es insuficiente.']);
                }

                $sale = Sale::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'user_id' => $user->id,
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
                    'discount_total' => 0,
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
                        'discount_total' => 0,
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

                SalePayment::create([
                    'sale_id' => $sale->id,
                    'payment_method_id' => $paymentMethod->id,
                    'created_by' => $user->id,
                    'amount' => $total,
                    'received_amount' => $receivedAmount,
                    'change_amount' => $receivedAmount - $total,
                    'reference' => null,
                    'status' => SalePayment::STATUS_COMPLETED,
                ]);

                return $sale;
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->existingSale($companyId, $data['checkout_token'], $fingerprint);
            if ($existing !== null) {
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
            $consolidated[$productId] = $this->decimal4(($consolidated[$productId] ?? 0) + (float) $item['quantity']);
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

    private function fingerprint(array $data, array $items, int $userId, int $companyId, int $branchId): string
    {
        return hash('sha256', json_encode([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            'payment_method_id' => (int) $data['payment_method_id'],
            'received_amount' => (string) (int) $data['received_amount'],
            'items' => array_map(fn ($quantity) => number_format($quantity, 4, '.', ''), $items),
        ], JSON_THROW_ON_ERROR));
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
