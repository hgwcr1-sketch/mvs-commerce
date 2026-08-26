<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
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

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Comercio MVS', 'legal_name' => 'Comercio MVS S.A.', 'identification_number' => '3101000000', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = $this->user($company, $branch, ['pos.acceder', 'ventas.crear', 'ventas.ver']);
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
