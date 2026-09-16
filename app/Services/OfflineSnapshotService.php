<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\OfflineSnapshot;
use App\Models\OfflineTerminal;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Brand;
use App\Models\Unit;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OfflineSnapshotService
{
    public function buildSnapshot(
        Company $company,
        Branch $branch,
        OfflineTerminal $terminal,
        User $user
    ): array {
        $now = now();

        $companyData = [
            'id' => $company->id,
            'trade_name' => $company->trade_name,
            'currency' => $company->currency,
            'timezone' => $company->timezone,
        ];

        $branchData = [
            'id' => $branch->id,
            'name' => $branch->name,
            'code' => $branch->code,
            'receipt_format' => $branch->receipt_format,
        ];

        $terminalData = [
            'terminal_uuid' => $terminal->terminal_uuid,
            'name' => $terminal->name,
        ];

        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];

        $categories = ProductCategory::where('company_id', $company->id)
            ->where('is_active', true)
            ->select('id', 'name', 'code')
            ->get();

        $brands = Brand::where('company_id', $company->id)
            ->where('is_active', true)
            ->select('id', 'name')
            ->get();

        $units = Unit::where('company_id', $company->id)
            ->where('is_active', true)
            ->select('id', 'name', 'code')
            ->get();

        $branchProductStock = DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->pluck('stock', 'product_id');

        $products = Product::where('company_id', $company->id)
            ->where('is_active', true)
            ->select([
                'id', 'category_id', 'brand_id', 'unit_id',
                'name', 'internal_code', 'barcode',
                'sale_price', 'cost', 'tax_rate',
                'track_inventory', 'allow_negative_stock',
            ])
            ->get()
            ->map(function ($product) use ($branchProductStock) {
                $data = [
                    'id' => $product->id,
                    'category_id' => $product->category_id,
                    'brand_id' => $product->brand_id,
                    'unit_id' => $product->unit_id,
                    'name' => $product->name,
                    'internal_code' => $product->internal_code,
                    'barcode' => $product->barcode,
                    'sale_price' => $product->sale_price,
                    'cost' => $product->cost,
                    'tax_rate' => $product->tax_rate,
                    'track_inventory' => $product->track_inventory,
                    'allow_negative_stock' => $product->allow_negative_stock,
                    'stock' => (string) ($branchProductStock->get($product->id) ?? '0.0000'),
                ];

                $barcodes = ProductBarcode::where('product_id', $product->id)
                    ->where('is_active', true)
                    ->select('barcode', 'barcode_type', 'is_primary')
                    ->get();

                if ($barcodes->isNotEmpty()) {
                    $data['barcodes'] = $barcodes->toArray();
                }

                return $data;
            });

        $customers = Customer::where('company_id', $company->id)
            ->where('is_active', true)
            ->select([
                'id', 'customer_code', 'identification_type', 'identification',
                'name', 'phone', 'mobile', 'email', 'price_level',
            ])
            ->limit(2000)
            ->get();

        $paymentMethods = PaymentMethod::where('company_id', $company->id)
            ->where('is_active', true)
            ->select(['id', 'code', 'name', 'type', 'is_system', 'affects_cash', 'requires_reference', 'allows_change', 'sort_order'])
            ->get();

        $loyaltySetting = DB::table('loyalty_settings')
            ->where('company_id', $company->id)
            ->select('is_active', 'accumulation_percentage', 'point_value', 'redemption_minimum', 'redemption_maximum', 'expiration_enabled', 'expiration_months')
            ->first();

        $offlineAuthorization = [
            'authorized_at' => $now->toIso8601String(),
            'valid_until' => now()->addHours(config('offline.max_hours', 48))->toIso8601String(),
        ];

        $snapshotData = [
            'schema_version' => OfflineSnapshot::SCHEMA_VERSION,
            'generated_at' => $now->toIso8601String(),
            'company' => $companyData,
            'branch' => $branchData,
            'terminal' => $terminalData,
            'user' => $userData,
            'categories' => $categories->toArray(),
            'brands' => $brands->toArray(),
            'units' => $units->toArray(),
            'products' => $products->toArray(),
            'customers' => $customers->toArray(),
            'payment_methods' => $paymentMethods->toArray(),
            'loyalty_settings' => $loyaltySetting,
            'authorization' => $offlineAuthorization,
        ];

        return $snapshotData;
    }

    public function saveSnapshot(
        Company $company,
        Branch $branch,
        OfflineTerminal $terminal,
        array $snapshotData
    ): OfflineSnapshot {
        $json = json_encode($snapshotData, JSON_THROW_ON_ERROR);

        return OfflineSnapshot::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'terminal_uuid' => $terminal->terminal_uuid,
            'schema_version' => $snapshotData['schema_version'],
            'generated_at' => $snapshotData['generated_at'],
            'snapshot_data' => $snapshotData,
            'snapshot_size_bytes' => strlen($json),
            'product_count' => count($snapshotData['products']),
            'customer_count' => count($snapshotData['customers']),
        ]);
    }

    public function getLatestSnapshot(
        Company $company,
        Branch $branch,
        OfflineTerminal $terminal
    ): ?OfflineSnapshot {
        return OfflineSnapshot::where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('terminal_uuid', $terminal->terminal_uuid)
            ->latest('generated_at')
            ->first();
    }

    public function getSnapshotForAuthorization(
        Company $company,
        Branch $branch,
        OfflineTerminal $terminal,
        User $user
    ): OfflineSnapshot {
        $snapshotData = $this->buildSnapshot($company, $branch, $terminal, $user);

        return $this->saveSnapshot($company, $branch, $terminal, $snapshotData);
    }
}
