<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Cash\CashExpectedAmountService;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PosLayawayModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_creates_layaway_reserving_stock_and_initial_payment_without_a_sale(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'initial_amount' => 200,
            'payment_method_id' => $cash->id,
            'cash_session_id' => $session->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('layaway_number', 'APT-00000001')
            ->assertJsonPath('balance_due', '1800.0000');
        $this->assertSame(route('apartados.show', $response->json('layaway_id')), $response->json('show_url'));

        $layaway = Layaway::with('items')->firstOrFail();
        $this->assertSame($company->id, $layaway->company_id);
        $this->assertSame($branch->id, $layaway->branch_id);
        $this->assertSame($customer->id, $layaway->customer_id);
        $this->assertSame(Layaway::STATUS_ACTIVE, $layaway->status);
        $this->assertSame('1800.0000', $layaway->balance_due);
        $this->assertSame('200.0000', $layaway->paid_total);
        $this->assertSame('2.0000', $layaway->items->first()->quantity);
        $this->assertEquals(3, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseHas('inventory_movements', ['type' => 'layaway_reserve', 'reference_id' => $layaway->id, 'product_id' => $product->id]);
        $this->assertDatabaseHas('layaway_payments', ['layaway_id' => $layaway->id, 'amount' => 200, 'affects_cash_snapshot' => true]);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertSame(200.0, app(CashExpectedAmountService::class)->calculate($session));
    }

    public function test_pos_layaway_requires_customer_items_and_positive_initial_amount(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $base = ['customer_id' => $customer->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'initial_amount' => 100, 'payment_method_id' => $cash->id, 'cash_session_id' => $session->id];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), array_merge($base, ['customer_id' => null]))->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), array_merge($base, ['items' => []]))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), array_merge($base, ['initial_amount' => 0]))->assertUnprocessable()->assertJsonValidationErrors('initial_amount');

        $this->assertDatabaseCount('layaways', 0);
        $this->assertSame(5, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    public function test_pos_layaway_respects_manual_price_permission_and_client_token_idempotency(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context('Empresa', ['pos.acceder', 'ventas.crear', 'apartados.ver', 'apartados.crear', 'pos.cambiar_precio']);
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $payload = [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 750]],
            'initial_amount' => 500,
            'payment_method_id' => $cash->id,
            'cash_session_id' => $session->id,
            'client_token' => '11111111-1111-4111-8111-111111111111',
        ];

        $first = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.apartados.store'), $payload)
            ->assertCreated()
            ->assertJsonPath('total', '1500.0000')
            ->assertJsonPath('balance_due', '1000.0000');

        $second = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.apartados.store'), $payload)
            ->assertCreated()
            ->assertJsonPath('layaway_id', $first->json('layaway_id'))
            ->assertJsonPath('total', '1500.0000');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.apartados.store'), array_merge($payload, ['initial_amount' => 600]))
            ->assertConflict()
            ->assertJsonPath('message', 'El token de apartado ya fue utilizado con datos diferentes.');

        $layaway = Layaway::with('items')->firstOrFail();
        $this->assertSame('750.0000', $layaway->items->first()->unit_price);
        $this->assertDatabaseCount('layaways', 1);
        $this->assertDatabaseCount('layaway_items', 1);
        $this->assertDatabaseCount('layaway_payments', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(3, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    public function test_client_token_is_scoped_per_company_and_ignores_injected_tenant_context(): void
    {
        $token = '44444444-4444-4444-8444-444444444444';
        [$company, $branch, $user, $cash, $session] = $this->context('Primera');
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);

        [$otherCompany, $otherBranch, $otherUser, $otherCash, $otherSession] = $this->context('Segunda');
        $otherProduct = $this->product($otherCompany);
        $this->stock($otherBranch, $otherProduct, 5);
        $otherCustomer = $this->customer($otherCompany);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'company_id' => $otherCompany->id,
            'branch_id' => $otherBranch->id,
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'initial_amount' => 100,
            'payment_method_id' => $cash->id,
            'cash_session_id' => $session->id,
            'client_token' => $token,
        ])->assertCreated();

        $this->actingAs($otherUser)->withSession($this->activeSession($otherCompany, $otherBranch))->postJson(route('pos.apartados.store'), [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $otherCustomer->id,
            'items' => [['product_id' => $otherProduct->id, 'quantity' => 2]],
            'initial_amount' => 100,
            'payment_method_id' => $otherCash->id,
            'cash_session_id' => $otherSession->id,
            'client_token' => $token,
        ])->assertCreated();

        $this->assertDatabaseCount('layaways', 2);
        $this->assertSame(1, Layaway::forCompany($company->id)->where('client_token', $token)->count());
        $this->assertSame(1, Layaway::forCompany($otherCompany->id)->where('client_token', $token)->count());
        $this->assertSame(3, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame(3, (int) DB::table('branch_product')->where('branch_id', $otherBranch->id)->where('product_id', $otherProduct->id)->value('stock'));
    }

    public function test_pos_layaway_integrates_with_existing_list_payments_and_delivery(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context('Empresa', [
            'pos.acceder', 'ventas.crear', 'ventas.ver', 'apartados.ver', 'apartados.crear',
            'apartados.abonar', 'apartados.entregar', 'pos.cambiar_precio',
        ]);
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);

        $created = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 750]],
            'initial_amount' => 500,
            'payment_method_id' => $cash->id,
            'cash_session_id' => $session->id,
            'client_token' => '55555555-5555-4555-8555-555555555555',
        ])->assertCreated();

        $layaway = Layaway::findOrFail($created->json('layaway_id'));
        $this->withoutVite();
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('apartados.index'))->assertOk()->assertSee($layaway->number);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('apartados.payments.store', $layaway), [
                'amount' => 1000,
                'payment_method_id' => $cash->id,
                'cash_session_id' => $session->id,
            ])->assertRedirect();
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('apartados.deliver', $layaway))->assertRedirect();

        $layaway->refresh();
        $sale = $layaway->sale()->with('items')->firstOrFail();
        $this->assertSame(Layaway::STATUS_DELIVERED, $layaway->status);
        $this->assertSame('0.0000', $layaway->balance_due);
        $this->assertSame('750.0000', $sale->items->first()->unit_price);
        $this->assertSame('0.0000', $sale->items->first()->discount_total);
        $this->assertSame('1500.0000', $sale->total);
        $this->assertSame(3, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame(1, DB::table('inventory_movements')->where('reference_type', Layaway::class)->where('reference_id', $layaway->id)->count());
        $this->assertDatabaseCount('layaway_payments', 2);
    }

    public function test_initial_payment_obeys_cash_effect_and_open_session_rules(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $base = [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'initial_amount' => 100,
        ];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), array_merge($base, [
            'payment_method_id' => $cash->id,
            'cash_session_id' => null,
        ]))->assertUnprocessable()->assertJsonValidationErrors('cash_session_id');

        $sinpe = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_SINPE)->firstOrFail();
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), array_merge($base, [
            'payment_method_id' => $sinpe->id,
            'cash_session_id' => null,
        ]))->assertCreated();

        $this->assertDatabaseCount('layaways', 1);
        $this->assertDatabaseCount('layaway_payments', 1);
        $this->assertDatabaseHas('layaway_payments', [
            'payment_method_id' => $sinpe->id,
            'cash_session_id' => null,
            'affects_cash_snapshot' => false,
            'cash_effect_amount' => 0,
        ]);
        $this->assertSame(4, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    public function test_pos_layaway_rejects_foreign_and_inactive_payment_methods(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        [$otherCompany, , , $foreignCash] = $this->context('Ajena');
        $base = [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'initial_amount' => 100,
            'cash_session_id' => $session->id,
        ];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), array_merge($base, [
            'payment_method_id' => $foreignCash->id,
        ]))->assertUnprocessable();

        $cash->update(['is_active' => false]);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), array_merge($base, [
            'payment_method_id' => $cash->id,
        ]))->assertUnprocessable();

        $this->assertDatabaseCount('layaways', 0);
        $this->assertSame(5, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame($otherCompany->id, $foreignCash->company_id);
    }

    public function test_pos_layaway_rejects_manual_price_without_pos_permission(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 750]],
            'initial_amount' => 100,
            'payment_method_id' => $cash->id,
            'cash_session_id' => $session->id,
            'client_token' => '22222222-2222-4222-8222-222222222222',
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('layaways', 0);
        $this->assertSame(5, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    public function test_pos_layaway_rejects_discount_payload_because_existing_layaway_cannot_preserve_it(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context('Empresa', ['pos.acceder', 'ventas.crear', 'apartados.ver', 'apartados.crear', 'pos.aplicar_descuento']);
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'discount' => 100, 'discount_type' => 'fixed']],
            'discount_total' => 50,
            'discount_total_type' => 'fixed',
            'initial_amount' => 100,
            'payment_method_id' => $cash->id,
            'cash_session_id' => $session->id,
            'client_token' => '33333333-3333-4333-8333-333333333333',
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.discount', 'discount_total']);

        $this->assertDatabaseCount('layaways', 0);
        $this->assertSame(5, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    public function test_pos_layaway_rejects_zero_and_insufficient_stock_and_rolls_back(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $zero = $this->product($company);
        $limited = $this->product($company);
        $this->stock($branch, $zero, 0);
        $this->stock($branch, $limited, 2);
        $customer = $this->customer($company);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id, 'items' => [['product_id' => $zero->id, 'quantity' => 1]], 'initial_amount' => 100, 'payment_method_id' => $cash->id, 'cash_session_id' => $session->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id, 'items' => [['product_id' => $limited->id, 'quantity' => 5]], 'initial_amount' => 100, 'payment_method_id' => $cash->id, 'cash_session_id' => $session->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id, 'items' => [['product_id' => $limited->id, 'quantity' => 1]], 'initial_amount' => 99999, 'payment_method_id' => $cash->id, 'cash_session_id' => $session->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('layaways', 0);
        $this->assertDatabaseCount('layaway_items', 0);
        $this->assertDatabaseCount('layaway_payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(0, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $zero->id)->value('stock'));
        $this->assertSame(2, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $limited->id)->value('stock'));
    }

    public function test_pos_layaway_uses_only_active_branch_stock(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $otherBranch = $this->branch($company, 'Secundaria');
        $product = $this->product($company);
        $this->stock($otherBranch, $product, 20);
        $customer = $this->customer($company);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'initial_amount' => 100, 'payment_method_id' => $cash->id, 'cash_session_id' => $session->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('layaways', 0);
        $this->assertSame(20, (int) DB::table('branch_product')->where('branch_id', $otherBranch->id)->where('product_id', $product->id)->value('stock'));
    }

    public function test_pos_layaway_requires_permission_and_enforces_company_isolation(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);

        $withoutPermission = $this->user($company, $branch, ['pos.acceder', 'ventas.crear']);
        $ownSession = $this->openSession($company, $branch, $withoutPermission);
        $this->actingAs($withoutPermission)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.apartados.store'), [
                'customer_id' => $customer->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'initial_amount' => 100, 'payment_method_id' => $cash->id, 'cash_session_id' => $ownSession->id,
            ])->assertForbidden();
        $this->actingAs($withoutPermission)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))->assertOk()->assertSee('canCreateLayaway: false', false)->assertDontSee('Crear apartado');
        $this->actingAs($withoutPermission)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index', ['mode' => 'layaway']))->assertOk()
            ->assertSee('canCreateLayaway: false', false)->assertDontSee('>Apartar<', false);
        $this->actingAs($withoutPermission)->withSession($this->activeSession($company, $branch))
            ->get(route('apartados.index'))->assertForbidden();

        [$foreignCompany, $foreignBranch, $foreignUser, $foreignCash] = $this->context('Ajena');
        $foreignProduct = $this->product($foreignCompany);
        $this->stock($foreignBranch, $foreignProduct, 5);
        $foreignCustomer = $this->customer($foreignCompany);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $foreignCustomer->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'initial_amount' => 100, 'payment_method_id' => $cash->id, 'cash_session_id' => $session->id,
        ])->assertUnprocessable()->assertJsonStructure(['message']);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id, 'items' => [['product_id' => $foreignProduct->id, 'quantity' => 1]], 'initial_amount' => 100, 'payment_method_id' => $cash->id, 'cash_session_id' => $session->id,
        ])->assertUnprocessable()->assertJsonStructure(['message']);

        $this->actingAs($foreignUser)->withSession($this->activeSession($foreignCompany, $foreignBranch))
            ->get(route('pos.index'))->assertOk()->assertSee('canCreateLayaway: true', false)->assertSee('Crear apartado');
        $this->assertDatabaseCount('layaways', 0);
        $this->assertSame(5, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    public function test_pos_layaway_rejects_credit_and_loyalty_points_as_initial_payment(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);

        foreach ([PaymentMethod::TYPE_CREDIT, PaymentMethod::TYPE_LOYALTY_POINTS] as $type) {
            $method = PaymentMethod::forCompany($company->id)->where('type', $type)->firstOrFail();
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
                'customer_id' => $customer->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'initial_amount' => 100, 'payment_method_id' => $method->id, 'cash_session_id' => $session->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('payment_method_id');
        }

        $this->assertDatabaseCount('layaways', 0);
        $this->assertSame(5, (int) DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    public function test_pos_layaway_mode_executes_frontend_transitions_stock_rules_and_save_contract(): void
    {
        [$company, $branch, $user] = $this->context('Empresa', ['pos.acceder', 'ventas.crear', 'apartados.ver', 'apartados.crear', 'cotizaciones.crear']);
        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))->assertOk()
            ->assertSee('MODO APARTADO')->assertSee('Crear apartado')->assertSee('Apartar')
            ->assertSee('canCreateLayaway: true', false)
            ->assertDontSee(route('apartados.create'));
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index', ['mode' => 'layaway']))->assertOk()
            ->assertSee('canCreateLayaway: true', false);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('apartados.index'))->assertOk()
            ->assertSee(route('pos.index', ['mode' => 'layaway']))
            ->assertDontSee(route('apartados.create'));
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('apartados.create'))->assertOk();
        $process = new Process(['node', base_path('tests/js/pos-layaway-mode.cjs')]);
        $process->setInput($response->getContent())->mustRun();
        $this->assertStringContainsString('Layaway mode UI OK', $process->getOutput());
    }

    public function test_pos_layaway_cash_payment_requires_sufficient_received_amount_and_calculates_change(): void
    {
        [$company, $branch, $user, $cash, $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);

        // Insufficient cash received
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'initial_amount' => 100,
            'payment_method_id' => $cash->id,
            'cash_session_id' => $session->id,
            'received_amount' => 50,
        ])->assertUnprocessable()->assertJsonValidationErrors('received_amount');

        $this->assertDatabaseCount('layaways', 0);

        // Exact cash received → change 0
        $exact = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'initial_amount' => 100,
            'payment_method_id' => $cash->id,
            'cash_session_id' => $session->id,
            'received_amount' => 100,
        ])->assertCreated();
        $layawayExact = Layaway::findOrFail($exact->json('layaway_id'));
        $paymentExact = $layawayExact->payments->first();
        $this->assertSame('100.0000', (string) $paymentExact->amount);
        $this->assertSame('100.0000', (string) $paymentExact->received_amount);
        $this->assertSame('0.0000', (string) $paymentExact->change_amount);
        $this->assertSame('900.0000', (string) $layawayExact->balance_due);

        // Higher cash received → correct change
        $higher = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'initial_amount' => 150,
            'payment_method_id' => $cash->id,
            'cash_session_id' => $session->id,
            'received_amount' => 500,
        ])->assertCreated();
        $layawayHigher = Layaway::findOrFail($higher->json('layaway_id'));
        $paymentHigher = $layawayHigher->payments->first();
        $this->assertSame('150.0000', (string) $paymentHigher->amount);
        $this->assertSame('500.0000', (string) $paymentHigher->received_amount);
        $this->assertSame('350.0000', (string) $paymentHigher->change_amount);
        $this->assertSame('850.0000', (string) $layawayHigher->balance_due);
    }

    public function test_pos_layaway_non_cash_payment_ignores_received_amount_and_sets_change_to_zero(): void
    {
        [$company, $branch, $user, , $session] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 5);
        $customer = $this->customer($company);
        $sinpe = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_SINPE)->firstOrFail();

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.apartados.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'initial_amount' => 100,
            'payment_method_id' => $sinpe->id,
            'cash_session_id' => null,
        ])->assertCreated();

        $layaway = Layaway::findOrFail($response->json('layaway_id'));
        $payment = $layaway->payments->first();
        $this->assertSame('100.0000', (string) $payment->amount);
        $this->assertSame('100.0000', (string) $payment->received_amount);
        $this->assertSame('0.0000', (string) $payment->change_amount);
        $this->assertSame('900.0000', (string) $layaway->balance_due);
    }

    public function test_pos_index_does_not_contain_quote_mojibake(): void
    {
        [$company, $branch, $user] = $this->context('Empresa', ['pos.acceder', 'ventas.crear', 'apartados.ver', 'apartados.crear', 'cotizaciones.crear']);
        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))->assertOk();

        $response->assertSee('Cambiar a cotización');
        $this->assertStringNotContainsString('cotizaciÃ³n', $response->getContent());
    }

    private function context(string $name = 'Empresa', array $permissions = ['pos.acceder', 'ventas.crear', 'apartados.ver', 'apartados.crear']): array
    {
        $company = Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'layaway_validity_days' => 30, 'layaway_alert_days' => 5, 'is_active' => true]);
        $branch = $this->branch($company, 'Principal');
        $user = $this->user($company, $branch, $permissions);
        app(PaymentMethodProvisioner::class)->provision($company);
        $cash = PaymentMethod::forCompany($company->id)->where('type', PaymentMethod::TYPE_CASH)->firstOrFail();
        $session = $this->openSession($company, $branch, $user);

        return [$company, $branch, $user, $cash, $session];
    }

    private function openSession(Company $company, Branch $branch, User $user): CashSession
    {
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'C'.uniqid(), 'name' => 'Caja', 'is_active' => true]);

        return CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'CAJA-'.uniqid(), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'currency_code' => 'CRC', 'opening_amount' => '0', 'opened_at' => now()]);
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => $name.'-'.$company->id, 'is_active' => true]);
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company, ['role_id' => $role->id]);
        $user->branches()->attach($branch);

        return $user;
    }

    private function product(Company $company): Product
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'allows_decimals' => false, 'is_active' => true]);

        return Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 500, 'sale_price' => 1000, 'tax_rate' => 0, 'track_inventory' => true, 'is_active' => true]);
    }

    private function stock(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function customer(Company $company): Customer
    {
        return Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true]);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
