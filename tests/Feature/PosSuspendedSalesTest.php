<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\CompanyCashSetting;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SuspendedSale;
use App\Models\Unit;
use App\Models\User;
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosSuspendedSalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspends_final_consumer_and_customer_without_touching_sales_inventory_or_pos_sequence(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company, true, ['sale_price' => 10, 'tax_rate' => 13]);
        $customer = $this->customer($company);
        $this->stock($branch, $product, 0);

        $this->suspend($user, $company, $branch, $product)->assertCreated()->assertJsonPath('suspended_sale.suspension_number', 'SUSP-000001');
        $this->suspend($user, $company, $branch, $product, $customer->id)->assertCreated()->assertJsonPath('suspended_sale.suspension_number', 'SUSP-000002');

        $this->assertDatabaseCount('suspended_sales', 2);
        $this->assertDatabaseCount('suspended_sale_items', 2);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertEquals(0, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseHas('company_sequences', ['company_id' => $company->id, 'name' => 'pos_suspension', 'current_value' => 2]);
        $this->assertDatabaseMissing('company_sequences', ['company_id' => $company->id, 'name' => 'pos_sale']);
        $sale = SuspendedSale::firstOrFail();
        $this->assertSame('10.0000', $sale->estimated_subtotal);
        $this->assertSame('1.3000', $sale->estimated_tax_total);
        $this->assertSame('-0.3000', $sale->estimated_rounding_total);
        $this->assertSame('11.0000', $sale->estimated_total);
    }

    public function test_listing_is_scoped_and_permissions_control_other_cashiers(): void
    {
        [$company, $branch, $owner] = $this->context();
        $other = $this->user($company, $branch, ['pos.acceder', 'ventas.crear']);
        $viewer = $this->user($company, $branch, ['pos.acceder', 'ventas.crear', 'ventas.ver']);
        $product = $this->product($company, false);
        $this->suspend($owner, $company, $branch, $product);
        $this->suspend($other, $company, $branch, $product);

        $this->actingAs($owner)->withSession($this->activeSession($company, $branch))->getJson(route('pos.suspended.index'))->assertJsonCount(1);
        $this->actingAs($viewer)->withSession($this->activeSession($company, $branch))->getJson(route('pos.suspended.index'))->assertJsonCount(2);

        $otherBranch = $this->branch($company, 'Otra');
        $owner->branches()->attach($otherBranch->id);
        $this->actingAs($owner)->withSession($this->activeSession($company, $otherBranch))->getJson(route('pos.suspended.index'))->assertJsonCount(0);
        [$foreignCompany, $foreignBranch, $foreign] = $this->context('Ajena');
        $this->actingAs($foreign)->withSession($this->activeSession($foreignCompany, $foreignBranch))->getJson(route('pos.suspended.index'))->assertJsonCount(0);
    }

    public function test_claim_requires_edit_permission_for_other_cashier_and_prevents_simultaneous_claim(): void
    {
        [$company, $branch, $owner] = $this->context();
        $product = $this->product($company, false);
        $id = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        $other = $this->user($company, $branch, ['pos.acceder', 'ventas.crear']);
        $editor = $this->user($company, $branch, ['pos.acceder', 'ventas.crear', 'ventas.editar']);

        $this->recover($other, $company, $branch, $id)->assertForbidden();
        $claim = $this->recover($editor, $company, $branch, $id)->assertOk();
        $this->recover($owner, $company, $branch, $id)->assertConflict();
        $this->recover($editor, $company, $branch, $id, $claim->json('recovery_token'))->assertOk();
        $this->assertDatabaseCount('suspended_sales', 1);
    }

    public function test_recovery_lease_expires_after_fifteen_minutes_and_record_survives_browser_failure(): void
    {
        [$company, $branch, $owner] = $this->context();
        $product = $this->product($company, false);
        $id = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        $first = $this->recover($owner, $company, $branch, $id)->assertOk();
        $this->assertDatabaseHas('suspended_sales', ['id' => $id, 'status' => 'recovering']);

        $other = $this->user($company, $branch, ['pos.acceder', 'ventas.crear', 'ventas.editar']);
        $this->travel(14)->minutes();
        $this->recover($other, $company, $branch, $id)->assertConflict();
        $this->travel(2)->minutes();
        $second = $this->recover($other, $company, $branch, $id)->assertOk();
        $this->assertNotSame($first->json('recovery_token'), $second->json('recovery_token'));
        $this->assertDatabaseCount('suspended_sales', 1);
    }

    public function test_recovery_revalidates_customer_product_price_tax_and_stock(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, true, ['sale_price' => 100, 'tax_rate' => 13]);
        $this->stock($branch, $product, 1);
        $id = $this->suspend($user, $company, $branch, $product, $customer->id, 2)->json('suspended_sale.id');
        $customer->update(['is_active' => false]);
        $product->update(['sale_price' => 125, 'tax_rate' => 4]);

        $payload = $this->recover($user, $company, $branch, $id)->assertOk();
        $payload->assertJsonPath('customer', null)->assertJsonPath('customer_invalid', true)
            ->assertJsonPath('items.0.price_changed', true)->assertJsonPath('items.0.tax_changed', true)
            ->assertJsonPath('items.0.stock_insufficient', true)->assertJsonPath('can_checkout', false)
            ->assertJsonPath('items.0.price', '125.00');

        $product->delete();
        $payload = $this->recover($user, $company, $branch, $id, $payload->json('recovery_token'))->assertOk();
        $payload->assertJsonPath('items.0.unavailable', true)->assertJsonPath('can_checkout', false);
    }

    public function test_checkout_marks_recovered_atomically_and_duplicate_checkout_does_not_repeat_inventory(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, true, ['sale_price' => 1000, 'tax_rate' => 0]);
        $this->stock($branch, $product, 3);
        $id = $this->suspend($user, $company, $branch, $product)->json('suspended_sale.id');
        $claim = $this->recover($user, $company, $branch, $id)->assertOk();
        $checkoutToken = (string) Str::uuid();
        $payload = $this->checkoutPayload($cash, $product, $id, $claim->json('recovery_token'), $checkoutToken);

        $first = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), $payload)->assertOk();
        $second = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), $payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame($first->json('sale_id'), $second->json('sale_id'));
        $this->assertDatabaseHas('suspended_sales', ['id' => $id, 'status' => 'recovered', 'recovered_sale_id' => $first->json('sale_id')]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertEquals(2, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    public function test_failed_checkout_rolls_back_and_leaves_suspension_recoverable(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company, true, ['sale_price' => 1000, 'tax_rate' => 0]);
        $this->stock($branch, $product, 1);
        $id = $this->suspend($user, $company, $branch, $product, null, 2)->json('suspended_sale.id');
        $claim = $this->recover($user, $company, $branch, $id)->assertOk();
        $payload = $this->checkoutPayload($cash, $product, $id, $claim->json('recovery_token'), (string) Str::uuid(), 2);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), $payload)->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('suspended_sales', ['id' => $id, 'status' => 'recovering', 'recovered_sale_id' => null]);
        $this->recover($user, $company, $branch, $id, $claim->json('recovery_token'))->assertOk();
    }

    public function test_cancel_requires_permission_reason_and_keeps_audit_and_ui_controls_exist(): void
    {
        [$company, $branch, $owner] = $this->context();
        $product = $this->product($company, false);
        $id = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        $this->actingAs($owner)->withSession($this->activeSession($company, $branch))->postJson(route('pos.suspended.cancel', $id), [])->assertForbidden();
        $canceller = $this->user($company, $branch, ['pos.acceder', 'ventas.crear', 'ventas.anular']);
        $this->actingAs($canceller)->withSession($this->activeSession($company, $branch))->postJson(route('pos.suspended.cancel', $id), [])->assertUnprocessable();
        $this->actingAs($canceller)->withSession($this->activeSession($company, $branch))->postJson(route('pos.suspended.cancel', $id), ['reason' => 'Cliente desistió'])->assertOk();
        $this->assertDatabaseHas('suspended_sales', ['id' => $id, 'status' => 'cancelled', 'cancelled_by' => $canceller->id, 'cancellation_reason' => 'Cliente desistió']);
        $this->assertDatabaseCount('suspended_sales', 1);
        $this->actingAs($canceller)->withSession($this->activeSession($company, $branch))->get(route('pos.index'))->assertOk()->assertSee('Suspender')->assertSee('Suspendidas')->assertSee('Ventas suspendidas');
    }

    public function test_pos_fetch_errors_are_json_for_forbidden_not_found_validation_and_conflict(): void
    {
        [$company, $branch, $owner] = $this->context();
        $product = $this->product($company, false);
        $id = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');

        $withoutPermission = $this->user($company, $branch, ['pos.acceder']);
        $this->actingAs($withoutPermission)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.suspended.recover', $id))->assertForbidden()->assertHeader('Content-Type', 'application/json');
        $this->actingAs($owner)->withSession($this->activeSession($company, $branch))
            ->postJson('/pos/suspendidas/999999/recuperar')->assertNotFound()->assertHeader('Content-Type', 'application/json');
        $this->actingAs($owner)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.suspended.release', $id), [])->assertUnprocessable()->assertJsonStructure(['message', 'errors']);

        SuspendedSale::whereKey($id)->update(['status' => SuspendedSale::STATUS_CANCELLED]);
        $this->actingAs($owner)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.suspended.recover', $id))->assertConflict()
            ->assertJsonPath('message', 'Esta venta suspendida fue cancelada y no puede recuperarse.');
    }

    public function test_recovered_cancelled_and_other_user_conflicts_return_specific_json_messages(): void
    {
        [$company, $branch, $owner] = $this->context();
        $product = $this->product($company, false);
        $cancelled = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        SuspendedSale::whereKey($cancelled)->update(['status' => SuspendedSale::STATUS_CANCELLED]);
        $this->recover($owner, $company, $branch, $cancelled)->assertConflict()->assertJsonPath('message', 'Esta venta suspendida fue cancelada y no puede recuperarse.');

        $recovered = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        SuspendedSale::whereKey($recovered)->update(['status' => SuspendedSale::STATUS_RECOVERED]);
        $this->recover($owner, $company, $branch, $recovered)->assertConflict()->assertJsonPath('message', 'Esta venta suspendida ya fue cobrada.');

        $leased = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        $this->recover($owner, $company, $branch, $leased)->assertOk();
        $editor = $this->user($company, $branch, ['pos.acceder', 'ventas.crear', 'ventas.editar']);
        $this->recover($editor, $company, $branch, $leased)->assertConflict()->assertJsonPath('message', 'Esta venta suspendida está siendo recuperada por otro usuario.');
    }

    public function test_owner_can_release_idempotently_without_changing_lines_or_estimates(): void
    {
        [$company, $branch, $owner] = $this->context();
        $product = $this->product($company, false, ['sale_price' => 123, 'tax_rate' => 13]);
        $id = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        $before = SuspendedSale::with('items')->findOrFail($id);
        $claim = $this->recover($owner, $company, $branch, $id)->assertOk();
        $payload = ['recovery_token' => $claim->json('recovery_token')];

        $this->release($owner, $company, $branch, $id, $payload)->assertOk();
        $this->release($owner, $company, $branch, $id, $payload)->assertOk();
        $after = SuspendedSale::with('items')->findOrFail($id);
        $this->assertSame(SuspendedSale::STATUS_SUSPENDED, $after->status);
        $this->assertNull($after->recovery_token);
        $this->assertNull($after->recovery_by);
        $this->assertSame($before->estimated_total, $after->estimated_total);
        $this->assertSame($before->items->first()->estimated_total, $after->items->first()->estimated_total);
        $this->assertCount(1, $after->items);
    }

    public function test_other_user_and_wrong_token_cannot_release_and_terminal_contains_safe_release_flow(): void
    {
        [$company, $branch, $owner] = $this->context();
        $product = $this->product($company, false);
        $id = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        $claim = $this->recover($owner, $company, $branch, $id)->assertOk();
        $other = $this->user($company, $branch, ['pos.acceder', 'ventas.crear']);

        $this->release($other, $company, $branch, $id, ['recovery_token' => $claim->json('recovery_token')])->assertConflict();
        $this->release($owner, $company, $branch, $id, ['recovery_token' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseHas('suspended_sales', ['id' => $id, 'status' => 'recovering', 'recovery_token' => $claim->json('recovery_token')]);

        $this->actingAs($owner)->withSession($this->activeSession($company, $branch))->get(route('pos.index'))
            ->assertOk()->assertSee('Limpiar carrito')->assertSee('releaseCurrentRecovery')
            ->assertSee('await this.releaseCurrentRecovery()', false)->assertSee('readFetchResponse')
            ->assertSee("contentType.includes('application/json')", false)
            ->assertSee('La sesión venció. Recargue la página e inténtelo nuevamente.');
    }

    public function test_active_list_excludes_cancelled_and_recovered_records(): void
    {
        [$company, $branch, $owner] = $this->context();
        $product = $this->product($company, false);
        $active = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        $cancelled = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        $recovered = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        SuspendedSale::whereKey($cancelled)->update(['status' => SuspendedSale::STATUS_CANCELLED]);
        SuspendedSale::whereKey($recovered)->update(['status' => SuspendedSale::STATUS_RECOVERED]);

        $response = $this->actingAs($owner)->withSession($this->activeSession($company, $branch))->getJson(route('pos.suspended.index'))->assertOk()->assertJsonCount(1);
        $this->assertSame($active, $response->json('0.id'));
    }

    public function test_recovered_cart_resuspends_same_record_with_current_snapshot_and_no_side_effects(): void
    {
        [$company, $branch, $owner] = $this->context();
        $oldProduct = $this->product($company, true, ['sale_price' => 100, 'tax_rate' => 13]);
        $newProduct = $this->product($company, true, ['sale_price' => 200, 'tax_rate' => 4]);
        $customer = $this->customer($company);
        $this->stock($branch, $oldProduct, 5);
        $this->stock($branch, $newProduct, 7);
        $created = $this->suspend($owner, $company, $branch, $oldProduct)->assertCreated();
        $id = $created->json('suspended_sale.id');
        $number = $created->json('suspended_sale.suspension_number');
        $claim = $this->recover($owner, $company, $branch, $id)->assertOk();
        $newProduct->update(['sale_price' => 250, 'tax_rate' => 13]);

        $this->resuspend($owner, $company, $branch, $id, [
            'recovery_token' => $claim->json('recovery_token'), 'customer_id' => $customer->id,
            'items' => [['product_id' => $newProduct->id, 'quantity' => 1], ['product_id' => $newProduct->id, 'quantity' => 2]],
        ])->assertOk()->assertJsonPath('suspended_sale.id', $id)->assertJsonPath('suspended_sale.suspension_number', $number)
            ->assertJsonPath('message', "Venta {$number} actualizada y suspendida nuevamente.");

        $sale = SuspendedSale::with('items')->findOrFail($id);
        $this->assertSame(SuspendedSale::STATUS_SUSPENDED, $sale->status);
        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertNull($sale->recovery_token);
        $this->assertNull($sale->recovery_started_at);
        $this->assertNull($sale->recovery_by);
        $this->assertCount(1, $sale->items);
        $this->assertSame('3.0000', $sale->items->first()->quantity);
        $this->assertSame('250.0000', $sale->items->first()->estimated_unit_price);
        $this->assertSame('97.5000', $sale->estimated_tax_total);
        $this->assertSame('848.0000', $sale->estimated_total);
        $this->assertDatabaseCount('suspended_sales', 1);
        $this->assertDatabaseHas('company_sequences', ['company_id' => $company->id, 'name' => 'pos_suspension', 'current_value' => 1]);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertEquals(5, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $oldProduct->id)->value('stock'));
        $this->assertEquals(7, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $newProduct->id)->value('stock'));
    }

    public function test_resuspend_rejects_invalid_lease_user_company_branch_and_terminal_uses_update_endpoint(): void
    {
        [$company, $branch, $owner] = $this->context();
        $product = $this->product($company, false);
        $id = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        $claim = $this->recover($owner, $company, $branch, $id)->assertOk();
        $payload = ['recovery_token' => (string) Str::uuid(), 'customer_id' => null, 'items' => [['product_id' => $product->id, 'quantity' => 1]]];
        $this->resuspend($owner, $company, $branch, $id, $payload)->assertConflict()->assertJsonPath('message', 'La recuperación ya no es válida. Vuelva a abrir la venta suspendida.');

        $other = $this->user($company, $branch, ['pos.acceder', 'ventas.crear']);
        $payload['recovery_token'] = $claim->json('recovery_token');
        $this->resuspend($other, $company, $branch, $id, $payload)->assertConflict();
        $otherBranch = $this->branch($company, 'Otra');
        $owner->branches()->attach($otherBranch->id);
        $this->resuspend($owner, $company, $otherBranch, $id, $payload)->assertNotFound();
        [$foreignCompany, $foreignBranch, $foreign] = $this->context('Ajena');
        $this->resuspend($foreign, $foreignCompany, $foreignBranch, $id, $payload)->assertNotFound();
        $this->assertDatabaseHas('suspended_sales', ['id' => $id, 'status' => 'recovering', 'recovery_token' => $claim->json('recovery_token')]);

        $this->actingAs($owner)->withSession($this->activeSession($company, $branch))->get(route('pos.index'))->assertOk()
            ->assertSee('Volver a suspender')->assertSee('/volver-a-suspender', false)
            ->assertSee('recoveredCart ? { recovery_token: this.suspended.recoveryToken }', false)
            ->assertSee('this.clearSuspendedRecovery()', false);
    }

    public function test_resuspend_returns_specific_terminal_states_and_expired_lease_preserves_snapshot(): void
    {
        [$company, $branch, $owner] = $this->context();
        $product = $this->product($company, false);
        $payload = ['recovery_token' => (string) Str::uuid(), 'customer_id' => null, 'items' => [['product_id' => $product->id, 'quantity' => 2]]];
        $cancelled = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        SuspendedSale::whereKey($cancelled)->update(['status' => SuspendedSale::STATUS_CANCELLED]);
        $this->resuspend($owner, $company, $branch, $cancelled, $payload)->assertConflict()->assertJsonPath('message', 'Esta venta suspendida fue cancelada.');
        $recovered = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        SuspendedSale::whereKey($recovered)->update(['status' => SuspendedSale::STATUS_RECOVERED]);
        $this->resuspend($owner, $company, $branch, $recovered, $payload)->assertConflict()->assertJsonPath('message', 'Esta venta suspendida ya fue cobrada.');

        $expired = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        $claim = $this->recover($owner, $company, $branch, $expired)->assertOk();
        $before = SuspendedSale::with('items')->findOrFail($expired)->toArray();
        $this->travel(16)->minutes();
        $payload['recovery_token'] = $claim->json('recovery_token');
        $this->resuspend($owner, $company, $branch, $expired, $payload)->assertConflict()->assertJsonPath('message', 'La concesión de recuperación venció. Vuelva a abrir la venta suspendida.');
        $after = SuspendedSale::with('items')->findOrFail($expired)->toArray();
        $this->assertSame($before, $after);
    }

    public function test_resuspend_rolls_back_header_lines_and_lease_when_line_replacement_fails(): void
    {
        [$company, $branch, $owner] = $this->context();
        $product = $this->product($company, false);
        $id = $this->suspend($owner, $company, $branch, $product)->json('suspended_sale.id');
        $claim = $this->recover($owner, $company, $branch, $id)->assertOk();
        $before = SuspendedSale::with('items')->findOrFail($id)->toArray();
        DB::statement("CREATE TRIGGER fail_resuspend_lines BEFORE INSERT ON suspended_sale_items WHEN NEW.suspended_sale_id = {$id} BEGIN SELECT RAISE(ABORT, 'forced line failure'); END");

        $this->resuspend($owner, $company, $branch, $id, ['recovery_token' => $claim->json('recovery_token'), 'customer_id' => null, 'items' => [['product_id' => $product->id, 'quantity' => 2]]])->assertServerError();
        $after = SuspendedSale::with('items')->findOrFail($id)->toArray();
        $this->assertSame($before, $after);
    }

    private function context(string $name = 'Empresa'): array { $company = Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]); $branch = $this->branch($company, 'Principal'); $user = $this->user($company, $branch, ['pos.acceder', 'ventas.crear']); $settings = app(CompanyCashSettingsProvisioner::class)->provision($company); $settings->update(['session_mode' => CompanyCashSetting::SESSION_MODE_SHARED]); $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'REG-'.uniqid(), 'name' => 'Caja', 'is_active' => true]); CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'CAJA-'.uniqid(), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'opening_amount' => 0, 'opened_at' => now()]); $cash = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash-'.uniqid(), 'name' => 'Efectivo', 'type' => 'cash', 'is_active' => true, 'allows_change' => true]); return [$company, $branch, $user, $cash]; }
    private function branch(Company $company, string $name): Branch { return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => $name.'-'.$company->id, 'is_active' => true]); }
    private function user(Company $company, Branch $branch, array $permissions): User { $user = User::factory()->create(); $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]); foreach ($permissions as $name) { $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]); $role->permissions()->syncWithoutDetaching($permission); } $user->companies()->attach($company->id, ['role_id' => $role->id]); $user->branches()->attach($branch->id); return $user; }
    private function product(Company $company, bool $tracked, array $attributes = []): Product { $id = uniqid(); $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]); $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'allows_decimals' => false, 'is_active' => true]); return Product::create(array_merge(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 500, 'sale_price' => 1000, 'stock' => 0, 'tax_rate' => 13, 'track_inventory' => $tracked, 'is_active' => true], $attributes)); }
    private function customer(Company $company): Customer { return Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]); }
    private function stock(Branch $branch, Product $product, float $stock): void { DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock, 'created_at' => now(), 'updated_at' => now()]); }
    private function suspend(User $user, Company $company, Branch $branch, Product $product, ?int $customer = null, float $quantity = 1) { return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.suspended.store'), ['customer_id' => $customer, 'items' => [['product_id' => $product->id, 'quantity' => $quantity]]]); }
    private function recover(User $user, Company $company, Branch $branch, int $id, ?string $token = null) { return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.suspended.recover', $id), ['recovery_token' => $token]); }
    private function release(User $user, Company $company, Branch $branch, int $id, array $payload) { return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.suspended.release', $id), $payload); }
    private function resuspend(User $user, Company $company, Branch $branch, int $id, array $payload) { return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.suspended.resuspend', $id), $payload); }
    private function checkoutPayload(PaymentMethod $cash, Product $product, int $id, string $recoveryToken, string $checkoutToken, float $quantity = 1): array { $total = round((float) $product->sale_price * $quantity * (1 + (float) $product->tax_rate / 100), 0, PHP_ROUND_HALF_UP); return ['checkout_token' => $checkoutToken, 'suspended_sale_id' => $id, 'recovery_token' => $recoveryToken, 'customer_id' => null, 'payments' => [['payment_method_id' => $cash->id, 'amount' => $total, 'received_amount' => $total]], 'items' => [['product_id' => $product->id, 'quantity' => $quantity]]]; }
    private function activeSession(Company $company, Branch $branch): array { return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id]; }
}
