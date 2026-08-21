<?php

namespace App\Services\Sales;

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
            $company = Company::query()->where('is_active', true)->find($companyId);
            $branch = Branch::query()->where('company_id', $companyId)->where('is_active', true)->find($branchId);

            if (! $company || ! $user->companies()->whereKey($companyId)->exists()) {
                throw ValidationException::withMessages(['company' => 'La empresa activa no está autorizada.']);
            }
            if (! $branch || ! $user->branches()->whereKey($branchId)->exists()) {
                throw ValidationException::withMessages(['branch' => 'La sucursal activa no está autorizada.']);
            }

            $customer = null;
            if (isset($data['customer_id'])) {
                $customer = Customer::query()->where('company_id', $companyId)->where('is_active', true)->find($data['customer_id']);
                if (! $customer) {
                    throw ValidationException::withMessages(['customer_id' => 'El cliente no pertenece a la empresa activa.']);
                }
            }

            $items = collect($data['items'])->keyBy(fn ($item) => (int) $item['product_id']);
            $products = Product::query()->with('unit:id,abbreviation,allows_decimals')->where('company_id', $companyId)->where('is_active', true)->whereIn('id', $items->keys())->get()->keyBy('id');
            if ($products->count() !== $items->count()) {
                throw ValidationException::withMessages(['items' => 'Uno o más productos no están disponibles.']);
            }

            $lines = [];
            foreach ($items as $productId => $item) {
                $product = $products->get($productId);
                $quantity = $this->decimal((float) $item['quantity']);
                if ($quantity <= 0 || (! $product->unit?->allows_decimals && floor($quantity) !== $quantity)) {
                    throw ValidationException::withMessages(['items' => "La cantidad de {$product->name} no es válida."]);
                }
                $basePrice = match ($customer?->price_level ?? 'normal') {
                    'wholesale' => $product->wholesale_price ?? $product->sale_price,
                    'a' => $product->price_a ?? $product->sale_price,
                    'b' => $product->price_b ?? $product->sale_price,
                    'c' => $product->price_c ?? $product->sale_price,
                    default => $product->sale_price,
                };
                $unitPrice = $this->decimal((float) ($item['unit_price'] ?? $basePrice));
                $gross = $this->decimal($quantity * $unitPrice);
                $discount = $this->discount((float) ($item['discount'] ?? 0), (string) ($item['discount_type'] ?? 'fixed'), $gross);
                $lines[] = compact('product', 'quantity', 'unitPrice', 'gross', 'discount');
            }

            $base = $this->decimal(array_sum(array_map(fn ($line) => $line['gross'] - $line['discount'], $lines)));
            $general = $this->discount((float) ($data['discount_total'] ?? 0), (string) ($data['discount_total_type'] ?? 'fixed'), $base);
            if ($base <= 0 || $general >= $base) {
                throw ValidationException::withMessages(['items' => 'La cotización debe conservar un importe positivo.']);
            }

            $allocated = 0.0;
            foreach ($lines as $index => &$line) {
                $share = $index === array_key_last($lines)
                    ? $this->decimal($general - $allocated)
                    : $this->decimal($general * (($line['gross'] - $line['discount']) / $base));
                $allocated = $this->decimal($allocated + $share);
                $line['discountTotal'] = $this->decimal($line['discount'] + $share);
                $line['subtotal'] = $this->decimal($line['gross'] - $line['discountTotal']);
                $line['taxRate'] = $this->decimal((float) ($line['product']->tax_rate ?? 0));
                $line['taxTotal'] = $this->decimal($line['subtotal'] * ($line['taxRate'] / 100));
                $line['total'] = $this->decimal($line['subtotal'] + $line['taxTotal']);
            }
            unset($line);

            $quote = Quote::create([
                'company_id' => $companyId, 'branch_id' => $branchId, 'user_id' => $user->id,
                'customer_id' => $customer?->id, 'quote_number' => CompanySequence::nextQuoteNumber($companyId),
                'status' => Quote::STATUS_ACTIVE, 'currency_code' => $company->currency,
                'subtotal' => $this->decimal(array_sum(array_column($lines, 'subtotal'))),
                'discount_total' => $this->decimal(array_sum(array_column($lines, 'discountTotal'))),
                'tax_total' => $this->decimal(array_sum(array_column($lines, 'taxTotal'))),
                'total' => round($this->decimal(array_sum(array_column($lines, 'total'))), 0, PHP_ROUND_HALF_UP),
                'expires_at' => $data['expires_at'] ?? null, 'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                $product = $line['product'];
                $quote->items()->create([
                    'product_id' => $product->id, 'product_code' => $product->internal_code,
                    'barcode' => $product->barcode, 'cabys_code' => $product->cabys_code,
                    'description' => $product->name, 'unit_code' => $product->unit?->abbreviation,
                    'quantity' => $line['quantity'], 'unit_price' => $line['unitPrice'],
                    'gross_total' => $line['gross'], 'discount_total' => $line['discountTotal'],
                    'subtotal' => $line['subtotal'], 'tax_rate' => $line['taxRate'],
                    'tax_total' => $line['taxTotal'], 'total' => $line['total'], 'unit_cost' => $product->cost,
                ]);
            }

            return $quote->load('items');
        }, 3);
    }

    private function discount(float $value, string $type, float $base): float
    {
        $amount = $type === 'percentage' ? $base * ($value / 100) : $value;
        if ($value < 0 || ($type === 'percentage' && $value > 100) || $amount > $base) {
            throw ValidationException::withMessages(['discount' => 'El descuento no es válido.']);
        }

        return $this->decimal($amount);
    }

    private function decimal(float $value): float
    {
        return round($value, 4, PHP_ROUND_HALF_UP);
    }
}
