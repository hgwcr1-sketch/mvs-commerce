<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\Unit;
use App\Models\User;
use App\Services\Loyalty\LoyaltyAccountService;
use App\Services\Loyalty\LoyaltySaleReturnAdjustmentService;
use App\Services\PaymentMethodProvisioner;
use App\Services\Sales\SaleReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaleReturnLoyaltyTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_return_reverts_proportional_earned_points(): void
    {
        [$company, $branch, $user, $customer] = $this->context();
        $sale = $this->checkout($user, $company, $branch, $customer, 2);

        // 5000 prefondo + 100 ganados.
        $this->assertSame('5100.0000', LoyaltyAccount::firstOrFail()->balance);

        $return = $this->returnUnits($user, $company, $branch, $sale, 1);

        $account = LoyaltyAccount::firstOrFail();
        $this->assertSame('5050.0000', $account->balance);
        $this->assertSame('50.0000', $account->total_earned);
        $this->assertSame(Sale::STATUS_PARTIALLY_RETURNED, $sale->fresh()->status);

        $reversal = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_RETURN)->sole();
        $earn = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_PURCHASE)->sole();

        $this->assertSame('-50.0000', (string) $reversal->points);
        $this->assertSame($earn->id, $reversal->related_movement_id);
        $this->assertSame("sale:return:{$return->id}:earned", $reversal->event_key);
        $this->assertSame(SaleReturn::class, $reversal->source_type);
        $this->assertSame($return->id, (int) $reversal->source_id);
        $this->assertSame($sale->branch_id, $reversal->branch_id);
        $this->assertSame($user->id, $reversal->user_id);
        $this->assertSame($customer->id, $reversal->customer_id);
        $this->assertSame('earned', $reversal->metadata['kind']);
        $this->assertSame($sale->id, $reversal->metadata['sale_id']);
        $this->assertSame($return->id, $reversal->metadata['return_id']);
        $this->assertSame('0.500000', $reversal->metadata['ratio']);
        $this->assertSame('100.0000', $reversal->metadata['original_points']);
        $this->assertSame('5100.0000', (string) $reversal->balance_before);
        $this->assertSame('5050.0000', (string) $reversal->balance_after);
    }

    public function test_total_return_reverts_all_applicable_earned_points(): void
    {
        [$company, $branch, $user, $customer] = $this->context();
        $sale = $this->checkout($user, $company, $branch, $customer, 2);

        $this->returnUnits($user, $company, $branch, $sale, 2);

        $account = LoyaltyAccount::firstOrFail();
        $this->assertSame('5000.0000', $account->balance);
        $this->assertSame('0.0000', $account->total_earned);
        $this->assertSame(Sale::STATUS_RETURNED, $sale->fresh()->status);

        $chain = LoyaltyMovement::query()->orderBy('id')->get(['type', 'points']);
        $this->assertSame(
            [
                ['type' => LoyaltyMovement::TYPE_PURCHASE, 'points' => '100.0000'],
                ['type' => LoyaltyMovement::TYPE_RETURN, 'points' => '-100.0000'],
            ],
            $chain->map(fn ($movement) => ['type' => $movement->type, 'points' => (string) $movement->points])->all(),
        );
    }

    public function test_successive_partial_returns_never_exceed_original_points_and_round_half_up(): void
    {
        [$company, $branch, $user, $customer] = $this->context(['earning_percentage' => '3.3333']);
        $sale = $this->checkout($user, $company, $branch, $customer, 3);

        // 3000 × 3.3333% = 99.9990 puntos ganados.
        $this->assertSame('99.9990', (string) LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_PURCHASE)->value('points'));

        // 1 de 3 unidades: 99.9990 × (1000/3000) = 33.332966… → half-up → 33.3330.
        $this->returnUnits($user, $company, $branch, $sale, 1);
        $returns = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_RETURN)->orderBy('id')->get();
        $this->assertCount(1, $returns);
        $this->assertSame('-33.3330', (string) $returns[0]->points);

        // Devolución del remanente: el objetivo acumulado alcanza el total exacto.
        $this->returnUnits($user, $company, $branch, $sale, 2);
        $returns = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_RETURN)->orderBy('id')->get();
        $this->assertCount(2, $returns);
        $this->assertSame('-66.6660', (string) $returns[1]->points);

        $sumReverted = bcadd(ltrim((string) $returns[0]->points, '-'), ltrim((string) $returns[1]->points, '-'), 4);
        $this->assertSame('99.9990', $sumReverted);
        $account = LoyaltyAccount::firstOrFail();
        $this->assertSame('5000.0000', $account->balance);
        $this->assertSame('0.0000', $account->total_earned);
    }

    public function test_mixed_sale_return_restores_used_points_and_reverts_earned_proportionally(): void
    {
        [$company, $branch, $user, $customer] = $this->context();
        $sale = $this->checkout($user, $company, $branch, $customer, 2, 1000.0, '400');

        // 5000 iniciales + 100 ganados - 400 canjeados.
        $this->assertSame('4700.0000', LoyaltyAccount::firstOrFail()->balance);

        $this->returnUnits($user, $company, $branch, $sale, 1);

        $account = LoyaltyAccount::firstOrFail();
        // Devolución de la mitad: -50 ganados y +200 restaurados.
        $this->assertSame('4850.0000', $account->balance);
        $this->assertSame('50.0000', $account->total_earned);
        $this->assertSame('200.0000', $account->total_redeemed);

        $movements = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_RETURN)->orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertSame(['earned', 'redeemed'], $movements->map(fn ($movement) => $movement->metadata['kind'])->all());

        $restoration = $movements->last();
        $redemption = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_REDEMPTION)->sole();
        $this->assertSame('200.0000', (string) $restoration->points);
        $this->assertSame($redemption->id, $restoration->related_movement_id);
        $this->assertSame("sale:return:{$this->lastReturnId()}:redeemed", $restoration->event_key);
        $this->assertSame('redeemed', $restoration->metadata['kind']);
        $this->assertSame('400.0000', $restoration->metadata['redeemed_points']);

        $loyaltyMethod = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_LOYALTY_POINTS)->firstOrFail();
        $this->assertSame(SalePayment::STATUS_COMPLETED, SalePayment::query()->where('sale_id', $sale->id)->where('payment_method_id', $loyaltyMethod->id)->value('status'));
    }

    public function test_total_return_of_mixed_sale_leaves_account_as_if_sale_never_existed(): void
    {
        [$company, $branch, $user, $customer] = $this->context();
        $sale = $this->checkout($user, $company, $branch, $customer, 2, 1000.0, '400');

        $this->returnUnits($user, $company, $branch, $sale, 1);
        $this->returnUnits($user, $company, $branch, $sale, 1);

        $account = LoyaltyAccount::firstOrFail();
        $this->assertSame('5000.0000', $account->balance);
        $this->assertSame('0.0000', $account->total_earned);
        $this->assertSame('0.0000', $account->total_redeemed);
        $this->assertSame(Sale::STATUS_RETURNED, $sale->fresh()->status);
    }

    public function test_reprocessing_the_same_return_is_fully_idempotent(): void
    {
        [$company, $branch, $user, $customer] = $this->context();
        $sale = $this->checkout($user, $company, $branch, $customer, 2, 1000.0, '400');
        $return = $this->returnUnits($user, $company, $branch, $sale, 1);

        $movementsAfterFirstRun = LoyaltyMovement::query()->count();

        app(LoyaltySaleReturnAdjustmentService::class)->adjust($sale, $return, $user);
        app(LoyaltySaleReturnAdjustmentService::class)->adjust($sale, $return, $user);

        $this->assertSame($movementsAfterFirstRun, LoyaltyMovement::query()->count());
        $this->assertSame('4850.0000', LoyaltyAccount::firstOrFail()->balance);
        $this->assertSame(2, LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_RETURN)->count());
    }

    public function test_insufficient_balance_blocks_the_return_atomically(): void
    {
        [$company, $branch, $user, $customer] = $this->context();
        $sale = $this->checkout($user, $company, $branch, $customer, 2);

        // El cliente gastó absolutamente todo su saldo antes de la devolución.
        $account = LoyaltyAccount::firstOrFail();
        app(LoyaltyAccountService::class)->subtractPoints($account, '5100', LoyaltyMovement::TYPE_REDEMPTION, [
            'description' => 'Gasto posterior simulado',
            'event_key' => 'simulated-spend:'.$sale->id,
        ]);
        $this->assertSame('0.0000', LoyaltyAccount::firstOrFail()->balance);

        try {
            $this->returnUnits($user, $company, $branch, $sale, 1);
            $this->fail('La devolución debía bloquearse por saldo insuficiente.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('points', $exception->errors());
        }

        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertDatabaseCount('sale_return_items', 0);
        $this->assertSame(Sale::STATUS_COMPLETED, $sale->fresh()->status);
        $this->assertSame(0, LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_RETURN)->count());
        $this->assertSame('0.0000', LoyaltyAccount::firstOrFail()->balance);
        $stock = (float) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', Product::query()->firstOrFail()->id)->value('stock');
        $this->assertSame(8.0, $stock);
        $this->assertDatabaseCount('inventory_movements', 1); // solo la salida por la venta original
    }

    public function test_cross_company_return_is_blocked_without_adjustments(): void
    {
        [$companyA, $branchA, $userA, $customerA] = $this->context();
        [$companyB, $branchB, $userB] = $this->context();
        $sale = $this->checkout($userA, $companyA, $branchA, $customerA, 2);

        $this->actingAs($userB)
            ->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->post(route('ventas.return.store', $sale), [
                'reason' => 'Intento cruzado',
                'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertSame(0, LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_RETURN)->count());
        $this->assertSame('5100.0000', LoyaltyAccount::query()->where('company_id', $companyA->id)->value('balance'));
    }

    public function test_kardex_chain_stays_coherent_across_returns(): void
    {
        [$company, $branch, $user, $customer] = $this->context();
        $sale = $this->checkout($user, $company, $branch, $customer, 2, 1000.0, '400');

        $this->returnUnits($user, $company, $branch, $sale, 1);
        $this->returnUnits($user, $company, $branch, $sale, 1);

        $runningBalance = '5000.0000'; // saldo prefondado inicial de la cuenta
        $chain = LoyaltyMovement::query()->orderBy('id')->get(['type', 'points', 'balance_after']);

        foreach ($chain as $entry) {
            $runningBalance = bcadd($runningBalance, (string) $entry->points, 4);
            $this->assertSame($runningBalance, (string) $entry->balance_after);
        }

        $this->assertSame('5000.0000', $runningBalance);
    }

    private function context(array $settingOverrides = []): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        app(PaymentMethodProvisioner::class)->provision($company);
        LoyaltySetting::create(array_merge([
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => '5.0000',
            'point_value' => '1.0000',
            'maximum_redemption_percent' => '100.0000',
            'redeem_on_offers' => false,
        ], $settingOverrides));
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P-'.uniqid(), 'is_active' => true]);
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach (['pos.acceder', 'ventas.crear', 'devoluciones.crear'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);
        LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => '5000.0000']);

        return [$company, $branch, $user, $customer];
    }

    private function checkout(User $user, Company $company, Branch $branch, Customer $customer, int $quantity = 1, float $price = 1000.0, ?string $requestedPoints = null): Sale
    {
        $suffix = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'allows_decimals' => false, 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$suffix, 'internal_code' => 'P-'.$suffix, 'cost' => 500, 'sale_price' => $price, 'tax_rate' => 0, 'track_inventory' => true, 'is_active' => true]);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 10, 'created_at' => now(), 'updated_at' => now()]);

        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        $total = $price * $quantity;
        $pending = $total - (float) ($requestedPoints ?? 0);

        // El método Puntos lo inyecta internamente el POS a partir de requested_points.
        $payments = [];
        if ($pending > 0) {
            $payments[] = ['payment_method_id' => $cash->id, 'amount' => $pending, 'received_amount' => $pending, 'reference' => null];
        }

        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])->postJson(route('pos.checkout'), array_filter([
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer->id,
            'payments' => $payments,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'requested_points' => $requestedPoints,
        ], fn ($value) => $value !== null));

        $response->assertOk();

        return Sale::query()->latest('id')->firstOrFail();
    }

    private function returnUnits(User $user, Company $company, Branch $branch, Sale $sale, float|int $quantity): SaleReturn
    {
        return app(SaleReturnService::class)->store(
            $sale,
            $user,
            'Devolución de prueba',
            [['sale_item_id' => $sale->items->first()->id, 'quantity' => $quantity]],
        );
    }

    private function lastReturnId(): int
    {
        return (int) SaleReturn::query()->max('id');
    }

    private function ensureCashSession(Company $company, Branch $branch, User $user): CashSession
    {
        $session = CashSession::query()->forCompany($company->id)->forBranch($branch->id)->where('opened_by', $user->id)->where('status', CashSession::STATUS_OPEN)->first();
        if ($session) {
            return $session;
        }
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CAJA-'.uniqid(), 'name' => 'Caja', 'is_active' => true]);

        return CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'SES-'.uniqid(), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'opening_amount' => 0, 'opened_at' => now()]);
    }
}
