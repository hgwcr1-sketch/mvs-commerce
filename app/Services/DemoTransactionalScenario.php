<?php

namespace App\Services;

use App\Data\Purchases\PurchaseData;
use App\Data\Purchases\PurchaseLineData;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMultiplier;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\Cash\CashClosingService;
use App\Services\Cash\CashSessionService;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Purchases\PurchaseProcessor;
use App\Services\Sales\PosSaleProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class DemoTransactionalScenario
{
    private const SALES_DSJ = [
        ['customer' => '123456789', 'items' => [['code' => 'CAM-CL-001', 'qty' => 2]], 'payment' => 'cash', 'day_offset' => -6, 'time' => '10:00:00'],
        ['customer' => '234567890', 'items' => [['code' => 'PAN-SL-001', 'qty' => 1]], 'payment' => 'card', 'day_offset' => -5, 'time' => '11:30:00'],
        ['customer' => '345678901', 'items' => [['code' => 'CAM-CA-002', 'qty' => 1], ['code' => 'BOL-EC-001', 'qty' => 1]], 'payment' => 'sinpe', 'day_offset' => -5, 'time' => '15:00:00'],
        ['customer' => '456789012', 'items' => [['code' => 'ZAP-RN-001', 'qty' => 1]], 'payment' => 'cash', 'day_offset' => -4, 'time' => '09:30:00'],
        ['customer' => '567890123', 'items' => [['code' => 'CAM-FM-003', 'qty' => 1]], 'payment' => 'card', 'day_offset' => -3, 'time' => '14:00:00'],
        ['customer' => '678901234', 'items' => [['code' => 'PAN-CA-002', 'qty' => 2]], 'payment' => 'cash', 'day_offset' => -2, 'time' => '10:15:00'],
        ['customer' => '789012345', 'items' => [['code' => 'COJ-AR-001', 'qty' => 3]], 'payment' => 'sinpe', 'day_offset' => -1, 'time' => '16:00:00'],
        ['customer' => '890123456', 'items' => [['code' => 'MAN-EC-001', 'qty' => 1]], 'payment' => 'cash', 'day_offset' => -1, 'time' => '11:00:00'],
        ['customer' => '901234567', 'items' => [['code' => 'CAM-BA-004', 'qty' => 2]], 'payment' => 'card', 'day_offset' => 0, 'time' => '09:00:00'],
    ];

    private const SALES_DLB = [
        ['customer' => '012345678', 'items' => [['code' => 'CAM-CL-001', 'qty' => 1]], 'payment' => 'cash', 'day_offset' => -6, 'time' => '10:30:00'],
        ['customer' => '111222333', 'items' => [['code' => 'PAN-JG-003', 'qty' => 2]], 'payment' => 'card', 'day_offset' => -4, 'time' => '14:30:00'],
        ['customer' => '222333444', 'items' => [['code' => 'ZAP-UR-002', 'qty' => 1]], 'payment' => 'sinpe', 'day_offset' => -3, 'time' => '10:00:00'],
        ['customer' => '333444555', 'items' => [['code' => 'VES-CF-001', 'qty' => 1]], 'payment' => 'cash', 'day_offset' => -2, 'time' => '11:45:00'],
        ['customer' => '123456789', 'items' => [['code' => 'SAN-PL-001', 'qty' => 2]], 'payment' => 'card', 'day_offset' => -1, 'time' => '15:30:00'],
        ['customer' => '234567890', 'items' => [['code' => 'CIN-CA-001', 'qty' => 1]], 'payment' => 'cash', 'day_offset' => -1, 'time' => '09:45:00'],
        ['customer' => '456789012', 'items' => [['code' => 'SOM-CT-001', 'qty' => 1]], 'payment' => 'sinpe', 'day_offset' => 0, 'time' => '10:30:00'],
    ];

    private const PURCHASES = [
        ['supplier' => '3101234567', 'branch_code' => 'DSJ', 'payment_type' => 'cash', 'day_offset' => -7, 'lines' => [['code' => 'CAM-CL-001', 'qty' => 20, 'cost' => 8500], ['code' => 'CAM-CA-002', 'qty' => 15, 'cost' => 12000], ['code' => 'PAN-SL-001', 'qty' => 10, 'cost' => 11000]]],
        ['supplier' => '3102345678', 'branch_code' => 'DSJ', 'payment_type' => 'credit', 'day_offset' => -6, 'due_date_offset' => 30, 'lines' => [['code' => 'ZAP-RN-001', 'qty' => 8, 'cost' => 22000], ['code' => 'BOL-EC-001', 'qty' => 12, 'cost' => 7000], ['code' => 'CAM-FM-003', 'qty' => 10, 'cost' => 15000]]],
        ['supplier' => '3103456789', 'branch_code' => 'DLB', 'payment_type' => 'cash', 'day_offset' => -7, 'lines' => [['code' => 'PAN-JG-003', 'qty' => 15, 'cost' => 9000], ['code' => 'ZAP-UR-002', 'qty' => 10, 'cost' => 18000], ['code' => 'CAM-BA-004', 'qty' => 20, 'cost' => 4500]]],
    ];

    private const TRANSFERS = [
        ['from' => 'DSJ', 'to' => 'DLB', 'product_code' => 'CAM-CL-001', 'quantity' => 5, 'day_offset' => -5],
        ['from' => 'DLB', 'to' => 'DSJ', 'product_code' => 'PAN-JG-003', 'quantity' => 3, 'day_offset' => -3],
    ];

    public function __construct(
        private readonly PosSaleProcessor $posSaleProcessor,
        private readonly PurchaseProcessor $purchaseProcessor,
        private readonly InventoryPostingService $inventoryPosting,
        private readonly CashSessionService $cashSessionService,
        private readonly CashClosingService $cashClosingService,
    ) {}

    public function seed(Company $company): void
    {
        try {
            $this->seedMultiplier($company);
            $this->seedPurchases($company);
            $this->seedTransfers($company);
            $this->seedSessionsWithSales($company);
            $this->seedCashSessionsCurrent($company);
        } finally {
            CarbonImmutable::setTestNow(null);
        }
    }

    private function seedMultiplier(Company $company): void
    {
        $now = CarbonImmutable::now('America/Costa_Rica');
        LoyaltyMultiplier::create([
            'company_id' => $company->id,
            'branch_id' => null,
            'name' => 'Doble Puntos Demo',
            'multiplier' => '2.0000',
            'starts_at' => $now->copy()->subDay(),
            'ends_at' => $now->copy()->addDays(7),
            'is_active' => true,
        ]);
    }

    private function seedPurchases(Company $company): void
    {
        $owner = User::where('email', config('demo.owner_email'))->first();
        $branches = $company->branches()->get()->keyBy('code');

        foreach (self::PURCHASES as $purchaseDef) {
            $branch = $branches[$purchaseDef['branch_code']];
            $date = CarbonImmutable::now('America/Costa_Rica')->addDays($purchaseDef['day_offset']);
            CarbonImmutable::setTestNow($date->copy()->setTime(9, 0, 0));

            $supplier = \App\Models\Supplier::where('company_id', $company->id)
                ->where('identification', $purchaseDef['supplier'])->first();

            $lines = array_map(fn ($l) => new PurchaseLineData(
                product_id: Product::where('company_id', $company->id)->where('internal_code', $l['code'])->first()?->id,
                quantity: (float) $l['qty'],
                unit_cost: (float) $l['cost'],
                tax_rate: 13.0,
            ), $purchaseDef['lines']);

            $this->purchaseProcessor->process(new PurchaseData(
                company_id: $company->id,
                branch_id: $branch->id,
                supplier_id: $supplier?->id,
                user_id: $owner->id,
                purchase_date: $date->toDateString(),
                payment_type: $purchaseDef['payment_type'],
                due_date: $purchaseDef['payment_type'] === 'credit'
                    ? $date->copy()->addDays($purchaseDef['due_date_offset'] ?? 30)->toDateString()
                    : null,
                lines: $lines,
            ));
        }
    }

    private function seedTransfers(Company $company): void
    {
        $owner = User::where('email', config('demo.owner_email'))->first();
        $branches = $company->branches()->get()->keyBy('code');

        foreach (self::TRANSFERS as $transferDef) {
            $fromBranch = $branches[$transferDef['from']];
            $toBranch = $branches[$transferDef['to']];
            $product = Product::where('company_id', $company->id)
                ->where('internal_code', $transferDef['product_code'])->first();

            $date = CarbonImmutable::now('America/Costa_Rica')->addDays($transferDef['day_offset']);
            CarbonImmutable::setTestNow($date->copy()->setTime(14, 0, 0));

            $this->inventoryPosting->postTransfer(
                $fromBranch, $toBranch, $product,
                (string) $transferDef['quantity'], $owner->id, 'Traslado Demo',
            );
        }
    }

    private function seedSessionsWithSales(Company $company): void
    {
        $owner = User::where('email', config('demo.owner_email'))->first();
        $branches = $company->branches()->get()->keyBy('code');
        $customers = Customer::where('company_id', $company->id)->get()->keyBy('identification');
        $paymentMethods = PaymentMethod::where('company_id', $company->id)->get()->keyBy('code');
        $registers = \App\Models\CashRegister::where('company_id', $company->id)
            ->get()->keyBy(fn ($r) => $branches->firstWhere('id', $r->branch_id)?->code);

        $allSales = [
            'DSJ' => ['sales' => self::SALES_DSJ, 'user' => $owner],
            'DLB' => ['sales' => self::SALES_DLB, 'user' => $owner],
        ];

        $openingAmount = 50000;

        foreach ($allSales as $branchCode => $config) {
            $branch = $branches[$branchCode];
            $user = $config['user'];
            $register = $registers[$branchCode];

            $grouped = [];
            foreach ($config['sales'] as $saleDef) {
                $grouped[$saleDef['day_offset']][] = $saleDef;
            }
            ksort($grouped);

            foreach ($grouped as $dayOffset => $daySales) {
                $date = CarbonImmutable::now('America/Costa_Rica')->addDays($dayOffset);
                CarbonImmutable::setTestNow($date->copy()->setTime(8, 0, 0));

                $session = $this->openSession($company, $branch, $register, $user, $openingAmount);

                $cashTotal = '0.0000';
                foreach ($daySales as $saleDef) {
                    $customer = $customers[$saleDef['customer']] ?? null;
                    if ($customer === null) {
                        continue;
                    }
                    [$hour, $minute, $second] = array_map('intval', explode(':', $saleDef['time']));
                    CarbonImmutable::setTestNow($date->copy()->setTime($hour, $minute, $second));

                    $unroundedTotal = '0.0000';
                    $items = [];
                    foreach ($saleDef['items'] as $itemDef) {
                        $product = Product::where('company_id', $company->id)
                            ->where('internal_code', $itemDef['code'])->first();
                        if ($product === null) {
                            continue 2;
                        }
                        $lineSubtotal = bcmul((string) $product->sale_price, (string) $itemDef['qty'], 4);
                        $lineTax = bcmul($lineSubtotal, '0.13', 4);
                        $lineTotal = bcadd($lineSubtotal, $lineTax, 4);
                        $unroundedTotal = bcadd($unroundedTotal, $lineTotal, 4);
                        $items[] = ['product_id' => $product->id, 'quantity' => (float) $itemDef['qty']];
                    }

                    $total = (string) round((float) $unroundedTotal, 0, PHP_ROUND_HALF_UP);

                    if ($items === []) {
                        continue;
                    }

                    $paymentCode = $saleDef['payment'];
                    $method = $paymentMethods[$paymentCode] ?? null;
                    if ($method === null) {
                        continue;
                    }

                    if ($paymentCode === 'cash') {
                        $cashTotal = bcadd($cashTotal, $total, 4);
                    }

                    $payments = [[
                        'payment_method_id' => $method->id,
                        'amount' => (float) $total,
                        'reference' => in_array($paymentCode, ['card', 'sinpe']) ? 'REF-' . Str::random(6) : null,
                    ]];

                    $this->posSaleProcessor->process([
                        'checkout_token' => (string) Str::uuid(),
                        'customer_id' => $customer->id,
                        'document_type' => 'electronic_ticket',
                        'payments' => $payments,
                        'items' => $items,
                    ], $user, $company->id, $branch->id);
                }

                $this->closeSession($company, $branch, $user, $session, $openingAmount, $cashTotal, $paymentMethods);
            }
        }
    }

    private function seedCashSessionsCurrent(Company $company): void
    {
        $owner = User::where('email', config('demo.owner_email'))->first();
        $branches = $company->branches()->get()->keyBy('code');
        $registers = \App\Models\CashRegister::where('company_id', $company->id)
            ->get()->keyBy(fn ($r) => $branches->firstWhere('id', $r->branch_id)?->code);

        CarbonImmutable::setTestNow(CarbonImmutable::now('America/Costa_Rica')->setTime(8, 0, 0));

        $this->openSession($company, $branches['DSJ'], $registers['DSJ'], $owner, 50000);
        $this->openSession($company, $branches['DLB'], $registers['DLB'], $owner, 50000);
    }

    private function openSession(
        Company $company,
        Branch $branch,
        \App\Models\CashRegister $register,
        User $user,
        int $amount,
    ): \App\Models\CashSession {
        $denominations = \App\Models\CashDenomination::forCompany($company->id)
            ->forCurrency('CRC')->active()->orderBy('id')->get();

        $denomData = [];
        foreach ($denominations as $d) {
            $denomData[$d->id] = 0;
        }
        $remaining = $amount;
        foreach ($denominations as $d) {
            $value = (int) $d->value;
            $count = intdiv($remaining, $value);
            if ($count > 0) {
                $denomData[$d->id] = $count;
                $remaining -= $count * $value;
            }
        }

        return $this->cashSessionService->open([
            'cash_register_id' => $register->id,
            'denominations' => $denomData,
            'confirmation' => 'accepted',
        ], $user, $company->id, $branch->id);
    }

    private function closeSession(
        Company $company,
        Branch $branch,
        User $user,
        \App\Models\CashSession $session,
        int $openingAmount,
        string $cashSalesTotal,
        \Illuminate\Support\Collection $paymentMethods,
    ): void {
        $token = (string) Str::uuid();
        $this->cashClosingService->start($user, $company->id, $branch->id, $session->id, $token);

        $expectedCash = bcadd((string) $openingAmount, $cashSalesTotal, 4);

        $denominations = \App\Models\CashDenomination::forCompany($company->id)
            ->forCurrency('CRC')->active()->orderBy('id')->get();

        $denomData = [];
        $remaining = (int) round((float) $expectedCash);
        foreach ($denominations as $d) {
            $value = (int) $d->value;
            $count = intdiv($remaining, $value);
            $denomData[$d->id] = $count;
            $remaining -= $count * $value;
        }

        $paymentsData = [];
        foreach ($paymentMethods as $pm) {
            $paymentsData[$pm->id] = ['reported_amount' => 0];
        }

        $this->cashClosingService->submit($user, $company->id, $branch->id, $session->id, [
            'request_token' => $token,
            'denominations' => $denomData,
            'payments' => $paymentsData,
            'closing_notes' => 'Cierre Demo',
        ]);
    }
}
