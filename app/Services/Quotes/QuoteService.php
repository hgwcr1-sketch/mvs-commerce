<?php

namespace App\Services\Quotes;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuoteService
{
    public function create(array $data, User $user, int $companyId, int $branchId): Quote
    {
        return DB::transaction(function () use ($data, $user, $companyId, $branchId) {
            $company = Company::query()
                ->where('is_active', true)
                ->find($companyId);

            if ($company === null || ! $user->companies()->whereKey($companyId)->exists()) {
                throw ValidationException::withMessages([
                    'company' => 'La empresa activa ya no está autorizada.',
                ]);
            }

            $branch = Branch::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->find($branchId);

            if ($branch === null || ! $user->branches()->whereKey($branchId)->exists()) {
                throw ValidationException::withMessages([
                    'branch' => 'La sucursal activa ya no está autorizada.',
                ]);
            }

            $customer = null;

            if (! empty($data['customer_id'])) {
                $customer = Customer::query()
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereKey($data['customer_id'])
                    ->first();

                if ($customer === null) {
                    throw ValidationException::withMessages([
                        'customer_id' => 'El cliente no está disponible para esta empresa.',
                    ]);
                }
            }

            $productIds = array_map(
                fn (array $item) => (int) $item['product_id'],
                $data['items'],
            );

            $products = Product::query()
                ->with('unit:id,abbreviation,allows_decimals')
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== count($productIds)) {
                throw ValidationException::withMessages([
                    'items' => 'Uno o más productos no están disponibles.',
                ]);
            }

            $snapshotItems = [];
            $subtotal = 0.0;
            $discountTotal = 0.0;
            $taxTotal = 0.0;
            $total = 0.0;

            foreach ($data['items'] as $item) {
                $product = $products->get((int) $item['product_id']);
                $quantity = $this->decimal4((float) $item['quantity']);

                $this->validateQuantity($product, $quantity);

                $basePrice = $this->basePrice($product, $customer);
                $unitPrice = ! empty($item['unit_price'])
                    ? $this->decimal4((float) $item['unit_price'])
                    : $this->decimal4($basePrice);

                if ($unitPrice <= 0) {
                    throw ValidationException::withMessages([
                        'items' => "El precio de {$product->name} debe ser mayor que cero.",
                    ]);
                }

                $grossTotal = $this->decimal4($unitPrice * $quantity);

                $lineDiscount = $this->resolveDiscountAmount(
                    (float) ($item['discount'] ?? 0),
                    (string) ($item['discount_type'] ?? 'fixed'),
                    $grossTotal,
                    "el producto {$product->name}",
                );

                $lineSubtotal = $this->decimal4($grossTotal - $lineDiscount);
                $taxRate = $this->decimal4((float) ($product->tax_rate ?? 0));
                $lineTax = $this->decimal4($lineSubtotal * ($taxRate / 100));
                $lineTotal = $this->decimal4($lineSubtotal + $lineTax);

                $subtotal += $lineSubtotal;
                $discountTotal += $lineDiscount;
                $taxTotal += $lineTax;
                $total += $lineTotal;

                $snapshotItems[] = [
                    'product_id' => $product->id,
                    'product_code' => $product->internal_code,
                    'barcode' => $product->barcode,
                    'cabys_code' => $product->cabys_code,
                    'description' => $product->name,
                    'unit_code' => $product->unit?->abbreviation,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'gross_total' => $grossTotal,
                    'discount_total' => $lineDiscount,
                    'subtotal' => $lineSubtotal,
                    'tax_rate' => $taxRate,
                    'tax_total' => $lineTax,
                    'total' => $lineTotal,
                    'unit_cost' => $this->decimal4((float) $product->cost),
                ];
            }

            $quote = Quote::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'customer_id' => $customer?->id,
                'quote_number' => CompanySequence::nextQuoteNumber($companyId),
                'status' => Quote::STATUS_ACTIVE,
                'converted' => false,
                'cancelled' => false,
                'cancellation_enabled' => true,
                'expires_at' => $data['expires_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'subtotal' => $this->decimal4($subtotal),
                'discount_total' => $this->decimal4($discountTotal),
                'tax_total' => $this->decimal4($taxTotal),
                'total' => $this->decimal4($total),
            ]);

            $quote->items()->createMany($snapshotItems);

            return $quote->load('items', 'customer', 'user');
        });
    }

    public function cancel(Quote $quote, User $user, int $companyId, string $reason): Quote
    {
        return DB::transaction(function () use ($quote, $user, $companyId, $reason) {
            $fresh = Quote::query()
                ->lockForUpdate()
                ->find($quote->id);

            if ($fresh === null || (int) $fresh->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'quote_id' => 'La cotización no pertenece a la empresa activa.',
                ]);
            }

            if (! $fresh->canBeCancelled()) {
                throw ValidationException::withMessages([
                    'status' => 'Solo las cotizaciones activas pueden cancelarse.',
                ]);
            }

            $fresh->update([
                'status' => Quote::STATUS_CANCELLED,
                'cancelled' => true,
                'cancellation_reason' => $reason,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
            ]);

            return $fresh;
        });
    }

    private function basePrice(Product $product, ?Customer $customer): float
    {
        $level = $customer?->price_level ?? 'normal';

        return match ($level) {
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
    }

    private function validateQuantity(Product $product, float $quantity): void
    {
        if (
            $quantity <= 0
            || abs($quantity - $this->decimal4($quantity)) > 0.0000001
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

        if (! in_array($type, ['fixed', 'percentage'], true)) {
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

            return $this->decimal4($base * ($value / 100));
        }

        if ($value > $base) {
            throw ValidationException::withMessages([
                'discount' => "El descuento fijo para {$context} supera el importe disponible.",
            ]);
        }

        return $this->decimal4($value);
    }

    private function decimal4(float $value): float
    {
        return round($value, 4, PHP_ROUND_HALF_UP);
    }
}
