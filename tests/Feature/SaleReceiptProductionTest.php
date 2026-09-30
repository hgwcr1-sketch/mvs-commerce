<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyPortalSetting;
use App\Models\LoyaltySetting;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\Loyalty\LoyaltyPortalAccessService;
use App\Services\Loyalty\LoyaltySaleReceiptService;
use App\Services\Sales\SaleReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaleReceiptProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_supports_80mm_58mm_and_large_professional_formats(): void
    {
        [$company, $branch, $user, $sale] = $this->context();
        foreach (['80mm', '58mm', 'letter'] as $format) {
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->get(route('pos.receipt', $sale).'?format='.$format)->assertOk()
                ->assertSee('data-receipt-format="'.$format.'"', false)->assertSee('TOTAL')->assertSee('grand', false)
                ->assertSee($company->trade_name)->assertSee($branch->name);
        }
    }

    public function test_exact_cash_payment_keeps_received_and_zero_change_and_customer_identification(): void
    {
        [$company, $branch, $user, $sale] = $this->context();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente identificado', 'identification' => '123456789', 'is_active' => true]);
        $sale->update(['customer_id' => $customer->id]);
        $sale->payments()->update(['received_amount' => '1017.0000', 'change_amount' => '0.0000']);
        $data = app(SaleReceiptService::class)->buildReceiptData($sale->fresh());
        $this->assertSame('1.017', $data->payments[0]['received_amount']);
        $this->assertSame('0', $data->payments[0]['change_amount']);
        foreach (['58', '80'] as $width) {
            $ticket = app(\App\Services\MvsPrint\EscPosSaleTicket::class)->build($data, $width);
            $text = collect($ticket['lines'])->pluck('value')->implode("\n");
            $this->assertStringContainsString('Recibido: ₡1.017', $text);
            $this->assertStringContainsString('Vuelto:   ₡0', $text);
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->get(route('pos.receipt', $sale).'?format='.$width.'mm')->assertOk()->assertSee('123456789');
        }
    }

    public function test_pdf_reuses_the_same_receipt_and_downloads_without_action_controls(): void
    {
        [$company, $branch, $user, $sale] = $this->context();

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.receipt.pdf', $sale).'?format=letter');

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('comprobante-'.$sale->sale_number.'.pdf', $response->headers->get('content-disposition'));
    }

    public function test_branch_configuration_selects_default_format_and_optional_direct_print(): void
    {
        [$company, $branch, $user, $sale] = $this->context();
        $payload = ['name' => $branch->name, 'code' => $branch->code, 'phone' => '2222-2222', 'address' => 'Centro', 'is_active' => 1, 'receipt_format' => '58mm', 'receipt_auto_print' => 1];

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->put(route('branches.update', $branch), $payload)->assertRedirect();
        $this->get(route('pos.receipt', $sale))->assertOk()->assertSee('data-receipt-format="58mm"', false)
            ->assertSee("window.addEventListener('load',()=>window.print())", false);
    }

    public function test_receipts_enforce_company_branch_module_and_existing_permissions(): void
    {
        [$company, $branch, $creator, $sale] = $this->context();
        $otherBranch = Branch::create(['company_id' => $company->id, 'name' => 'Otra', 'code' => 'OTRA', 'is_active' => true]);
        $creator->branches()->attach($otherBranch->id);

        $this->actingAs($creator)->withSession($this->activeSession($company, $otherBranch))->get(route('pos.receipt', $sale))->assertNotFound();
        $viewer = $this->user($company, $branch, ['ventas.ver']);
        $this->actingAs($viewer)->withSession($this->activeSession($company, $branch))->get(route('pos.receipt', $sale))->assertOk();
        $unauthorized = $this->user($company, $branch, []);
        $this->actingAs($unauthorized)->withSession($this->activeSession($company, $branch))->get(route('pos.receipt', $sale))->assertForbidden();
        $company->modules()->create(['module_key' => 'sales', 'is_enabled' => false]);
        $this->actingAs($creator)->withSession($this->activeSession($company, $branch))->get(route('pos.receipt', $sale))->assertForbidden();
    }

    public function test_sales_history_keeps_reprint_access_to_the_production_receipt(): void
    {
        [$company, $branch, $user, $sale] = $this->context();
        $viewer = $this->user($company, $branch, ['ventas.ver']);

        $this->actingAs($viewer)->withSession($this->activeSession($company, $branch))->get(route('ventas.index'))
            ->assertOk()->assertSee(route('pos.receipt', $sale), false)->assertSee('Reimprimir');
    }

    public function test_all_receipt_formats_use_persisted_loyalty_movement_snapshots(): void
    {
        [$company, $branch, $user, $sale] = $this->context();
        $this->attachLoyalty($sale, $company, $branch, $user);

        foreach (['80mm', '58mm', 'letter'] as $format) {
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->get(route('pos.receipt', $sale).'?format='.$format)
                ->assertOk()->assertSee('Fidelización')->assertSee('Puntos ganados')
                ->assertSee('+50,00')->assertSee('-200,00')->assertSee('1.000,00')->assertSee('850,00')
                ->assertDontSee('QR general del Portal');
        }
    }

    public function test_receipt_without_customer_does_not_show_loyalty_section(): void
    {
        [$company, $branch, $user, $sale] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.receipt', $sale))->assertOk()->assertDontSee('Puntos ganados');
    }

    public function test_reprint_and_pdf_do_not_mutate_kardex_and_reflect_void_adjustment_balance(): void
    {
        [$company, $branch, $user, $sale] = $this->context();
        [$account, $last] = $this->attachLoyalty($sale, $company, $branch, $user);
        LoyaltyMovement::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'loyalty_account_id' => $account->id, 'customer_id' => $sale->customer_id, 'user_id' => $user->id, 'type' => LoyaltyMovement::TYPE_VOID, 'points' => 200, 'balance_before' => 850, 'balance_after' => 1050, 'description' => 'Reversión', 'source_type' => Sale::class, 'source_id' => $sale->id, 'related_movement_id' => $last->id, 'event_key' => "sale:{$sale->id}:void", 'effective_at' => now()]);
        $count = LoyaltyMovement::count();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.receipt', $sale))->assertOk()->assertSee('saldo ajustado posteriormente')->assertSee('1.050,00');
        $this->get(route('pos.receipt.pdf', $sale))->assertOk();

        $this->assertSame($count, LoyaltyMovement::count());
        $this->assertSame('1050.0000', (string) LoyaltyMovement::latest('id')->first()->balance_after);
    }

    public function test_member_without_sale_movements_shows_current_balance_without_invitation(): void
    {
        [$company, $branch, $user, $sale] = $this->context();
        [$account] = $this->attachLoyalty($sale, $company, $branch, $user);
        LoyaltyMovement::query()->delete();
        foreach (SaleReceiptService::FORMATS as $format) {
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->get(route('pos.receipt', $sale).'?format='.$format)->assertOk()
                ->assertSee('Fidelización')->assertSee('Saldo actual')->assertSee('850,00')
                ->assertSee('+0,00')->assertSee('-0,00')->assertDontSee('Saldo anterior')
                ->assertDontSee('QR general del Portal');
        }
        $account->update(['is_active' => false]);
        $this->get(route('pos.receipt', $sale))->assertOk()->assertDontSee('Puntos ganados')
            ->assertDontSee('Escanea para registrarte');
        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_invitation_reuses_exact_general_company_qr_and_branding(): void
    {
        [$company, $branch, $user, $sale] = $this->context();
        $this->identifyNonMember($sale);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true]);
        LoyaltyPortalSetting::create(['company_id' => $company->id, 'portal_name' => 'Club de esta empresa']);
        [$other] = $this->context();
        LoyaltyPortalSetting::create(['company_id' => $other->id, 'portal_name' => 'Portal ajeno']);
        LoyaltyAccount::create(['company_id' => $other->id, 'customer_id' => $sale->customer_id, 'balance' => 9999, 'is_active' => true]);
        $expected = 'data:image/svg+xml;base64,'.base64_encode(app(LoyaltyPortalAccessService::class)
            ->qrSvg(route('loyalty.customer.login', $company)));
        $this->assertSame($expected, app(LoyaltySaleReceiptService::class)->forSale($sale)['qr_image']);
        foreach (SaleReceiptService::FORMATS as $format) {
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->get(route('pos.receipt', $sale).'?format='.$format)->assertOk()
                ->assertSee('Únete a nuestro programa de fidelidad')->assertSee('Escanea para registrarte')
                ->assertSee('Club de esta empresa')->assertSee($expected, false)
                ->assertDontSee('Portal ajeno')->assertDontSee('Puntos ganados');
        }
    }

    public function test_final_consumer_receives_exact_company_invitation_in_all_formats(): void
    {
        [$company, $branch, $user, $sale] = $this->context();
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true]);
        [$other] = $this->context();
        $qr = app(LoyaltyPortalAccessService::class);
        $expected = 'data:image/svg+xml;base64,'.base64_encode($qr->qrSvg(route('loyalty.customer.login', $company)));
        $otherQr = 'data:image/svg+xml;base64,'.base64_encode($qr->qrSvg(route('loyalty.customer.login', $other)));
        $this->assertSame('invitation', app(LoyaltySaleReceiptService::class)->forSale($sale)['kind']);
        foreach (SaleReceiptService::FORMATS as $format) {
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->get(route('pos.receipt', $sale).'?format='.$format)->assertOk()
                ->assertSee('Únete a nuestro programa de fidelidad')->assertSee('Escanea para registrarte')
                ->assertSee($expected, false)->assertDontSee($otherQr, false)->assertDontSee('Puntos ganados');
        }
    }

    public function test_invitation_is_hidden_for_final_consumer_and_non_member_when_loyalty_is_disabled(): void
    {
        [$company, $branch, $user, $sale] = $this->context();
        $setting = LoyaltySetting::create(['company_id' => $company->id, 'is_active' => false]);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.receipt', $sale))->assertOk()->assertDontSee('Escanea para registrarte')->assertDontSee('Puntos ganados');
        $this->identifyNonMember($sale);
        $setting->update(['is_active' => false]);
        $this->get(route('pos.receipt', $sale))->assertOk()->assertDontSee('Escanea para registrarte');
        $setting->update(['is_active' => true]);
        $company->modules()->create(['module_key' => 'loyalty', 'is_enabled' => false]);
        $this->get(route('pos.receipt', $sale))->assertOk()->assertDontSee('Escanea para registrarte');
        $sale->update(['customer_id' => null]);
        $this->get(route('pos.receipt', $sale))->assertOk()->assertDontSee('Escanea para registrarte')
            ->assertDontSee('QR general del Portal');
    }

    public function test_historical_points_survive_configuration_and_account_changes_and_returns(): void
    {
        [$company, $branch, $user, $sale] = $this->context();
        [$account, $last] = $this->attachLoyalty($sale, $company, $branch, $user);
        $service = app(LoyaltySaleReceiptService::class);
        $before = $service->forSale($sale);
        $setting = LoyaltySetting::where('company_id', $company->id)->firstOrFail();
        $setting->update(['is_active' => false, 'earning_percentage' => 99, 'point_value' => 10]);
        $this->assertNull($service->forSale($sale));
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.receipt', $sale))->assertOk()->assertDontSee('Puntos ganados')
            ->assertDontSee('QR general del Portal');
        $setting->update(['is_active' => true]);
        $account->update(['balance' => 9999, 'is_active' => false]);
        $this->assertSame($before, $service->forSale($sale));
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.receipt', $sale))->assertOk()->assertSee('850,00')->assertDontSee('Escanea para registrarte');
        LoyaltyMovement::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'loyalty_account_id' => $account->id, 'customer_id' => $sale->customer_id, 'type' => LoyaltyMovement::TYPE_RETURN, 'points' => -25, 'balance_before' => 850, 'balance_after' => 825, 'description' => 'Devolución', 'source_type' => SaleReturn::class, 'source_id' => 987, 'related_movement_id' => $last->id, 'event_key' => 'receipt:return', 'effective_at' => now()]);
        $this->get(route('pos.receipt', $sale))->assertOk()->assertSee('saldo ajustado posteriormente')->assertSee('825,00');
    }

    public function test_reprints_and_all_pdf_formats_preserve_all_loyalty_rows_and_execute_zero_writes(): void
    {
        [$company, $branch, $user, $sale] = $this->context();
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true]);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch));
        // Provision the unrelated tenant license before observing receipt requests.
        $company->license()->create(['status' => 'active', 'plan' => 'Prueba']);
        $tables = array_values(array_filter(DB::getSchemaBuilder()->getTableListing(),
            fn ($table) => str_starts_with($table, 'loyalty_') || $table === 'customer_one_time_tokens' || $table === 'customers'));
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => [
            'count' => DB::table($table)->count(),
            'rows' => DB::table($table)->orderBy('id')->get()->toJson(),
        ]])->all();
        // Include missing settings, existing branding/accesses, balance-only and historical receipts.
        foreach (['final_consumer', 'missing_portal', 'configured_portal', 'history', 'balance', 'inactive'] as $scenario) {
            if ($scenario === 'missing_portal') {
                $this->identifyNonMember($sale);
            } elseif ($scenario === 'configured_portal') {
                LoyaltyPortalSetting::create(['company_id' => $company->id, 'portal_name' => 'Mi club']);
                DB::table('loyalty_portal_accesses')->insert(['company_id' => $company->id, 'customer_id' => $sale->customer_id, 'token_hash' => hash('sha256', 'existing'), 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($scenario === 'history') {
                [$account] = $this->attachLoyalty($sale, $company, $branch, $user);
            } elseif ($scenario === 'balance') {
                LoyaltyMovement::query()->delete();
            } elseif ($scenario === 'inactive') {
                $account->update(['is_active' => false]);
            }
            $before = $snapshot();
            DB::enableQueryLog();
            DB::flushQueryLog();
            foreach (SaleReceiptService::FORMATS as $format) {
                for ($repeat = 0; $repeat < 2; $repeat++) {
                    $response = $this->get(route('pos.receipt', $sale).'?format='.$format)->assertOk();
                    if (in_array($scenario, ['final_consumer', 'missing_portal'], true)) {
                        $response->assertSee($company->trade_name)->assertSee('Escanea para registrarte');
                    }
                }
                $this->get(route('pos.receipt.pdf', $sale).'?format='.$format)->assertOk()->assertHeader('content-type', 'application/pdf');
            }
            $writes = collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query['query']))->all();
            DB::disableQueryLog();
            $this->assertSame([], $writes, $scenario.' must execute ZERO writes');
            $this->assertSame($before, $snapshot(), $scenario.' must preserve counts and every stored field');
        }
    }

    private function identifyNonMember(Sale $sale): void
    {
        $customer = Customer::create(['company_id' => $sale->company_id, 'customer_type' => 'individual', 'name' => 'Cliente identificado', 'is_active' => true]);
        $sale->update(['customer_id' => $customer->id]);
    }

    private function attachLoyalty(Sale $sale, Company $company, Branch $branch, User $user): array
    {
        LoyaltySetting::firstOrCreate(['company_id' => $company->id], ['is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente fiel', 'is_active' => true]);
        $sale->update(['customer_id' => $customer->id]);
        $account = LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => 850, 'total_earned' => 50, 'total_redeemed' => 200, 'is_active' => true]);
        LoyaltyMovement::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'loyalty_account_id' => $account->id, 'customer_id' => $customer->id, 'user_id' => $user->id, 'type' => LoyaltyMovement::TYPE_REDEMPTION, 'points' => -200, 'balance_before' => 1000, 'balance_after' => 800, 'description' => 'Canje', 'source_type' => Sale::class, 'source_id' => $sale->id, 'event_key' => "sale:{$sale->id}:redeem", 'effective_at' => now()]);
        $earned = LoyaltyMovement::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'loyalty_account_id' => $account->id, 'customer_id' => $customer->id, 'user_id' => $user->id, 'type' => LoyaltyMovement::TYPE_PURCHASE, 'points' => 50, 'balance_before' => 800, 'balance_after' => 850, 'description' => 'Ganancia', 'source_type' => Sale::class, 'source_id' => $sale->id, 'event_key' => "sale:{$sale->id}:earn", 'effective_at' => now()]);

        return [$account, $earned];
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Comercio MVS', 'legal_name' => 'Comercio MVS S.A.', 'identification_number' => '3101000000', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = $this->user($company, $branch, ['pos.acceder', 'ventas.crear', 'ventas.ver', 'configuracion.editar']);
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'sale_number' => 'POS-P04-001', 'document_type' => 'electronic_ticket', 'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 1000, 'discount_total' => 100, 'tax_total' => 117, 'rounding_total' => 0, 'total' => 1017, 'paid_total' => 1017, 'balance_due' => 0, 'completed_at' => now()]);
        DB::table('sale_items')->insert(['sale_id' => $sale->id, 'product_code' => 'P01', 'description' => 'Producto', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'discount_total' => 100, 'subtotal' => 900, 'tax_rate' => 13, 'tax_total' => 117, 'total' => 1017, 'unit_cost' => 500, 'created_at' => now(), 'updated_at' => now()]);
        $method = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash', 'name' => 'Efectivo', 'type' => 'cash', 'affects_cash' => true, 'allows_change' => true, 'requires_reference' => false, 'is_active' => true]);
        DB::table('sale_payments')->insert(['sale_id' => $sale->id, 'payment_method_id' => $method->id, 'created_by' => $user->id, 'status' => 'completed', 'amount' => 1017, 'received_amount' => 1200, 'change_amount' => 183, 'created_at' => now(), 'updated_at' => now()]);

        return [$company, $branch, $user, $sale];
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Ventas', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
