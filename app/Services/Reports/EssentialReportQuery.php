<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class EssentialReportQuery
{
    public const CATEGORIES = [
        'sales' => ['label' => 'Ventas', 'permission' => 'ventas.ver', 'description' => 'Ventas, productos, pagos, descuentos, devoluciones y margen.'],
        'inventory' => ['label' => 'Inventario', 'permission' => 'inventario.ver', 'description' => 'Stock, valor, mínimos, Kardex, inactividad y rotación disponible.'],
        'finance' => ['label' => 'Caja / Finanzas', 'permission' => 'caja.ver', 'description' => 'Ingresos, egresos, sesiones, diferencias, CxC y CxP.'],
        'purchases' => ['label' => 'Compras / Proveedores', 'permission' => 'compras.ver', 'description' => 'Compras, proveedores, productos, costos y saldos.'],
        'customers' => ['label' => 'Clientes', 'permission' => 'clientes.ver', 'description' => 'Ventas, frecuencia, ticket promedio, inactividad y deuda.'],
        'loyalty' => ['label' => 'Fidelización', 'permission' => 'fidelidad.ver', 'description' => 'Puntos ganados, canjeados, ajustes, saldos y actividad.'],
    ];

    public function run(string $category, int $companyId, array $filters): array
    {
        return match ($category) {
            'sales' => $this->sales($companyId, $filters),
            'inventory' => $this->inventory($companyId, $filters),
            'finance' => $this->finance($companyId, $filters),
            'purchases' => $this->purchases($companyId, $filters),
            'customers' => $this->customers($companyId, $filters),
            'loyalty' => $this->loyalty($companyId, $filters),
        };
    }

    private function sales(int $companyId, array $filters): array
    {
        $sales = $this->salesBase($companyId, $filters);
        $totals = (clone $sales)->selectRaw('COUNT(*) sales_count, COALESCE(SUM(total),0) total, COALESCE(SUM(discount_total),0) discounts')->first();
        $profit = $this->saleItemsBase($companyId, $filters)
            ->selectRaw('COALESCE(SUM(sale_items.subtotal - (sale_items.quantity * sale_items.unit_cost)),0) profit')->value('profit');
        $returns = $this->dated(DB::table('sale_returns')->where('sale_returns.company_id', $companyId), 'sale_returns.returned_at', $filters)
            ->when($filters['branch_id'], fn ($q, $id) => $q->where('sale_returns.branch_id', $id))->count();
        $voids = $this->dated(DB::table('sales')->where('company_id', $companyId)->where('status', 'voided'), 'voided_at', $filters)
            ->when($filters['branch_id'], fn ($q, $id) => $q->where('branch_id', $id))->count();

        return [
            'metrics' => [
                ['label' => 'Ventas', 'value' => (int) $totals->sales_count],
                ['label' => 'Total vendido', 'value' => $this->money($totals->total)],
                ['label' => 'Descuentos', 'value' => $this->money($totals->discounts)],
                ['label' => 'Utilidad estimada', 'value' => $this->money($profit)],
                ['label' => 'Margen', 'value' => (float) $totals->total > 0 ? number_format(((float) $profit / (float) $totals->total) * 100, 2).'%' : '0.00%'],
                ['label' => 'Devoluciones / anulaciones', 'value' => $returns.' / '.$voids],
            ],
            'sections' => [
                $this->section('Ventas por día', ['Fecha', 'Ventas', 'Total'], (clone $sales)->selectRaw('DATE(completed_at) label, COUNT(*) count, SUM(total) total')->groupByRaw('DATE(completed_at)')->orderBy('label')->get()->map(fn ($r) => [$r->label, $r->count, $this->money($r->total)])),
                $this->section('Por producto', ['Producto', 'Cantidad', 'Total', 'Utilidad'], $this->saleItemsBase($companyId, $filters)->selectRaw('sale_items.description label, SUM(sale_items.quantity) quantity, SUM(sale_items.total) total, SUM(sale_items.subtotal-(sale_items.quantity*sale_items.unit_cost)) profit')->groupBy('sale_items.description')->orderByDesc('total')->limit(25)->get()->map(fn ($r) => [$r->label, $r->quantity, $this->money($r->total), $this->money($r->profit)])),
                $this->section('Por categoría', ['Categoría', 'Total'], $this->saleItemsBase($companyId, $filters)->leftJoin('products', 'products.id', '=', 'sale_items.product_id')->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')->selectRaw("COALESCE(product_categories.name, 'Sin categoría') label, SUM(sale_items.total) total")->groupBy('product_categories.name')->orderByDesc('total')->get()->map(fn ($r) => [$r->label, $this->money($r->total)])),
                $this->section('Por sucursal', ['Sucursal', 'Ventas', 'Total'], (clone $sales)->join('branches', 'branches.id', '=', 'sales.branch_id')->selectRaw('branches.name label, COUNT(*) count, SUM(sales.total) total')->groupBy('branches.name')->get()->map(fn ($r) => [$r->label, $r->count, $this->money($r->total)])),
                $this->section('Por forma de pago', ['Forma de pago', 'Total'], DB::table('sale_payments')->join('sales', 'sales.id', '=', 'sale_payments.sale_id')->join('payment_methods', 'payment_methods.id', '=', 'sale_payments.payment_method_id')->where('sales.company_id', $companyId)->where('sales.status', 'completed')->where('sale_payments.status', 'completed')->tap(fn ($q) => $this->applySalesFilters($q, $filters))->selectRaw('payment_methods.name label, SUM(sale_payments.amount) total')->groupBy('payment_methods.name')->get()->map(fn ($r) => [$r->label, $this->money($r->total)])),
            ],
            'export_dataset' => null,
        ];
    }

    private function inventory(int $companyId, array $filters): array
    {
        $branchId = $filters['branch_id'];
        $stock = DB::table('products')->leftJoin('branch_product', fn ($join) => $join->on('branch_product.product_id', '=', 'products.id')->where('branch_product.branch_id', $branchId))
            ->where('products.company_id', $companyId)->where('products.is_active', true)
            ->when($filters['product_id'], fn ($q, $id) => $q->where('products.id', $id));
        $summary = (clone $stock)->selectRaw('COUNT(*) products, COALESCE(SUM(COALESCE(branch_product.stock,0)),0) units, COALESCE(SUM(COALESCE(branch_product.stock,0)*products.cost),0) valuation, SUM(CASE WHEN COALESCE(branch_product.stock,0)<=COALESCE(branch_product.minimum_stock,products.minimum_stock,0) THEN 1 ELSE 0 END) low_stock')->first();
        $movements = $this->dated(DB::table('inventory_movements')->where('inventory_movements.company_id', $companyId), 'inventory_movements.created_at', $filters)
            ->when($branchId, fn ($q, $id) => $q->where('inventory_movements.branch_id', $id))->when($filters['product_id'], fn ($q, $id) => $q->where('inventory_movements.product_id', $id));
        $without = (clone $stock)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inventory_movements')->whereColumn('inventory_movements.product_id', 'products.id')->where('inventory_movements.branch_id', $branchId))->count();

        return [
            'metrics' => [
                ['label' => 'Productos', 'value' => (int) $summary->products], ['label' => 'Unidades', 'value' => $summary->units],
                ['label' => 'Valorización a costo', 'value' => $this->money($summary->valuation)], ['label' => 'En mínimo', 'value' => (int) $summary->low_stock],
                ['label' => 'Sin movimiento', 'value' => $without], ['label' => 'Movimientos del período', 'value' => (clone $movements)->count()],
            ],
            'sections' => [
                $this->section('Stock y valorización por producto', ['Código', 'Producto', 'Stock', 'Mínimo', 'Valor costo'], (clone $stock)->orderBy('products.name')->limit(100)->get(['products.internal_code', 'products.name', 'products.cost', 'products.minimum_stock as product_minimum', 'branch_product.stock', 'branch_product.minimum_stock'])->map(fn ($r) => [$r->internal_code, $r->name, $r->stock ?? 0, $r->minimum_stock ?? $r->product_minimum ?? 0, $this->money(($r->stock ?? 0) * $r->cost)])),
                $this->section('Movimientos / Kardex', ['Fecha', 'Tipo', 'Producto', 'Cantidad', 'Anterior', 'Nuevo'], (clone $movements)->join('products', 'products.id', '=', 'inventory_movements.product_id')->orderByDesc('inventory_movements.created_at')->limit(100)->get(['inventory_movements.created_at', 'inventory_movements.type', 'products.name', 'inventory_movements.quantity', 'inventory_movements.previous_stock', 'inventory_movements.new_stock'])->map(fn ($r) => [$r->created_at, $r->type, $r->name, $r->quantity, $r->previous_stock, $r->new_stock])),
                $this->section('Rotación disponible', ['Producto', 'Cantidad vendida'], $this->saleItemsBase($companyId, $filters)->selectRaw('sale_items.description label, SUM(sale_items.quantity) quantity')->groupBy('sale_items.description')->orderByDesc('quantity')->limit(25)->get()->map(fn ($r) => [$r->label, $r->quantity])),
            ],
            'export_dataset' => 'inventory',
        ];
    }

    private function finance(int $companyId, array $filters): array
    {
        $cash = $this->dated(DB::table('cash_movements')->where('company_id', $companyId), 'occurred_at', $filters)->when($filters['branch_id'], fn ($q, $id) => $q->where('branch_id', $id));
        $sessions = $this->dated(DB::table('cash_sessions')->where('company_id', $companyId), 'opened_at', $filters)->when($filters['branch_id'], fn ($q, $id) => $q->where('branch_id', $id));
        $receivable = DB::table('accounts_receivable')->where('company_id', $companyId)->when($filters['branch_id'], fn ($q, $id) => $q->where('branch_id', $id));
        $payable = DB::table('accounts_payable')->where('company_id', $companyId)->when($filters['branch_id'], fn ($q, $id) => $q->where('branch_id', $id));

        return [
            'metrics' => [
                ['label' => 'Ingresos', 'value' => $this->money((clone $cash)->where('direction', 'in')->sum('amount'))],
                ['label' => 'Egresos', 'value' => $this->money((clone $cash)->where('direction', 'out')->sum('amount'))],
                ['label' => 'Sesiones', 'value' => (clone $sessions)->count()],
                ['label' => 'Diferencias', 'value' => $this->money((clone $sessions)->sum('difference_amount'))],
                ['label' => 'Saldo CxC', 'value' => $filters['can_view_receivables'] ? $this->money((clone $receivable)->sum('balance_due')) : 'Sin permiso'],
                ['label' => 'Saldo CxP', 'value' => $filters['can_view_payables'] ? $this->money((clone $payable)->sum('balance_due')) : 'Sin permiso'],
            ],
            'sections' => [
                $this->section('Movimientos de caja', ['Fecha', 'Dirección', 'Tipo', 'Concepto', 'Monto'], (clone $cash)->orderByDesc('occurred_at')->limit(100)->get()->map(fn ($r) => [$r->occurred_at, $r->direction, $r->type, $r->concept, $this->money($r->amount)])),
                $this->section('Aperturas / cierres y diferencias', ['Sesión', 'Estado', 'Apertura', 'Cierre', 'Esperado', 'Contado', 'Diferencia'], (clone $sessions)->orderByDesc('opened_at')->limit(100)->get()->map(fn ($r) => [$r->session_number, $r->status, $r->opened_at, $r->closed_at, $this->money($r->expected_cash), $this->money($r->counted_cash), $this->money($r->difference_amount)])),
                $this->section('Formas de pago', ['Forma de pago', 'Total'], DB::table('sale_payments')->join('sales', 'sales.id', '=', 'sale_payments.sale_id')->join('payment_methods', 'payment_methods.id', '=', 'sale_payments.payment_method_id')->where('sales.company_id', $companyId)->where('sales.status', 'completed')->tap(fn ($q) => $this->applySalesFilters($q, $filters))->selectRaw('payment_methods.name label, SUM(sale_payments.amount) total')->groupBy('payment_methods.name')->get()->map(fn ($r) => [$r->label, $this->money($r->total)])),
            ],
            'export_dataset' => 'receivables',
            'secondary_export_dataset' => 'payables',
        ];
    }

    private function purchases(int $companyId, array $filters): array
    {
        $purchases = $this->dated(DB::table('purchases')->where('purchases.company_id', $companyId)->where('purchases.status', 'posted'), 'purchases.purchase_date', $filters)
            ->when($filters['branch_id'], fn ($q, $id) => $q->where('purchases.branch_id', $id))->when($filters['supplier_id'], fn ($q, $id) => $q->where('purchases.supplier_id', $id))->when($filters['user_id'], fn ($q, $id) => $q->where('purchases.user_id', $id));
        $totals = (clone $purchases)->selectRaw('COUNT(*) count, COALESCE(SUM(total),0) total, COALESCE(SUM(discount),0) discounts')->first();

        return [
            'metrics' => [['label' => 'Compras', 'value' => $totals->count], ['label' => 'Total comprado', 'value' => $this->money($totals->total)], ['label' => 'Descuentos', 'value' => $this->money($totals->discounts)], ['label' => 'Saldo CxP', 'value' => $filters['can_view_payables'] ? $this->money(DB::table('accounts_payable')->where('company_id', $companyId)->when($filters['branch_id'], fn ($q, $id) => $q->where('branch_id', $id))->sum('balance_due')) : 'Sin permiso']],
            'sections' => [
                $this->section('Compras por proveedor', ['Proveedor', 'Compras', 'Total'], (clone $purchases)->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')->selectRaw('suppliers.name label, COUNT(*) count, SUM(purchases.total) total')->groupBy('suppliers.name')->orderByDesc('total')->get()->map(fn ($r) => [$r->label, $r->count, $this->money($r->total)])),
                $this->section('Productos comprados y costos', ['Producto', 'Cantidad', 'Costo promedio', 'Total'], DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->join('products', 'products.id', '=', 'purchase_items.product_id')->where('purchases.company_id', $companyId)->where('purchases.status', 'posted')->tap(fn ($q) => $this->applyPurchaseFilters($q, $filters))->selectRaw('products.name label, SUM(purchase_items.quantity) quantity, AVG(purchase_items.unit_cost) average_cost, SUM(purchase_items.total) total')->groupBy('products.name')->orderByDesc('total')->limit(50)->get()->map(fn ($r) => [$r->label, $r->quantity, $this->money($r->average_cost), $this->money($r->total)])),
            ],
            'export_dataset' => 'suppliers', 'secondary_export_dataset' => 'payables',
        ];
    }

    private function customers(int $companyId, array $filters): array
    {
        $sales = $this->salesBase($companyId, $filters)->whereNotNull('sales.customer_id');
        $activeCustomers = (clone $sales)->distinct()->count('sales.customer_id');
        $customerRows = (clone $sales)->join('customers', 'customers.id', '=', 'sales.customer_id')->selectRaw('customers.name, COUNT(*) frequency, SUM(sales.total) total, AVG(sales.total) average_ticket, MAX(sales.completed_at) last_sale')->groupBy('customers.id', 'customers.name')->orderByDesc('total')->limit(100)->get();
        $withoutMovement = DB::table('customers')->where('company_id', $companyId)->where('is_active', true)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sales')->whereColumn('sales.customer_id', 'customers.id')->where('sales.status', 'completed'))->count();

        return [
            'metrics' => [['label' => 'Clientes con ventas', 'value' => $activeCustomers], ['label' => 'Sin movimiento', 'value' => $withoutMovement], ['label' => 'Ventas identificadas', 'value' => $this->money((clone $sales)->sum('total'))], ['label' => 'Deuda total', 'value' => $filters['can_view_receivables'] ? $this->money(DB::table('accounts_receivable')->where('company_id', $companyId)->when($filters['branch_id'], fn ($q, $id) => $q->where('branch_id', $id))->sum('balance_due')) : 'Sin permiso']],
            'sections' => [$this->section('Actividad por cliente', ['Cliente', 'Frecuencia', 'Ventas', 'Ticket promedio', 'Última venta'], $customerRows->map(fn ($r) => [$r->name, $r->frequency, $this->money($r->total), $this->money($r->average_ticket), $r->last_sale]))],
            'export_dataset' => 'customers', 'secondary_export_dataset' => 'receivables',
        ];
    }

    private function loyalty(int $companyId, array $filters): array
    {
        $movements = $this->dated(DB::table('loyalty_movements')->where('loyalty_movements.company_id', $companyId), 'loyalty_movements.effective_at', $filters)
            ->when($filters['branch_id'], fn ($q, $id) => $q->where('loyalty_movements.branch_id', $id))->when($filters['customer_id'], fn ($q, $id) => $q->where('loyalty_movements.customer_id', $id))->when($filters['user_id'], fn ($q, $id) => $q->where('loyalty_movements.user_id', $id));
        $byType = (clone $movements)->selectRaw('type, COUNT(*) count, COALESCE(SUM(points),0) points')->groupBy('type')->get();

        return [
            'metrics' => [
                ['label' => 'Puntos ganados', 'value' => $this->points((clone $movements)->whereIn('type', ['earn', 'bonus'])->sum('points'))],
                ['label' => 'Puntos canjeados', 'value' => $this->points(abs((float) (clone $movements)->where('type', 'redemption')->sum('points')))],
                ['label' => 'Ajustes', 'value' => $this->points((clone $movements)->where('type', 'adjustment')->sum('points'))],
                ['label' => 'Saldo global', 'value' => $this->points(DB::table('loyalty_accounts')->where('company_id', $companyId)->sum('balance'))],
            ],
            'sections' => [
                $this->section('Movimientos por tipo', ['Tipo', 'Movimientos', 'Puntos'], $byType->map(fn ($r) => [$r->type, $r->count, $this->points($r->points)])),
                $this->section('Actividad por sucursal', ['Sucursal', 'Movimientos', 'Puntos'], (clone $movements)->leftJoin('branches', 'branches.id', '=', 'loyalty_movements.branch_id')->selectRaw("COALESCE(branches.name, 'Sin sucursal') label, COUNT(*) count, SUM(loyalty_movements.points) points")->groupBy('branches.name')->get()->map(fn ($r) => [$r->label, $r->count, $this->points($r->points)])),
            ],
            'export_dataset' => 'loyalty',
        ];
    }

    private function salesBase(int $companyId, array $filters): Builder
    {
        return $this->applySalesFilters(DB::table('sales')->where('sales.company_id', $companyId)->where('sales.status', 'completed'), $filters);
    }

    private function saleItemsBase(int $companyId, array $filters): Builder
    {
        return DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sales.company_id', $companyId)->where('sales.status', 'completed')->tap(fn ($q) => $this->applySalesFilters($q, $filters))->when($filters['product_id'], fn ($q, $id) => $q->where('sale_items.product_id', $id));
    }

    private function applySalesFilters(Builder $query, array $filters): Builder
    {
        return $this->dated($query, 'sales.completed_at', $filters)
            ->when($filters['branch_id'], fn ($q, $id) => $q->where('sales.branch_id', $id))
            ->when($filters['customer_id'], fn ($q, $id) => $q->where('sales.customer_id', $id))
            ->when($filters['user_id'], fn ($q, $id) => $q->where('sales.user_id', $id))
            ->when($filters['product_id'] && ! str_contains(implode(',', $query->joins ? array_map(fn ($join) => $join->table, $query->joins) : []), 'sale_items'), fn ($q) => $q->whereExists(fn ($items) => $items->selectRaw('1')->from('sale_items')->whereColumn('sale_items.sale_id', 'sales.id')->where('sale_items.product_id', $filters['product_id'])));
    }

    private function applyPurchaseFilters(Builder $query, array $filters): Builder
    {
        return $this->dated($query, 'purchases.purchase_date', $filters)->when($filters['branch_id'], fn ($q, $id) => $q->where('purchases.branch_id', $id))->when($filters['supplier_id'], fn ($q, $id) => $q->where('purchases.supplier_id', $id))->when($filters['user_id'], fn ($q, $id) => $q->where('purchases.user_id', $id))->when($filters['product_id'], fn ($q, $id) => $q->where('purchase_items.product_id', $id));
    }

    private function dated(Builder $query, string $column, array $filters): Builder
    {
        return $query->when($filters['from'], fn ($q, $date) => $q->whereDate($column, '>=', $date))->when($filters['to'], fn ($q, $date) => $q->whereDate($column, '<=', $date));
    }

    private function section(string $title, array $headers, $rows): array
    {
        return ['title' => $title, 'headers' => $headers, 'rows' => $rows->all()];
    }

    private function money(mixed $value): string
    {
        return '₡'.number_format((float) ($value ?? 0), 2, '.', ',');
    }

    private function points(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', ',');
    }
}
