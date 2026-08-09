<?php

namespace App\Services\Purchases;

use App\Data\Purchases\PurchaseData;
use App\Data\Purchases\PurchaseLineData;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseProcessor
{
    public function __construct(
        private readonly CompanyPurchaseSettingsResolver $settingsResolver,
        private readonly ProductResolver $productResolver,
        private readonly InventoryPostingService $inventoryPostingService,
    ) {
    }

    /**
     * Procesa una compra ya normalizada por el origen manual, Excel o XML.
     */
    public function process(PurchaseData $data): Purchase
    {
        return DB::transaction(function () use ($data) {
            $company = Company::query()
                ->where('is_active', true)
                ->findOrFail($data->company_id);

            $this->validatePurchaseData($company, $data);

            $resolvedLines = $this->resolveLines($company, $data->lines);
            $totals = $this->calculateTotals($resolvedLines);

            $purchase = Purchase::create([
                'company_id' => $company->id,
                'branch_id' => $data->branch_id,
                'supplier_id' => $data->supplier_id,
                'user_id' => $data->user_id,
                'number' => $this->nextPurchaseNumber($company),
                'supplier_invoice_number' => $data->supplier_invoice_number,
                'purchase_date' => $data->purchase_date,
                'payment_type' => $data->payment_type,
                'due_date' => $data->payment_type === 'credit'
                    ? $data->due_date
                    : null,
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'tax' => $totals['tax'],
                'total' => $totals['total'],
                'status' => 'posted',
                'notes' => $data->notes,
            ]);

            foreach ($resolvedLines as $resolvedLine) {
                $product = $resolvedLine['product'];
                $line = $resolvedLine['line'];

                $purchaseItem = PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $product->id,
                    'lot_number' => $this->nullableValue($line->lot_number),
                    'expires_at' => $this->nullableValue($line->expires_at),
                    'quantity' => $resolvedLine['quantity'],
                    'unit_cost' => $resolvedLine['unit_cost'],
                    'previous_sale_price' => $product->sale_price,
                    'new_sale_price' => $line->new_sale_price,
                    'subtotal' => $resolvedLine['subtotal'],
                    'discount' => $resolvedLine['discount'],
                    'tax_rate' => $resolvedLine['tax_rate'],
                    'tax' => $resolvedLine['tax'],
                    'total' => $resolvedLine['total'],
                ]);

                $product->cost = $resolvedLine['unit_cost'];

                if ($line->new_sale_price !== null) {
                    $product->sale_price = $line->new_sale_price;
                }

                $product->save();

                $this->inventoryPostingService->postPurchase(
                    $purchase,
                    $purchaseItem,
                    $product,
                    $line,
                );
            }

            return $purchase;
        });
    }

    private function validatePurchaseData(
        Company $company,
        PurchaseData $data,
    ): void {
        $branchExists = Branch::query()
            ->where('id', $data->branch_id)
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->exists();

        if (!$branchExists) {
            throw ValidationException::withMessages([
                'branch_id' => 'La sucursal receptora no pertenece a la empresa activa.',
            ]);
        }

        if ($data->supplier_id === null) {
            throw ValidationException::withMessages([
                'supplier_id' => $this->settingsResolver
                    ->requiresSupplierAssignment($company)
                    ? 'Debe asignar un proveedor antes de confirmar la compra.'
                    : 'La compra requiere un proveedor válido.',
            ]);
        }

        $supplierExists = Supplier::query()
            ->where('id', $data->supplier_id)
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->exists();

        if (!$supplierExists) {
            throw ValidationException::withMessages([
                'supplier_id' => 'El proveedor no pertenece a la empresa activa.',
            ]);
        }

        if ($data->user_id !== null
            && !$company->users()->whereKey($data->user_id)->exists()) {
            throw ValidationException::withMessages([
                'user_id' => 'El usuario no pertenece a la empresa de la compra.',
            ]);
        }

        if ($data->purchase_date === null) {
            throw ValidationException::withMessages([
                'purchase_date' => 'La fecha de compra es obligatoria.',
            ]);
        }

        if (!in_array($data->payment_type, ['cash', 'credit'], true)) {
            throw ValidationException::withMessages([
                'payment_type' => 'El tipo de pago debe ser cash o credit.',
            ]);
        }

        if ($data->payment_type === 'credit' && $data->due_date === null) {
            throw ValidationException::withMessages([
                'due_date' => 'Debe indicar la fecha de vencimiento para compras a crédito.',
            ]);
        }

        if ($data->lines === []) {
            throw ValidationException::withMessages([
                'items' => 'La compra debe incluir al menos un producto.',
            ]);
        }
    }

    /**
     * @param list<PurchaseLineData> $lines
     * @return list<array{
     *     product: \App\Models\Product,
     *     line: PurchaseLineData,
     *     quantity: float,
     *     unit_cost: float,
     *     tax_rate: float,
     *     subtotal: float,
     *     discount: float,
     *     tax: float,
     *     total: float
     * }>
     */
    private function resolveLines(Company $company, array $lines): array
    {
        $resolvedLines = [];
        $resolvedProductIds = [];

        foreach ($lines as $line) {
            if (!$line instanceof PurchaseLineData) {
                throw ValidationException::withMessages([
                    'items' => 'Cada línea debe ser una instancia de PurchaseLineData.',
                ]);
            }

            $this->validateLine($line);

            $product = $this->productResolver->resolve($company, $line);

            if (isset($resolvedProductIds[$product->id])) {
                throw ValidationException::withMessages([
                    'items' => 'Un producto solo puede aparecer una vez por compra.',
                ]);
            }

            $resolvedProductIds[$product->id] = true;

            $quantity = $line->quantity;
            $unitCost = $line->unit_cost;
            $taxRate = $line->tax_rate ?? (float) $product->tax_rate;
            $discountPercent = $line->discount_percent ?? 0;

            $subtotal = $quantity * $unitCost;
            $discount = $subtotal * ($discountPercent / 100);
            $taxableAmount = $subtotal - $discount;
            $tax = $taxableAmount * ($taxRate / 100);
            $total = $taxableAmount + $tax;

            $resolvedLines[] = [
                'product' => $product,
                'line' => $line,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'tax_rate' => $taxRate,
                'subtotal' => round($subtotal, 2),
                'discount' => round($discount, 2),
                'tax' => round($tax, 2),
                'total' => round($total, 2),
            ];
        }

        return $resolvedLines;
    }

    private function validateLine(PurchaseLineData $line): void
    {
        if ($line->quantity === null || $line->quantity <= 0) {
            throw ValidationException::withMessages([
                'items' => 'La cantidad de cada línea debe ser mayor que cero.',
            ]);
        }

        if ($line->unit_cost === null || $line->unit_cost < 0) {
            throw ValidationException::withMessages([
                'items' => 'El costo unitario de cada línea es obligatorio.',
            ]);
        }

        if ($line->new_sale_price !== null && $line->new_sale_price < 0) {
            throw ValidationException::withMessages([
                'items' => 'El nuevo precio de venta no puede ser negativo.',
            ]);
        }

        if ($line->tax_rate !== null && $line->tax_rate < 0) {
            throw ValidationException::withMessages([
                'items' => 'La tasa de impuesto no puede ser negativa.',
            ]);
        }

        if ($line->discount_percent !== null
            && ($line->discount_percent < 0 || $line->discount_percent > 100)) {
            throw ValidationException::withMessages([
                'items' => 'El descuento debe estar entre 0 y 100.',
            ]);
        }
    }

    /**
     * @param list<array{
     *     subtotal: float,
     *     discount: float,
     *     tax: float,
     *     total: float
     * }> $resolvedLines
     * @return array{subtotal: float, discount: float, tax: float, total: float}
     */
    private function calculateTotals(array $resolvedLines): array
    {
        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;

        foreach ($resolvedLines as $line) {
            $subtotal += $line['subtotal'];
            $discount += $line['discount'];
            $tax += $line['tax'];
            $total += $line['total'];
        }

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'tax' => round($tax, 2),
            'total' => round($total, 2),
        ];
    }

    private function nextPurchaseNumber(Company $company): string
    {
        do {
            $number = 'CP-' . $company->id . '-'
                . now()->format('YmdHis') . '-'
                . Str::upper(Str::random(8));
        } while (Purchase::query()
            ->where('company_id', $company->id)
            ->where('number', $number)
            ->exists());

        return $number;
    }

    private function nullableValue(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
