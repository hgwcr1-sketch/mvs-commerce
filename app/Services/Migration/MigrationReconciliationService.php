<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\DB;

class MigrationReconciliationService
{
    public function summary(int $companyId): array
    {
        $sales = DB::table('sales')->where('company_id', $companyId);
        $inventory = DB::table('branch_product')->join('branches', 'branches.id', '=', 'branch_product.branch_id')->where('branches.company_id', $companyId);

        return [
            'customers' => DB::table('customers')->where('company_id', $companyId)->count(),
            'products' => DB::table('products')->where('company_id', $companyId)->count(),
            'sales' => (clone $sales)->count(),
            'sales_total' => $this->decimal((clone $sales)->sum('total')),
            'last_sale_at' => (clone $sales)->max('sale_date'),
            'inventory_units' => $this->decimal((clone $inventory)->sum('branch_product.stock')),
            'inventory_movements' => DB::table('inventory_movements')->where('company_id', $companyId)->count(),
            'loyalty_balance' => $this->decimal(DB::table('loyalty_accounts')->where('company_id', $companyId)->sum('balance')),
            'loyalty_movements' => DB::table('loyalty_movements')->where('company_id', $companyId)->count(),
        ];
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 4);
    }
}
