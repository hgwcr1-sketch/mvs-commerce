<?php

namespace Tests\Feature;

use App\Models\{Branch,CashRegister,CashSession,Company,Customer,Layaway,LayawayPayment,PaymentMethod,Permission,Product,ProductCategory,Role,Unit,User};
use App\Services\Cash\CashPaymentExpectedAmountService;
use App\Services\PaymentMethodProvisioner;
use App\Services\Sales\LayawayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LayawayMixedPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Company $c;
    private Branch $b;
    private User $u;
    private CashSession $s;
    private PaymentMethod $cash;
    private PaymentMethod $card;
    private PaymentMethod $sinpe;
    private PaymentMethod $credit;
    private Product $p;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = Company::create(['trade_name' => 'Mix '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'layaway_validity_days' => 30, 'layaway_alert_days' => 5, 'is_active' => true]);
        $this->b = Branch::create(['company_id' => $this->c->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $this->u = User::factory()->create();
        $role = Role::create(['company_id' => $this->c->id, 'name' => 'R'.uniqid(), 'is_active' => true]);
        foreach (['apartados.ver', 'apartados.crear', 'apartados.abonar', 'apartados.cancelar', 'apartados.entregar'] as $n) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $n], ['label' => $n, 'module' => 'Test', 'is_active' => true]));
        }
        $this->u->companies()->attach($this->c->id, ['role_id' => $role->id]);
        $this->u->branches()->attach($this->b->id);
        app(PaymentMethodProvisioner::class)->provision($this->c);
        $this->cash = PaymentMethod::forCompany($this->c->id)->where('type', 'cash')->firstOrFail();
        $this->card = PaymentMethod::forCompany($this->c->id)->where('type', 'card')->firstOrFail();
        $this->sinpe = PaymentMethod::forCompany($this->c->id)->where('type', 'sinpe')->firstOrFail();
        $this->credit = PaymentMethod::create(['company_id' => $this->c->id, 'name' => 'Crédito', 'code' => 'credit-x', 'type' => 'credit', 'affects_cash' => false, 'requires_reference' => false, 'is_active' => true]);
        $register = CashRegister::create(['company_id' => $this->c->id, 'branch_id' => $this->b->id, 'code' => 'C'.uniqid(), 'name' => 'Caja', 'is_active' => true]);
        $this->s = CashSession::create(['company_id' => $this->c->id, 'branch_id' => $this->b->id, 'cash_register_id' => $register->id, 'session_number' => 'CAJA-'.uniqid(), 'opened_by' => $this->u->id, 'status' => 'open', 'open_guard' => 'OPEN', 'opening_amount' => 0, 'opened_at' => now()]);
        $id = uniqid();
        $cat = ProductCategory::create(['company_id' => $this->c->id, 'name' => 'C'.$id, 'slug' => 'c'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $this->c->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u'.$id, 'is_active' => true]);
        $this->p = Product::create(['company_id' => $this->c->id, 'category_id' => $cat->id, 'unit_id' => $unit->id, 'name' => 'Producto', 'internal_code' => 'P'.$id, 'cost' => 500, 'sale_price' => 1000, 'tax_rate' => 0, 'track_inventory' => true, 'is_active' => true]);
        DB::table('branch_product')->insert(['branch_id' => $this->b->id, 'product_id' => $this->p->id, 'stock' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->customer = Customer::create(['company_id' => $this->c->id, 'name' => 'Cliente Mix', 'is_active' => true]);
    }

    private function ctx(): array
    {
        return ['active_company_id' => $this->c->id, 'active_branch_id' => $this->b->id];
    }

    private function storeLayaway(array $payments, ?string $initialAmount = null)
    {
        $initialAmount ??= (string) array_sum(array_map(fn ($p) => (float) $p['amount'], $payments));

        return $this->actingAs($this->u)->withSession($this->ctx())->post(route('apartados.store'), [
            'customer_id' => $this->customer->id,
            'items' => [['product_id' => $this->p->id, 'quantity' => 2]],
            'initial_amount' => $initialAmount,
            'payments' => $payments,
            'cash_session_id' => $this->s->id,
        ]);
    }

    private function pay(Layaway $layaway, array $payments, ?string $amount = null)
    {
        return $this->actingAs($this->u)->withSession($this->ctx())->post(route('apartados.payments.store', $layaway), [
            'payments' => $payments,
            'amount' => $amount,
            'cash_session_id' => $this->s->id,
        ]);
    }

    public function test_mixed_initial_payment_creates_n_lines_and_updates_balance_once(): void
    {
        $this->storeLayaway([
            ['payment_method_id' => $this->cash->id, 'amount' => '400', 'reference' => null, 'notes' => null],
            ['payment_method_id' => $this->card->id, 'amount' => '600', 'reference' => 'TARJ-1', 'notes' => null],
        ])->assertRedirect();

        $a = Layaway::firstOrFail();
        $this->assertSame('2000.0000', $a->total);
        $this->assertSame('1000.0000', $a->paid_total);
        $this->assertSame('1000.0000', $a->balance_due);
        $this->assertSame(2, LayawayPayment::where('layaway_id', $a->id)->count());
        $this->assertDatabaseHas('layaway_payments', ['payment_method_id' => $this->cash->id, 'amount' => '400.0000']);
        $this->assertDatabaseHas('layaway_payments', ['payment_method_id' => $this->card->id, 'amount' => '600.0000', 'reference' => 'TARJ-1']);
    }

    public function test_mixed_later_payment_updates_balance_once(): void
    {
        $this->storeLayaway([
            ['payment_method_id' => $this->cash->id, 'amount' => '200', 'reference' => null, 'notes' => null],
        ], '200');
        $a = Layaway::firstOrFail();

        $this->pay($a, [
            ['payment_method_id' => $this->sinpe->id, 'amount' => '500', 'reference' => 'SINPE-9', 'notes' => null],
            ['payment_method_id' => $this->cash->id, 'amount' => '300', 'reference' => null, 'notes' => null],
        ], '800')->assertRedirect();

        $a->refresh();
        $this->assertSame('1000.0000', $a->paid_total);
        $this->assertSame('1000.0000', $a->balance_due);
        $this->assertSame(3, LayawayPayment::where('layaway_id', $a->id)->count());
    }

    public function test_wrong_sum_is_rejected(): void
    {
        $this->storeLayaway([
            ['payment_method_id' => $this->cash->id, 'amount' => '400', 'reference' => null, 'notes' => null],
            ['payment_method_id' => $this->card->id, 'amount' => '500', 'reference' => 'T', 'notes' => null],
        ], '1000')->assertSessionHasErrors('payments');
        $this->assertSame(0, Layaway::count());
    }

    public function test_overpayment_is_rejected(): void
    {
        $this->storeLayaway([
            ['payment_method_id' => $this->cash->id, 'amount' => '200', 'reference' => null, 'notes' => null],
        ], '200');
        $a = Layaway::firstOrFail();

        $this->pay($a, [
            ['payment_method_id' => $this->cash->id, 'amount' => '2000', 'reference' => null, 'notes' => null],
        ], '2000')->assertSessionHasErrors('payments');
        $this->assertSame('200.0000', $a->fresh()->paid_total);
    }

    public function test_repeated_method_is_rejected(): void
    {
        $this->storeLayaway([
            ['payment_method_id' => $this->cash->id, 'amount' => '200', 'reference' => null, 'notes' => null],
            ['payment_method_id' => $this->cash->id, 'amount' => '200', 'reference' => null, 'notes' => null],
        ], '400')->assertSessionHasErrors('payments');
    }

    public function test_reference_required_when_method_requires_it(): void
    {
        $this->storeLayaway([
            ['payment_method_id' => $this->card->id, 'amount' => '500', 'reference' => null, 'notes' => null],
        ], '500')->assertSessionHasErrors('payments.0.reference');

        $this->storeLayaway([
            ['payment_method_id' => $this->sinpe->id, 'amount' => '500', 'reference' => 'OK-1', 'notes' => null],
        ], '500')->assertRedirect();
    }

    public function test_inactive_or_forbidden_method_is_rejected(): void
    {
        $this->storeLayaway([
            ['payment_method_id' => $this->credit->id, 'amount' => '500', 'reference' => null, 'notes' => null],
        ], '500')->assertSessionHasErrors('payments.0.payment_method_id');

        $inactive = PaymentMethod::create(['company_id' => $this->c->id, 'name' => 'Inactivo', 'code' => 'inact', 'type' => 'other', 'affects_cash' => false, 'requires_reference' => false, 'is_active' => false]);
        $this->storeLayaway([
            ['payment_method_id' => $inactive->id, 'amount' => '500', 'reference' => null, 'notes' => null],
        ], '500')->assertSessionHasErrors('payments.0.payment_method_id');
    }

    public function test_cash_method_requires_session(): void
    {
        $this->actingAs($this->u)->withSession($this->ctx())->post(route('apartados.store'), [
            'customer_id' => $this->customer->id,
            'items' => [['product_id' => $this->p->id, 'quantity' => 2]],
            'initial_amount' => '500',
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => '500', 'reference' => null, 'notes' => null]],
        ])->assertSessionHasErrors('cash_session_id');
        $this->assertSame(0, Layaway::count());
    }

    public function test_legacy_single_payment_still_works(): void
    {
        $this->actingAs($this->u)->withSession($this->ctx())->post(route('apartados.store'), [
            'customer_id' => $this->customer->id,
            'items' => [['product_id' => $this->p->id, 'quantity' => 2]],
            'initial_amount' => 200,
            'payment_method_id' => $this->cash->id,
            'cash_session_id' => $this->s->id,
        ])->assertRedirect();

        $a = Layaway::firstOrFail();
        $this->assertSame('1800.0000', $a->balance_due);
        $this->assertSame(1, LayawayPayment::where('layaway_id', $a->id)->count());

        $this->actingAs($this->u)->withSession($this->ctx())->post(route('apartados.payments.store', $a), [
            'amount' => 300,
            'payment_method_id' => $this->card->id,
            'reference' => 'LEGACY-1',
        ])->assertRedirect();
        $this->assertSame('1500.0000', $a->fresh()->balance_due);
    }

    public function test_cash_breakdown_reflects_each_method_part(): void
    {
        $this->storeLayaway([
            ['payment_method_id' => $this->cash->id, 'amount' => '400', 'reference' => null, 'notes' => null],
            ['payment_method_id' => $this->card->id, 'amount' => '600', 'reference' => 'TARJ-X', 'notes' => null],
        ]);

        $breakdown = app(CashPaymentExpectedAmountService::class)->breakdown($this->s);
        $this->assertEquals(400, (float) $breakdown[$this->cash->id]['layaways']);
        $this->assertEquals(600, (float) $breakdown[$this->card->id]['layaways']);
    }

    public function test_show_page_lists_each_mixed_line(): void
    {
        $this->storeLayaway([
            ['payment_method_id' => $this->cash->id, 'amount' => '400', 'reference' => null, 'notes' => 'Efectivo parcial'],
            ['payment_method_id' => $this->sinpe->id, 'amount' => '600', 'reference' => 'SINPE-MIX', 'notes' => null],
        ]);
        $a = Layaway::firstOrFail();

        $this->actingAs($this->u)->withSession($this->ctx())->get(route('apartados.show', $a))
            ->assertOk()
            ->assertSee('Efectivo parcial')
            ->assertSee('SINPE-MIX');
    }
}
