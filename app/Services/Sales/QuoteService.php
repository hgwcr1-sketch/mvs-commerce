<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\Customer;
use App\Models\FiscalProfile;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItemTax;
use App\Models\User;
use App\Services\Fiscal\FiscalTaxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class QuoteService
{
    public function __construct(private readonly FiscalTaxService $fiscalTaxService)
    {
    }

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
                $line['profile'] = $this->resolveLineProfile($line['product']);
                $line['taxRate'] = $this->decimal((float) ($line['profile']->rate ?? 0));
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
                $this->persistItem($quote, $line);
            }

            return $quote->load('items');
        }, 3);
    }

    public function update(Quote $quote, array $data, User $user, int $companyId, int $branchId): Quote
    {
        return DB::transaction(function () use ($quote, $data, $user, $companyId, $branchId) {
            $company = Company::query()->where('is_active', true)->find($companyId);
            $branch = Branch::query()->where('company_id', $companyId)->where('is_active', true)->find($branchId);

            if (! $company || ! $user->companies()->whereKey($companyId)->exists()) {
                throw ValidationException::withMessages(['company' => 'La empresa activa no está autorizada.']);
            }
            if (! $branch || ! $user->branches()->whereKey($branchId)->exists()) {
                throw ValidationException::withMessages(['branch' => 'La sucursal activa no está autorizada.']);
            }

            abort_unless((int) $quote->company_id === $companyId && (int) $quote->branch_id === $branchId, 404);
            abort_unless($quote->status === Quote::STATUS_ACTIVE, 409, 'La cotización no está activa.');

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
                $line['profile'] = $this->resolveLineProfile($line['product']);
                $line['taxRate'] = $this->decimal((float) ($line['profile']->rate ?? 0));
                $line['taxTotal'] = $this->decimal($line['subtotal'] * ($line['taxRate'] / 100));
                $line['total'] = $this->decimal($line['subtotal'] + $line['taxTotal']);
            }
            unset($line);

            $quote->update([
                'customer_id' => $customer?->id,
                'subtotal' => $this->decimal(array_sum(array_column($lines, 'subtotal'))),
                'discount_total' => $this->decimal(array_sum(array_column($lines, 'discountTotal'))),
                'tax_total' => $this->decimal(array_sum(array_column($lines, 'taxTotal'))),
                'total' => round($this->decimal(array_sum(array_column($lines, 'total'))), 0, PHP_ROUND_HALF_UP),
                'expires_at' => $data['expires_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $quote->items()->delete();

            foreach ($lines as $line) {
                $this->persistItem($quote, $line);
            }

            return $quote->load('items');
        }, 3);
    }

    private function resolveLineProfile(Product $product): FiscalProfile
    {
        try {
            return $this->fiscalTaxService->resolveProductProfile($product);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'items' => "El producto {$product->name} no puede cotizarse: requiere un perfil fiscal explícito ({$exception->getMessage()}).",
            ]);
        }
    }

    private function persistItem(Quote $quote, array $line): void
    {
        $product = $line['product'];
        /** @var FiscalProfile $profile */
        $profile = $line['profile'];
        $snapshot = $this->fiscalTaxService->snapshotFromProfile($profile);

        $quoteItem = $quote->items()->create([
            'product_id' => $product->id, 'product_code' => $product->internal_code,
            'barcode' => $product->barcode, 'cabys_code' => $product->cabys_code,
            'description' => $product->name, 'unit_code' => $product->unit?->abbreviation,
            'quantity' => $line['quantity'], 'unit_price' => $line['unitPrice'],
            'gross_total' => $line['gross'], 'discount_total' => $line['discountTotal'],
            'subtotal' => $line['subtotal'], 'tax_rate' => $line['taxRate'],
            'tax_code' => $profile->tax_code,
            'tax_rate_code' => $profile->tax_rate_code,
            'tax_treatment' => $profile->treatment,
            'fiscal_source' => $profile->catalogVersion?->source,
            'fiscal_source_version' => $profile->catalogVersion?->source_version,
            'fiscal_snapshot' => $snapshot,
            'tax_total' => $line['taxTotal'], 'total' => $line['total'], 'unit_cost' => $product->cost,
        ]);

        QuoteItemTax::create([
            'quote_item_id' => $quoteItem->id,
            'tax_code' => $profile->tax_code,
            'tax_rate_code' => $profile->tax_rate_code,
            'description' => $profile->name,
            'treatment' => $profile->treatment,
            'rate' => $profile->rate,
            'factor_iva' => $profile->factor_iva,
            'base_amount' => $line['subtotal'],
            'tax_amount' => $line['taxTotal'],
            'specific_tax_data' => null,
            'exemption_snapshot' => null,
            'source' => $profile->catalogVersion?->source,
            'source_version' => $profile->catalogVersion?->source_version,
            'sequence' => 1,
        ]);
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
