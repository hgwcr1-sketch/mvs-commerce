<?php

namespace App\Services\Exports;

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\Product;
use App\Models\Supplier;

class DataExportService
{
    public const DATASETS = [
        'products' => ['label' => 'Productos', 'permission' => 'productos.ver', 'branch' => false],
        'customers' => ['label' => 'Clientes', 'permission' => 'clientes.ver', 'branch' => false],
        'suppliers' => ['label' => 'Proveedores', 'permission' => 'proveedores.ver', 'branch' => false],
        'inventory' => ['label' => 'Inventario', 'permission' => 'inventario.ver', 'branch' => true],
        'receivables' => ['label' => 'Cuentas por cobrar', 'permission' => 'cuentas_cobrar.ver', 'branch' => true],
        'payables' => ['label' => 'Cuentas por pagar', 'permission' => 'cuentas_pagar.ver', 'branch' => true],
        'loyalty' => ['label' => 'Fidelización', 'permission' => 'fidelidad.ver', 'branch' => false],
    ];

    public function dataset(string $dataset, int $companyId, ?int $branchId): array
    {
        return match ($dataset) {
            'products' => $this->products($companyId),
            'customers' => $this->customers($companyId),
            'suppliers' => $this->suppliers($companyId),
            'inventory' => $this->inventory($companyId, $branchId),
            'receivables' => $this->receivables($companyId, $branchId),
            'payables' => $this->payables($companyId, $branchId),
            'loyalty' => $this->loyalty($companyId),
        };
    }

    private function products(int $companyId): array
    {
        $rows = Product::query()->where('company_id', $companyId)->with(['category', 'brand', 'unit', 'barcodes'])
            ->orderBy('internal_code')->get()->map(fn (Product $product) => [
                $product->internal_code, $product->name, $product->category?->name, $product->brand?->name,
                $product->unit?->name, $product->barcode, $product->barcodes->where('is_active', true)->pluck('barcode')->join(' | '),
                $product->cabys_code, $product->product_type, $product->cost, $product->sale_price,
                $product->wholesale_price, $product->special_price, $product->price_a, $product->price_b, $product->price_c, $product->tax_rate,
                $product->minimum_stock, $product->maximum_stock, $product->is_active ? 'Sí' : 'No',
            ])->all();

        return [['Código', 'Nombre', 'Categoría', 'Marca', 'Unidad', 'Código de barras principal',
            'Códigos de barras adicionales', 'CABYS', 'Tipo', 'Costo', 'Precio de venta', 'Precio mayorista',
            'Precio especial', 'Precio A', 'Precio B', 'Precio C', 'Impuesto %', 'Stock mínimo', 'Stock máximo', 'Activo'], $rows];
    }

    private function customers(int $companyId): array
    {
        $rows = Customer::withTrashed()->where('company_id', $companyId)->orderBy('name')->get()->map(fn (Customer $customer) => [
            $customer->identification_type, $customer->identification, $customer->name, $customer->commercial_name,
            $customer->phone, $customer->mobile, $customer->email, $customer->address,
            $customer->credit_limit, $customer->credit_days, $customer->price_level,
            $customer->birth_date?->format('Y-m-d'), $customer->is_active && $customer->deleted_at === null ? 'Sí' : 'No',
        ])->all();

        return [['Tipo identificación', 'Identificación', 'Nombre', 'Nombre comercial', 'Teléfono', 'Móvil',
            'Correo', 'Dirección', 'Límite de crédito', 'Días de crédito', 'Nivel de precio', 'Fecha de nacimiento', 'Activo'], $rows];
    }

    private function suppliers(int $companyId): array
    {
        $rows = Supplier::withTrashed()->where('company_id', $companyId)->orderBy('name')->get()->map(fn (Supplier $supplier) => [
            $supplier->supplier_type, $supplier->identification_type, $supplier->identification, $supplier->name,
            $supplier->commercial_name, $supplier->contact_name, $supplier->phone, $supplier->mobile,
            $supplier->email, $supplier->address, $supplier->credit_days, $supplier->credit_limit,
            $supplier->is_active && $supplier->deleted_at === null ? 'Sí' : 'No',
        ])->all();

        return [['Tipo proveedor', 'Tipo identificación', 'Identificación', 'Nombre', 'Nombre comercial',
            'Contacto', 'Teléfono', 'Móvil', 'Correo', 'Dirección', 'Días de crédito', 'Límite de crédito', 'Activo'], $rows];
    }

    private function inventory(int $companyId, ?int $branchId): array
    {
        $rows = Product::query()->where('products.company_id', $companyId)->where('products.is_active', true)
            ->leftJoin('branch_product', fn ($join) => $join->on('branch_product.product_id', '=', 'products.id')
                ->where('branch_product.branch_id', '=', $branchId))
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->orderBy('products.internal_code')->get([
                'products.internal_code', 'products.name', 'product_categories.name as category_name',
                'units.name as unit_name', 'branch_product.stock', 'branch_product.minimum_stock', 'branch_product.maximum_stock',
            ])->map(fn ($row) => [$row->internal_code, $row->name, $row->category_name, $row->unit_name,
                $row->stock ?? 0, $row->minimum_stock ?? 0, $row->maximum_stock ?? 0])->all();

        return [['Código', 'Producto', 'Categoría', 'Unidad', 'Stock', 'Stock mínimo', 'Stock máximo'], $rows];
    }

    private function receivables(int $companyId, ?int $branchId): array
    {
        $rows = AccountReceivable::query()->forCompany($companyId)->forBranch($branchId)->with(['customer', 'sale', 'branch'])
            ->orderBy('due_date')->get()->map(fn (AccountReceivable $account) => [
                $account->sale?->sale_number, $account->customer?->identification, $account->customer?->name,
                $account->branch?->name, $account->issued_at?->format('Y-m-d'), $account->due_date?->format('Y-m-d'),
                $account->original_amount, $account->balance_due, $account->currency_code, $account->effective_status,
            ])->all();

        return [['Venta', 'Identificación cliente', 'Cliente', 'Sucursal', 'Fecha emisión', 'Fecha vencimiento',
            'Monto original', 'Saldo', 'Moneda', 'Estado'], $rows];
    }

    private function payables(int $companyId, ?int $branchId): array
    {
        $rows = AccountPayable::query()->forCompany($companyId)->forBranch($branchId)->with(['supplier', 'purchase', 'branch'])
            ->orderBy('due_date')->get()->map(fn (AccountPayable $account) => [
                $account->purchase?->number, $account->supplier?->identification, $account->supplier?->name,
                $account->branch?->name, $account->issue_date?->format('Y-m-d'), $account->due_date?->format('Y-m-d'),
                $account->original_amount, $account->paid_amount, $account->balance_due, $account->currency_code,
                $account->effective_status,
            ])->all();

        return [['Compra', 'Identificación proveedor', 'Proveedor', 'Sucursal', 'Fecha emisión', 'Fecha vencimiento',
            'Monto original', 'Abonado', 'Saldo', 'Moneda', 'Estado'], $rows];
    }

    private function loyalty(int $companyId): array
    {
        $rows = LoyaltyAccount::query()->where('company_id', $companyId)->with('customer')->orderBy('customer_id')->get()
            ->map(fn (LoyaltyAccount $account) => [
                $account->customer?->identification, $account->customer?->name, $account->balance,
                $account->total_earned, $account->total_redeemed, $account->total_expired,
                $account->last_activity_at?->format('Y-m-d H:i:s'), $account->is_active ? 'Sí' : 'No',
            ])->all();

        return [['Identificación cliente', 'Cliente', 'Saldo puntos', 'Total ganado', 'Total canjeado',
            'Total vencido', 'Última actividad', 'Activo'], $rows];
    }
}
