<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Customer;
use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Centro de Control: reposicion sugerida (Paso 32).
 *
 * Solo lectura + recomendacion explicada. Nunca crea traslados ni compras:
 * el usuario revisa, confirma y ejecuta el flujo existente.
 */
class ControlCenterService
{
    public const SCALE = 4;

    private const MAX_PRODUCTS = 200;

    private const COST_SOURCE_SUPPLIER = 'proveedor';

    private const COST_SOURCE_PRODUCT = 'producto';

    private PhoneNumberService $phones;

    public function __construct(PhoneNumberService $phones)
    {
        $this->phones = $phones;
    }

    /**
     * Analisis de reposicion para la empresa y sucursal activas.
     *
     * Cuando branchId es null (vista empresarial), analiza TODAS las sucursales.
     */
    public function forCompany(Company $company, ?int $branchId): array
    {
        $branch = $branchId
            ? Branch::query()->where('company_id', $company->id)->find($branchId)
            : null;

        // Vista empresarial: analizar todas las sucursales cuando no hay branch seleccionada
        if (! $branch) {
            return $this->forCompanyGlobal($company);
        }

        $shortages = $this->shortages($company->id, $branch->id);

        if ($shortages->isEmpty()) {
            return $this->emptyResult($company, $branch);
        }

        $surpluses = $this->surpluses($company->id, $branch->id, $shortages->keys()->all());
        $inTransit = $this->inTransitQuantities($company->id, $branch->id, $shortages->keys()->all());
        $relations = $this->supplierRelations($company->id, $shortages->keys()->all());
        $countries = $this->countryPhoneCodes($relations->pluck('country_id')->filter()->unique()->all());

        $transferSuggestions = [];
        $replenishment = [];

        foreach ($shortages as $productId => $row) {
            $deficit = bcsub((string) $row->minimum_stock, (string) $row->stock, self::SCALE);
            $pending = $inTransit[$productId] ?? '0';
            $net = bcsub($deficit, $pending, self::SCALE);
            if (bccomp($net, '0', self::SCALE) <= 0) {
                continue;
            }

            $origin = $surpluses->get($productId);
            $line = [
                'product_id' => (int) $productId,
                'product_name' => (string) $row->name,
                'internal_code' => $row->internal_code,
                'allows_decimals' => (bool) $row->allows_decimals,
                'stock' => bcadd((string) $row->stock, '0', self::SCALE),
                'minimum_stock' => bcadd((string) $row->minimum_stock, '0', self::SCALE),
                'maximum_stock' => $row->maximum_stock !== null ? bcadd((string) $row->maximum_stock, '0', self::SCALE) : null,
                'deficit' => $net,
                'in_transit_quantity' => $pending,
                'suggested_quantity' => $this->suggestedQuantity($net, $row->maximum_stock, $row->stock),
                'reason' => 'Existencias '.$row->stock.' por debajo del minimo '.$row->minimum_stock.'.',
            ];

            $suggestion = $this->transferSuggestion($company, $branch, $line, $origin);
            $coveredByTransfer = false;

            if ($suggestion) {
                $transferSuggestions[] = $suggestion;
                $line['origin_branch_name'] = $suggestion['from_branch_name'];
                $line['origin_available_quantity'] = $suggestion['available_quantity'];
                $line['suggested_quantity'] = $suggestion['suggested_quantity'];

                if (bccomp((string) $suggestion['suggested_quantity'], $net, self::SCALE) >= 0) {
                    $coveredByTransfer = true;
                }
            }

            $line = $this->withSupplier($line, $relations->get($productId), $countries);
            $line['covered_by_transfer'] = $coveredByTransfer;

            if (! $coveredByTransfer) {
                $replenishment[] = $line;
            }
        }

        $groups = $this->purchaseGroups($replenishment);

        $totalCovered = count($transferSuggestions);

        $customerData = $this->customerPortalData($company);

        return [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'generated_at' => now()->format('d/m/Y H:i'),
            'summary' => [
                'shortage_count' => $totalCovered + count($replenishment),
                'transfer_count' => $totalCovered,
                'purchase_count' => count($replenishment),
                'group_count' => count($groups),
                'without_supplier' => count(array_filter($replenishment, fn ($line) => $line['supplier_id'] === null)),
                'new_customer_count' => $customerData['summary']['new_count'],
                'no_portal_count' => $customerData['summary']['no_portal_count'],
                'portal_no_access_count' => $customerData['summary']['portal_no_access_count'],
            ],
            'transfer_suggestions' => $transferSuggestions,
            'replenishment' => $replenishment,
            'purchase_groups' => $groups,
            'follow_up' => $this->followUp($company, $branch),
            'customers' => $customerData['customers'],
            'customer_summary' => $customerData['summary'],
        ];
    }

    /**
     * Vista empresarial: analiza TODAS las sucursales sin requerir una branch especifica.
     * Usado cuando el usuario tiene dashboard.admin y no ha seleccionado branch.
     */
    private function forCompanyGlobal(Company $company): array
    {
        $shortages = $this->shortagesGlobal($company->id);

        if ($shortages->isEmpty()) {
            return $this->emptyResult($company, null);
        }

        $productIds = array_unique(array_map(fn ($row) => (int) $row->product_id, $shortages->all()));
        $relations = $this->supplierRelations($company->id, $productIds);
        $countries = $this->countryPhoneCodes($relations->pluck('country_id')->filter()->unique()->all());

        $transferSuggestions = [];
        $replenishment = [];
        $processedProducts = []; // Deduplicar productos en vista global

        foreach ($shortages as $key => $row) {
            $shortageBranchId = (int) $row->branch_id;
            $productId = (int) $row->product_id;

            $deficit = bcsub((string) $row->minimum_stock, (string) $row->stock, self::SCALE);
            $pendingArr = $this->inTransitQuantities($company->id, $shortageBranchId, [$productId]);
            $pending = $pendingArr[$productId] ?? '0';
            $net = bcsub($deficit, $pending, self::SCALE);
            if (bccomp($net, '0', self::SCALE) <= 0) {
                continue;
            }

            // En vista global, omitir traslados que ya cubrieron este producto
            if (isset($processedProducts[$productId])) {
                continue;
            }

            $surplusOrigin = $this->surpluses($company->id, $shortageBranchId, [$productId])->get($productId);
            $shortageBranch = Branch::query()->where('company_id', $company->id)->find($shortageBranchId);

            $line = [
                'product_id' => $productId,
                'product_name' => (string) $row->name,
                'internal_code' => $row->internal_code,
                'allows_decimals' => (bool) $row->allows_decimals,
                'stock' => bcadd((string) $row->stock, '0', self::SCALE),
                'minimum_stock' => bcadd((string) $row->minimum_stock, '0', self::SCALE),
                'maximum_stock' => $row->maximum_stock !== null ? bcadd((string) $row->maximum_stock, '0', self::SCALE) : null,
                'deficit' => $net,
                'in_transit_quantity' => $pending,
                'suggested_quantity' => $this->suggestedQuantity($net, $row->maximum_stock, $row->stock),
                'reason' => 'Existencias '.$row->stock.' por debajo del minimo '.$row->minimum_stock.' en '.($shortageBranch?->name ?? 'sucursal '.$shortageBranchId).'.',
            ];

            $suggestion = $this->transferSuggestion($company, $shortageBranch, $line, $surplusOrigin);
            $coveredByTransfer = false;

            if ($suggestion) {
                $transferSuggestions[] = $suggestion;
                $line['origin_branch_name'] = $suggestion['from_branch_name'];
                $line['origin_available_quantity'] = $suggestion['available_quantity'];
                $line['suggested_quantity'] = $suggestion['suggested_quantity'];

                if (bccomp((string) $suggestion['suggested_quantity'], $net, self::SCALE) >= 0) {
                    $coveredByTransfer = true;
                }
            }

            $line = $this->withSupplier($line, $relations->get($productId), $countries);
            $line['covered_by_transfer'] = $coveredByTransfer;

            if (! $coveredByTransfer) {
                $replenishment[] = $line;
            }

            $processedProducts[$productId] = true;
        }

        $groups = $this->purchaseGroups($replenishment);
        $totalCovered = count($transferSuggestions);
        $customerData = $this->customerPortalData($company);

        return [
            'company_id' => $company->id,
            'branch_id' => null,
            'branch_name' => 'Todas las sucursales',
            'generated_at' => now()->format('d/m/Y H:i'),
            'summary' => [
                'shortage_count' => $totalCovered + count($replenishment),
                'transfer_count' => $totalCovered,
                'purchase_count' => count($replenishment),
                'group_count' => count($groups),
                'without_supplier' => count(array_filter($replenishment, fn ($line) => $line['supplier_id'] === null)),
                'new_customer_count' => $customerData['summary']['new_count'],
                'no_portal_count' => $customerData['summary']['no_portal_count'],
                'portal_no_access_count' => $customerData['summary']['portal_no_access_count'],
            ],
            'transfer_suggestions' => $transferSuggestions,
            'replenishment' => $replenishment,
            'purchase_groups' => $groups,
            'follow_up' => [
                'pending_transfers' => [],
                'pending_count' => 0,
            ],
            'customers' => $customerData['customers'],
            'customer_summary' => $customerData['summary'],
        ];
    }

    /**
     * Productos con existencia por debajo del minimo en CUALQUIER sucursal.
     * Usado para la vista empresarial (branchId = null).
     * Clave: "{branch_id}_{product_id}" para manejar duplicados.
     */
    private function shortagesGlobal(int $companyId): Collection
    {
        return DB::table('products as p')
            ->join('branch_product as bp', function ($join) {
                $join->on('bp.product_id', '=', 'p.id');
            })
            ->join('branches as b', function ($join) use ($companyId) {
                $join->on('b.id', '=', 'bp.branch_id')
                    ->where('b.company_id', '=', $companyId)
                    ->where('b.is_active', true);
            })
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('p.company_id', $companyId)
            ->whereNull('p.deleted_at')
            ->where('p.is_active', true)
            ->where('p.track_inventory', true)
            ->whereRaw('COALESCE(bp.stock, 0) <= COALESCE(bp.minimum_stock, p.minimum_stock, 0)')
            ->orderByRaw('COALESCE(bp.stock, 0) asc')
            ->limit(self::MAX_PRODUCTS)
            ->get([
                'p.id as product_id',
                DB::raw('bp.branch_id as branch_id'),
                'b.name as branch_name',
                'p.name',
                'p.internal_code',
                'p.barcode',
                'p.cost as product_cost',
                DB::raw('COALESCE(bp.stock, 0) as stock'),
                DB::raw('COALESCE(bp.minimum_stock, p.minimum_stock, 0) as minimum_stock'),
                DB::raw('COALESCE(bp.maximum_stock, p.maximum_stock) as maximum_stock'),
                DB::raw('COALESCE(u.allows_decimals, true) as allows_decimals'),
            ])
            ->keyBy(fn ($row) => $row->branch_id.'_'.$row->product_id);
    }

    /**
     * Resultado vacio cuando no hay sucursal activa seleccionada.
     */
    private function emptyResult(Company $company, ?Branch $branch): array
    {
        $customerData = $this->customerPortalData($company);

        return [
            'company_id' => $company->id,
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name ?? 'Todas las sucursales',
            'generated_at' => now()->format('d/m/Y H:i'),
            'summary' => [
                'shortage_count' => 0,
                'transfer_count' => 0,
                'purchase_count' => 0,
                'group_count' => 0,
                'without_supplier' => 0,
                'new_customer_count' => $customerData['summary']['new_count'],
                'no_portal_count' => $customerData['summary']['no_portal_count'],
                'portal_no_access_count' => $customerData['summary']['portal_no_access_count'],
            ],
            'transfer_suggestions' => [],
            'replenishment' => [],
            'purchase_groups' => [],
            'follow_up' => [
                'pending_transfers' => [],
                'pending_count' => 0,
            ],
            'customers' => $customerData['customers'],
            'customer_summary' => $customerData['summary'],
            'empty_reason' => $branch ? null : 'Seleccione una sucursal para analizar la reposición.',
        ];
    }

    /**
     * Productos cuya existencia en la sucursal esta en o por debajo del minimo.
     */
    private function shortages(int $companyId, int $branchId): Collection
    {
        return DB::table('products as p')
            ->join('branch_product as bp', function ($join) use ($branchId) {
                $join->on('bp.product_id', '=', 'p.id')
                    ->where('bp.branch_id', '=', $branchId);
            })
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('p.company_id', $companyId)
            ->whereNull('p.deleted_at')
            ->where('p.is_active', true)
            ->where('p.track_inventory', true)
            ->whereRaw('COALESCE(bp.stock, 0) <= COALESCE(bp.minimum_stock, p.minimum_stock, 0)')
            ->orderByRaw('COALESCE(bp.stock, 0) asc')
            ->limit(self::MAX_PRODUCTS)
            ->get([
                'p.id as product_id',
                'p.name',
                'p.internal_code',
                'p.barcode',
                'p.cost as product_cost',
                DB::raw('COALESCE(bp.stock, 0) as stock'),
                DB::raw('COALESCE(bp.minimum_stock, p.minimum_stock, 0) as minimum_stock'),
                DB::raw('COALESCE(bp.maximum_stock, p.maximum_stock) as maximum_stock'),
                DB::raw('COALESCE(u.allows_decimals, true) as allows_decimals'),
            ])
            ->keyBy('product_id');
    }

    /**
     * Excedente disponible por producto en otras sucursales de la misma empresa.
     */
    private function surpluses(int $companyId, int $branchId, array $productIds): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return DB::table('branch_product as bp')
            ->join('branches as b', function ($join) use ($companyId) {
                $join->on('b.id', '=', 'bp.branch_id')
                    ->where('b.company_id', '=', $companyId);
            })
            ->join('products as p', function ($join) use ($companyId) {
                $join->on('p.id', '=', 'bp.product_id')
                    ->where('p.company_id', '=', $companyId);
            })
            ->whereIn('bp.product_id', $productIds)
            ->where('bp.branch_id', '!=', $branchId)
            ->where('b.is_active', true)
            ->whereNull('p.deleted_at')
            ->where('p.is_active', true)
            ->whereRaw('COALESCE(bp.stock, 0) > COALESCE(bp.minimum_stock, p.minimum_stock, 0)')
            ->orderByRaw('(COALESCE(bp.stock, 0) - COALESCE(bp.minimum_stock, p.minimum_stock, 0)) desc')
            ->get([
                'bp.product_id',
                'bp.branch_id',
                'b.name as branch_name',
                DB::raw('(COALESCE(bp.stock, 0) - COALESCE(bp.minimum_stock, p.minimum_stock, 0)) as available_quantity'),
            ])
            ->groupBy('product_id')
            ->map(fn ($items) => $items->first());
    }

    /**
     * Cantidad ya en transito hacia la sucursal (evita reponer de mas).
     *
     * @return array<int, string>
     */
    private function inTransitQuantities(int $companyId, int $branchId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('inventory_transfer_items as iti')
            ->join('inventory_transfers as it', 'it.id', '=', 'iti.inventory_transfer_id')
            ->where('it.company_id', $companyId)
            ->where('it.to_branch_id', $branchId)
            ->whereIn('it.status', InventoryTransfer::TRANSIT_STATUSES)
            ->whereIn('iti.product_id', $productIds)
            ->groupBy('iti.product_id')
            ->pluck(DB::raw('SUM(COALESCE(iti.quantity, 0))'), 'iti.product_id')
            ->map(fn ($quantity) => bcadd((string) $quantity, '0', self::SCALE))
            ->all();
    }

    /**
     * Relaciones proveedor por producto: prioriza el proveedor primario activo.
     */
    private function supplierRelations(int $companyId, array $productIds): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return DB::table('product_suppliers as ps')
            ->join('suppliers as s', function ($join) {
                $join->on('s.id', '=', 'ps.supplier_id')
                    ->whereNull('s.deleted_at')
                    ->where('s.is_active', true);
            })
            ->where('ps.company_id', $companyId)
            ->whereIn('ps.product_id', $productIds)
            ->where('ps.is_active', true)
            ->orderByRaw('ps.is_primary desc, ps.current_cost asc')
            ->get([
                'ps.product_id',
                'ps.supplier_id',
                'ps.supplier_product_code',
                'ps.current_cost',
                'ps.is_primary',
                's.name as supplier_name',
                's.contact_name',
                's.phone',
                's.mobile',
                's.email',
                's.country_id',
            ])
            ->groupBy('product_id')
            ->map(fn ($items) => $items->first());
    }

    /**
     * Codigos telefonicos de paises para mostrar en contactos de proveedores.
     */
    private function countryPhoneCodes(array $countryIds): Collection
    {
        if ($countryIds === []) {
            return collect();
        }

        return Country::query()
            ->whereIn('id', $countryIds)
            ->get(['id', 'phone_code'])
            ->keyBy('id');
    }

    /**
     * Cantidad sugerida: cubre el deficit y llega hasta maximum_stock si existe.
     */
    private function suggestedQuantity(string $deficit, ?string $maximumStock, string $currentStock): string
    {
        if ($maximumStock !== null && bccomp($maximumStock, '0', self::SCALE) > 0) {
            $target = bcsub($maximumStock, $currentStock, self::SCALE);
            if (bccomp($target, $deficit, self::SCALE) > 0) {
                return $target;
            }
        }

        return $deficit;
    }

    /**
     * Busca si otra sucursal tiene excedente suficiente para cubrir el deficit.
     * RECOMENDACION V1: Traslados inteligentes — prioriza mover stock entre sucursales.
     */
    private function transferSuggestion(Company $company, Branch $targetBranch, array $line, ?object $origin): ?array
    {
        if (! $origin) {
            return null;
        }

        $available = (string) $origin->available_quantity;
        $needed = (string) $line['suggested_quantity'];

        if (bccomp($available, '0', self::SCALE) <= 0) {
            return null;
        }

        $transferable = bccomp($available, $needed, self::SCALE) >= 0 ? $needed : $available;

        return [
            'from_branch_id' => (int) $origin->branch_id,
            'from_branch_name' => (string) $origin->branch_name,
            'to_branch_id' => (int) $targetBranch->id,
            'to_branch_name' => (string) $targetBranch->name,
            'product_id' => $line['product_id'],
            'product_name' => $line['product_name'],
            'available_quantity' => $available,
            'suggested_quantity' => $transferable,
            'reason' => 'La sucursal '
                . $origin->branch_name
                . ' tiene '
                . $available
                . ' unidades disponibles para traslado.',
        ];
    }

    /**
     * Anexa datos del proveedor a la linea de reposicion.
     */
    private function withSupplier(array $line, ?object $relation, Collection $countries): array
    {
        if (! $relation) {
            return array_merge($line, [
                'supplier_id' => null,
                'supplier_name' => null,
                'supplier_product_code' => null,
                'supplier_cost' => null,
                'cost_source' => null,
                'supplier_phone' => null,
                'supplier_email' => null,
                'supplier_country_phone_code' => null,
            ]);
        }

        $cost = $relation->current_cost !== null
            ? bcadd((string) $relation->current_cost, '0', self::SCALE)
            : null;

        $finalCost = $cost ?? bcadd((string) $line['product_cost'], '0', self::SCALE);

        $countryCode = $relation->country_id
            ? ($countries->get($relation->country_id)->phone_code ?? null)
            : null;

        return array_merge($line, [
            'supplier_id' => (int) $relation->supplier_id,
            'supplier_name' => (string) $relation->supplier_name,
            'supplier_product_code' => $relation->supplier_product_code,
            'supplier_cost' => $finalCost,
            'cost_source' => $cost !== null ? self::COST_SOURCE_SUPPLIER : self::COST_SOURCE_PRODUCT,
            'supplier_phone' => $relation->phone ?? $relation->mobile ?? null,
            'supplier_email' => $relation->email,
            'supplier_country_phone_code' => $countryCode,
        ]);
    }

    /**
     * RECOMENDACION V1: Agrupacion de compras por proveedor.
     * Consolida los productos de cada proveedor en un solo grupo para orden de compra.
     */
    private function purchaseGroups(array $replenishment): array
    {
        $grouped = [];

        foreach ($replenishment as $line) {
            $supplierId = $line['supplier_id'] ?? null;

            if ($supplierId === null) {
                continue;
            }

            $key = (int) $supplierId;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'supplier_id' => $key,
                    'supplier_name' => $line['supplier_name'],
                    'supplier_phone' => $line['supplier_phone'] ?? null,
                    'supplier_email' => $line['supplier_email'] ?? null,
                    'supplier_country_phone_code' => $line['supplier_country_phone_code'] ?? null,
                    'items' => [],
                    'total_estimated_cost' => '0',
                ];
            }

            $itemCost = (string) ($line['supplier_cost'] ?? '0');
            $qty = (string) $line['suggested_quantity'];
            $lineTotal = bcmul($qty, $itemCost, self::SCALE);

            $grouped[$key]['items'][] = [
                'product_id' => $line['product_id'],
                'product_name' => $line['product_name'],
                'internal_code' => $line['internal_code'],
                'supplier_product_code' => $line['supplier_product_code'] ?? null,
                'suggested_quantity' => $qty,
                'unit_cost' => $itemCost,
                'line_total' => $lineTotal,
            ];

            $grouped[$key]['total_estimated_cost'] = bcadd(
                $grouped[$key]['total_estimated_cost'],
                $lineTotal,
                self::SCALE,
            );
        }

        usort($grouped, fn ($a, $b) => bccomp($b['total_estimated_cost'], $a['total_estimated_cost'], self::SCALE));

        return array_values($grouped);
    }

    /**
     * Traslados pendientes hacia la sucursal (seguimiento).
     */
    private function followUp(Company $company, Branch $branch): array
    {
        $pending = DB::table('inventory_transfer_items as iti')
            ->join('inventory_transfers as it', 'it.id', '=', 'iti.inventory_transfer_id')
            ->join('products as p', 'p.id', '=', 'iti.product_id')
            ->where('it.company_id', $company->id)
            ->where('it.to_branch_id', $branch->id)
            ->whereIn('it.status', InventoryTransfer::TRANSIT_STATUSES)
            ->orderBy('it.created_at', 'desc')
            ->limit(20)
            ->get([
                'iti.product_id',
                'p.name as product_name',
                DB::raw('COALESCE(iti.quantity, 0) as quantity'),
                'it.transfer_number',
                'it.status',
                'it.created_at',
            ]);

        return [
            'pending_transfers' => $pending,
            'pending_count' => count($pending),
        ];
    }

    /**
     * Datos de clientes y estado del portal para el Centro de Control.
     */
    private function customerPortalData(Company $company): array
    {
        $customers = DB::table('customers as c')
            ->leftJoin('loyalty_portal_credentials as lpc', function ($join) {
                $join->on('lpc.customer_id', '=', 'c.id')
                    ->where('lpc.is_active', true);
            })
            ->where('c.company_id', $company->id)
            ->whereNull('c.deleted_at')
            ->where('c.is_active', true)
            ->orderBy('c.created_at', 'desc')
            ->limit(100)
            ->get([
                'c.id',
                'c.name',
                'c.phone',
                'c.phone_country_code',
                'c.mobile',
                'c.email',
                'c.customer_code',
                'c.created_at',
                'lpc.id as credential_id',
                'lpc.first_login_at',
                'lpc.last_login_at',
            ]);

        $result = [];
        $newCount = 0;
        $noPortalCount = 0;
        $portalNoAccessCount = 0;
        $thirtyDaysAgo = now()->subDays(30);

        foreach ($customers as $customer) {
            $status = $this->portalStatus($customer);
            $isNew = $customer->created_at && Carbon::parse($customer->created_at)->gt($thirtyDaysAgo);

            if ($isNew) {
                $newCount++;
            }
            if ($status['key'] === 'sin_portal') {
                $noPortalCount++;
            }
            if ($status['key'] === 'portal_creado') {
                $portalNoAccessCount++;
            }

            $whatsappUrl = $this->buildWhatsappUrl($customer, $company);

            $result[] = [
                'id' => (int) $customer->id,
                'name' => (string) $customer->name,
                'phone' => $customer->phone,
                'phone_country_code' => $customer->phone_country_code,
                'mobile' => $customer->mobile,
                'email' => $customer->email,
                'customer_code' => $customer->customer_code,
                'created_at' => $customer->created_at,
                'is_new' => $isNew,
                'portal_status' => $status,
                'whatsapp_url' => $whatsappUrl,
            ];
        }

        return [
            'customers' => $result,
            'summary' => [
                'total' => count($result),
                'new_count' => $newCount,
                'no_portal_count' => $noPortalCount,
                'portal_no_access_count' => $portalNoAccessCount,
            ],
        ];
    }

    /**
     * Determina el estado del portal para un cliente.
     *
     * Estados posibles:
     * - sin_portal: no tiene credenciales de portal
     * - portal_creado: tiene credencial pero nunca ingresó (first_login_at NULL)
     * - ya_ingreso: tiene first_login_at registrado
     */
    private function portalStatus(object $customer): array
    {
        if (! $customer->credential_id) {
            return [
                'key' => 'sin_portal',
                'label' => 'Sin portal',
                'color' => 'slate',
                'first_login_at' => null,
                'last_login_at' => null,
            ];
        }

        if (is_null($customer->first_login_at)) {
            return [
                'key' => 'portal_creado',
                'label' => 'Portal creado — Nunca ingresó',
                'color' => 'amber',
                'first_login_at' => null,
                'last_login_at' => null,
            ];
        }

        return [
            'key' => 'ya_ingreso',
            'label' => 'Ya ingresó',
            'color' => 'green',
            'first_login_at' => $customer->first_login_at,
            'last_login_at' => $customer->last_login_at,
        ];
    }

    private function buildWhatsappUrl(object $customer, Company $company): ?string
    {
        $rawPhone = $customer->phone ?: $customer->mobile;
        $countryCode = $customer->phone_country_code ?: $company->default_phone_country_code;

        if (! $rawPhone || ! $countryCode) {
            return null;
        }

        $whatsappPhone = $this->phones->forWhatsApp($countryCode, $rawPhone);

        if (! $whatsappPhone) {
            return null;
        }

        $message = 'Hola '.$customer->name.', te saludamos de '.$company->trade_name.'. Ya tienes disponible tu Portal de Fidelidad.';

        return 'https://wa.me/'.$whatsappPhone.'?text='.rawurlencode($message);
    }
}