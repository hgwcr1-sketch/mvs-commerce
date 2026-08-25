<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SuspendedSale;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SuspendedSaleService
{
    public const RECOVERY_LEASE_MINUTES = 15;

    public function suspend(array $data, User $user, int $companyId, int $branchId): SuspendedSale
    {
        return DB::transaction(function () use ($data, $user, $companyId, $branchId) {
            $snapshot = $this->resolveSnapshot($data, $user, $companyId, $branchId);

            $suspended = SuspendedSale::create([
                'company_id' => $companyId, 'branch_id' => $branchId, 'user_id' => $user->id,
                'customer_id' => $snapshot['customer_id'], 'suspension_number' => CompanySequence::nextSuspensionNumber($companyId),
                'status' => SuspendedSale::STATUS_SUSPENDED, 'currency_code' => $snapshot['currency_code'],
                'estimated_subtotal' => $snapshot['subtotal'], 'estimated_tax_total' => $snapshot['tax_total'],
                'estimated_rounding_total' => $snapshot['rounding_total'], 'estimated_total' => $snapshot['total'],
                'suspended_at' => now(),
            ]);
            $this->storeSnapshotLines($suspended, $snapshot['lines']);
            return $suspended->load('items');
        }, 3);
    }

    public function resuspend(SuspendedSale $sale, array $data, User $user, int $companyId, int $branchId): SuspendedSale
    {
        return DB::transaction(function () use ($sale, $data, $user, $companyId, $branchId) {
            $this->validateContext($user, $companyId, $branchId);
            $current = SuspendedSale::query()->forCompany($companyId)->forBranch($branchId)->lockForUpdate()->findOrFail($sale->id);
            if ($current->status === SuspendedSale::STATUS_CANCELLED) {
                throw new ConflictHttpException('Esta venta suspendida fue cancelada.');
            }
            if ($current->status === SuspendedSale::STATUS_RECOVERED) {
                throw new ConflictHttpException('Esta venta suspendida ya fue cobrada.');
            }
            if ($current->status !== SuspendedSale::STATUS_RECOVERING
                || (int) $current->recovery_by !== (int) $user->id
                || $current->recovery_token === null
                || !hash_equals($current->recovery_token, $data['recovery_token'])) {
                throw new ConflictHttpException('La recuperación ya no es válida. Vuelva a abrir la venta suspendida.');
            }
            if (!$current->recovery_started_at || $current->recovery_started_at->lte(now()->subMinutes(self::RECOVERY_LEASE_MINUTES))) {
                throw new ConflictHttpException('La concesión de recuperación venció. Vuelva a abrir la venta suspendida.');
            }

            $snapshot = $this->resolveSnapshot($data, $user, $companyId, $branchId);
            $current->items()->delete();
            $this->storeSnapshotLines($current, $snapshot['lines']);
            $current->update([
                'customer_id' => $snapshot['customer_id'], 'currency_code' => $snapshot['currency_code'],
                'estimated_subtotal' => $snapshot['subtotal'], 'estimated_tax_total' => $snapshot['tax_total'],
                'estimated_rounding_total' => $snapshot['rounding_total'], 'estimated_total' => $snapshot['total'],
                'suspended_at' => now(), 'status' => SuspendedSale::STATUS_SUSPENDED,
                'recovery_token' => null, 'recovery_started_at' => null, 'recovery_by' => null,
            ]);

            return $current->fresh('items');
        }, 3);
    }

    public function list(User $user, int $companyId, int $branchId): Collection
    {
        $company = $this->validateContext($user, $companyId, $branchId);
        return SuspendedSale::query()->forCompany($companyId)->forBranch($branchId)->suspended()
            ->when(!$user->hasPermission('ventas.ver', $company), fn ($query) => $query->where('user_id', $user->id))
            ->with(['user:id,name', 'customer:id,name'])->withCount('items')->orderByDesc('suspended_at')->get();
    }

    public function claimForRecovery(SuspendedSale $sale, User $user, int $companyId, int $branchId, ?string $providedToken = null): array
    {
        return DB::transaction(function () use ($sale, $user, $companyId, $branchId, $providedToken) {
            $company = $this->validateContext($user, $companyId, $branchId);
            $current = SuspendedSale::query()->forCompany($companyId)->forBranch($branchId)->lockForUpdate()->findOrFail($sale->id);
            if ($current->status === SuspendedSale::STATUS_CANCELLED) {
                throw new ConflictHttpException('Esta venta suspendida fue cancelada y no puede recuperarse.');
            }
            if ($current->status === SuspendedSale::STATUS_RECOVERED) {
                throw new ConflictHttpException('Esta venta suspendida ya fue cobrada.');
            }
            if ((int) $current->user_id !== (int) $user->id && !$user->hasPermission('ventas.editar', $company)) {
                throw new AccessDeniedHttpException('No tiene permiso para recuperar la suspensión de otro cajero.');
            }
            $sameLease = $current->status === SuspendedSale::STATUS_RECOVERING
                && (int) $current->recovery_by === (int) $user->id
                && $providedToken !== null && $current->recovery_token !== null
                && hash_equals($current->recovery_token, $providedToken);
            $expired = $current->status === SuspendedSale::STATUS_RECOVERING
                && $current->recovery_started_at?->lte(now()->subMinutes(self::RECOVERY_LEASE_MINUTES));
            if ($current->status === SuspendedSale::STATUS_RECOVERING
                && (int) $current->recovery_by === (int) $user->id
                && !$sameLease && !$expired) {
                throw new ConflictHttpException('Esta venta tiene una recuperación activa. Use el token vigente o espere a que venza la concesión.');
            }
            if ($current->status !== SuspendedSale::STATUS_SUSPENDED && !$sameLease && !$expired) {
                throw new ConflictHttpException('Esta venta suspendida está siendo recuperada por otro usuario.');
            }
            $token = $sameLease ? $current->recovery_token : (string) Str::uuid();
            $claimed = SuspendedSale::query()->whereKey($current->id)
                ->where(function ($query) use ($user, $providedToken) {
                    $query->where('status', SuspendedSale::STATUS_SUSPENDED)
                        ->orWhere(function ($lease) use ($user, $providedToken) {
                            $lease->where('status', SuspendedSale::STATUS_RECOVERING)
                                ->where('recovery_by', $user->id)
                                ->where('recovery_token', $providedToken);
                        })
                        ->orWhere(function ($lease) {
                            $lease->where('status', SuspendedSale::STATUS_RECOVERING)
                                ->where('recovery_started_at', '<=', now()->subMinutes(self::RECOVERY_LEASE_MINUTES));
                        });
                })
                ->update(['status' => SuspendedSale::STATUS_RECOVERING, 'recovery_token' => $token, 'recovery_started_at' => now(), 'recovery_by' => $user->id, 'updated_at' => now()]);
            if ($claimed !== 1) {
                throw new ConflictHttpException('Esta venta suspendida acaba de ser reclamada por otro usuario.');
            }
            return $this->recoveryPayload(SuspendedSale::with('items')->findOrFail($current->id), $branchId);
        }, 3);
    }

    public function cancel(SuspendedSale $sale, User $user, int $companyId, int $branchId, string $reason): SuspendedSale
    {
        return DB::transaction(function () use ($sale, $user, $companyId, $branchId, $reason) {
            $company = $this->validateContext($user, $companyId, $branchId);
            if (!$user->hasPermission('ventas.anular', $company)) {
                throw new AccessDeniedHttpException('No tiene permiso para cancelar ventas suspendidas.');
            }
            $current = SuspendedSale::query()->forCompany($companyId)->forBranch($branchId)->lockForUpdate()->findOrFail($sale->id);
            if (!in_array($current->status, [SuspendedSale::STATUS_SUSPENDED, SuspendedSale::STATUS_RECOVERING], true)) {
                throw new ConflictHttpException('La venta suspendida ya no se puede cancelar.');
            }
            $current->update(['status' => SuspendedSale::STATUS_CANCELLED, 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancellation_reason' => trim($reason)]);
            return $current;
        }, 3);
    }

    public function release(SuspendedSale $sale, User $user, int $companyId, int $branchId, string $token): SuspendedSale
    {
        return DB::transaction(function () use ($sale, $user, $companyId, $branchId, $token) {
            $this->validateContext($user, $companyId, $branchId);
            $current = SuspendedSale::query()->forCompany($companyId)->forBranch($branchId)->lockForUpdate()->findOrFail($sale->id);

            if ($current->status === SuspendedSale::STATUS_SUSPENDED && $current->recovery_token === null) {
                return $current;
            }
            if ($current->status === SuspendedSale::STATUS_CANCELLED) {
                throw new ConflictHttpException('Esta venta suspendida fue cancelada y no puede liberarse.');
            }
            if ($current->status === SuspendedSale::STATUS_RECOVERED) {
                throw new ConflictHttpException('Esta venta suspendida ya fue cobrada.');
            }
            if ($current->status !== SuspendedSale::STATUS_RECOVERING
                || (int) $current->recovery_by !== (int) $user->id
                || $current->recovery_token === null
                || !hash_equals($current->recovery_token, $token)) {
                throw new ConflictHttpException('La concesión de recuperación no pertenece al usuario o el token no es válido.');
            }

            $released = SuspendedSale::query()->whereKey($current->id)
                ->where('status', SuspendedSale::STATUS_RECOVERING)
                ->where('recovery_by', $user->id)
                ->where('recovery_token', $token)
                ->update([
                    'status' => SuspendedSale::STATUS_SUSPENDED,
                    'recovery_token' => null,
                    'recovery_started_at' => null,
                    'recovery_by' => null,
                    'updated_at' => now(),
                ]);
            if ($released !== 1) {
                throw new ConflictHttpException('La concesión cambió y no pudo liberarse.');
            }

            return $current->fresh();
        }, 3);
    }

    private function recoveryPayload(SuspendedSale $sale, int $branchId): array
    {
        $customer = $sale->customer_id ? Customer::withTrashed()->find($sale->customer_id) : null;
        $customerInvalid = $sale->customer_id !== null && (!$customer || $customer->trashed() || !$customer->is_active || (int) $customer->company_id !== (int) $sale->company_id);
        $products = Product::withTrashed()->with('unit:id,abbreviation,allows_decimals')->whereIn('id', $sale->items->pluck('product_id')->filter())->get()->keyBy('id');
        $stocks = DB::table('branch_product')->where('branch_id', $branchId)->whereIn('product_id', $products->keys())->pluck('stock', 'product_id');
        $warnings = $customerInvalid ? ['El cliente ya no está activo. Debe quitarlo antes de cobrar.'] : [];
        $canCheckout = !$customerInvalid;
        $companyId = (int) $sale->company_id;
        $items = $sale->items->map(function ($snapshot) use ($products, $stocks, $companyId, $customer, &$warnings, &$canCheckout) {
            $product = $snapshot->product_id ? $products->get($snapshot->product_id) : null;
            $unavailable = !$product || $product->trashed() || !$product->is_active || (int) $product->company_id !== $companyId;
            if ($unavailable) {
                $canCheckout = false;
                $warnings[] = "{$snapshot->description} ya no está disponible y debe retirarse.";
                return ['product_id' => $snapshot->product_id, 'name' => $snapshot->description, 'code' => $snapshot->product_code, 'barcode' => $snapshot->barcode, 'quantity' => $snapshot->quantity, 'price' => $snapshot->estimated_unit_price, 'tax_rate' => $snapshot->estimated_tax_rate, 'stock' => 0, 'track_inventory' => true, 'allows_decimals' => true, 'unit' => $snapshot->unit_code, 'image_url' => null, 'unavailable' => true];
            }
            $stock = (float) ($stocks[$product->id] ?? 0);
            $currentPrice = match ($customer?->price_level ?? 'normal') {
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

$priceChanged = $this->decimal4((float) $snapshot->estimated_unit_price)
    !== $this->decimal4($currentPrice);

$taxChanged = $this->decimal4((float) $snapshot->estimated_tax_rate)
    !== $this->decimal4((float) ($product->tax_rate ?? 0));

$stockInsufficient = $product->track_inventory
    && (float) $snapshot->quantity > $stock;

if ($priceChanged) {
    $warnings[] = "{$product->name}: precio cambió de {$snapshot->estimated_unit_price} a {$currentPrice}.";
}
            if ($taxChanged) $warnings[] = "{$product->name}: impuesto cambió de {$snapshot->estimated_tax_rate}% a {$product->tax_rate}%.";
            if ($stockInsufficient) { $warnings[] = "{$product->name}: stock insuficiente."; $canCheckout = false; }
            $path = $this->safeImage($product->image);
            return ['product_id' => $product->id, 'name' => $product->name, 'code' => $product->internal_code, 'barcode' => $product->barcode, 'quantity' => $snapshot->quantity, 'price' => $currentPrice, 'previous_price' => $snapshot->estimated_unit_price, 'tax_rate' => $product->tax_rate ?? 0, 'previous_tax_rate' => $snapshot->estimated_tax_rate, 'stock' => $stock, 'track_inventory' => (bool) $product->track_inventory, 'allows_decimals' => (bool) $product->unit?->allows_decimals, 'unit' => $product->unit?->abbreviation, 'image_url' => $path ? asset('storage/'.$path) : null, 'unavailable' => false, 'price_changed' => $priceChanged, 'tax_changed' => $taxChanged, 'stock_insufficient' => $stockInsufficient];
        })->values();
        return ['suspended_sale_id' => $sale->id, 'suspension_number' => $sale->suspension_number, 'recovery_token' => $sale->recovery_token, 'customer' => $customerInvalid || !$customer ? null : [
    'id' => $customer->id,
    'name' => $customer->name,
    'identification' => $customer->identification,
    'price_level' => $customer->price_level ?? 'normal',
], 'customer_invalid' => $customerInvalid, 'items' => $items, 'warnings' => $warnings, 'can_checkout' => $canCheckout];
    }

    private function resolveSnapshot(array $data, User $user, int $companyId, int $branchId): array
    {
        $company = $this->validateContext($user, $companyId, $branchId);
        $customerId = $data['customer_id'] ?? null;
$customer = null;

if ($customerId !== null) {
    $customer = Customer::query()
        ->forCompany($companyId)
        ->where('is_active', true)
        ->whereKey($customerId)
        ->first();

    if ($customer === null) {
        throw ValidationException::withMessages([
            'customer_id' => 'El cliente no está disponible para esta empresa.',
        ]);
    }
}
        $items = $this->consolidateItems($data['items']);
        $products = Product::query()->with('unit:id,abbreviation,allows_decimals')
            ->where('company_id', $companyId)->where('is_active', true)
            ->whereIn('id', array_keys($items))->get()->keyBy('id');
        if ($products->count() !== count($items)) {
            throw ValidationException::withMessages(['items' => 'Uno o más productos no están disponibles.']);
        }
        $lines = [];
        $subtotal = $taxTotal = 0.0;
        foreach ($items as $productId => $quantity) {
            $product = $products->get($productId);
            $this->validateQuantity($product, $quantity);
            $price = match ($customer?->price_level ?? 'normal') {
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
            $taxRate = (float) ($product->tax_rate ?? 0);
            $gross = $this->decimal4($price * $quantity);
            $tax = $this->decimal4($gross * $taxRate / 100);
            $total = $this->decimal4($gross + $tax);
            $subtotal += $gross;
            $taxTotal += $tax;
            $lines[] = compact('product', 'quantity', 'price', 'taxRate', 'gross', 'tax', 'total');
        }
        $subtotal = $this->decimal4($subtotal);
        $taxTotal = $this->decimal4($taxTotal);
        $unrounded = $this->decimal4($subtotal + $taxTotal);
        $total = round($unrounded, 0, PHP_ROUND_HALF_UP);

        return ['customer_id' => $customerId, 'currency_code' => $company->currency, 'subtotal' => $subtotal,
            'tax_total' => $taxTotal, 'rounding_total' => $this->decimal4($total - $unrounded),
            'total' => $total, 'lines' => $lines];
    }

    private function storeSnapshotLines(SuspendedSale $sale, array $lines): void
    {
        foreach ($lines as $line) {
            $product = $line['product'];
            $sale->items()->create([
                'product_id' => $product->id, 'product_code' => $product->internal_code,
                'barcode' => $product->barcode, 'cabys_code' => $product->cabys_code,
                'description' => $product->name, 'unit_code' => $product->unit?->abbreviation,
                'quantity' => $line['quantity'], 'estimated_unit_price' => $line['price'],
                'estimated_gross_total' => $line['gross'], 'estimated_tax_rate' => $line['taxRate'],
                'estimated_tax_total' => $line['tax'], 'estimated_total' => $line['total'],
            ]);
        }
    }

    private function validateContext(User $user, int $companyId, int $branchId): Company
    {
        $company = Company::query()->where('is_active', true)->find($companyId);
        if (!$company || !$user->companies()->whereKey($companyId)->exists()) throw ValidationException::withMessages(['company' => 'La empresa activa ya no está autorizada.']);
        if ($company->currency !== 'CRC') throw ValidationException::withMessages(['currency' => 'Esta fase del POS solo admite empresas con moneda CRC.']);
        $branch = Branch::query()->where('company_id', $companyId)->where('is_active', true)->find($branchId);
        if (!$branch || !$user->branches()->whereKey($branchId)->exists()) throw ValidationException::withMessages(['branch' => 'La sucursal activa ya no está autorizada.']);
        return $company;
    }

    private function consolidateItems(array $items): array { $result = []; foreach ($items as $item) { $id = (int) $item['product_id']; $result[$id] = $this->decimal4(($result[$id] ?? 0) + (float) $item['quantity']); } ksort($result); return $result; }
    private function validateQuantity(Product $product, float $quantity): void { if ($quantity <= 0 || abs($quantity - $this->decimal4($quantity)) > .0000001 || (!$product->unit?->allows_decimals && floor($quantity) !== $quantity)) throw ValidationException::withMessages(['items' => "La cantidad de {$product->name} no es válida para su unidad."]); }
    private function decimal4(float $value): float { return round($value, 4, PHP_ROUND_HALF_UP); }
    private function safeImage(?string $image): ?string { $path = str_replace('\\', '/', trim((string) $image)); return $path !== '' && str_starts_with($path, 'products/') && !str_contains($path, '..') ? $path : null; }
}
