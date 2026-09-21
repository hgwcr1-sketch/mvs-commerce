<?php

namespace Tests\Feature;

use App\Models\{Branch, CashRegister, CashSession, Company, PaymentMethod, Permission, Product, ProductCategory, Role, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PosPaymentMethodVisualTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_payment_method_buttons_are_gold_and_enabled_while_balance_pending(): void
    {
        [$company, $branch, $user] = $this->context();
        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))->assertOk()
            ->assertSee('paymentMethodDisabled(method)', false)
            ->assertSee('paymentMethodClasses(method).button', false)
            ->assertSee('border-primary bg-primary text-black hover:bg-primary-hover hover:border-primary', false)
            ->assertSee('border-slate-300 bg-slate-100 text-slate-500', false)
            ->assertDontSee('border-slate-300 bg-white text-slate-700 hover:bg-slate-50 hover:border-slate-400', false);

        $process = new Process(['node', base_path('tests/js/pos-payment-methods.cjs')]);
        $process->setInput($response->getContent())->mustRun();
        $this->assertStringContainsString('Payment method buttons UI OK', $process->getOutput());
    }

    private function context(string $name = 'Visual'): array
    {
        $company = Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.$company->id, 'is_active' => true]);
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Visualista', 'is_active' => true]);
        foreach (['pos.acceder', 'ventas.crear', 'pos.aplicar_descuento', 'pos.cambiar_precio'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]));
        }
        $user->companies()->attach($company, ['role_id' => $role->id]);
        $user->branches()->attach($branch);

        foreach ([
            ['code' => 'cash', 'name' => 'Efectivo', 'type' => PaymentMethod::TYPE_CASH, 'allows_change' => true, 'requires_reference' => false],
            ['code' => 'card', 'name' => 'Tarjeta', 'type' => PaymentMethod::TYPE_CARD, 'allows_change' => false, 'requires_reference' => true],
            ['code' => 'sinpe', 'name' => 'SINPE', 'type' => PaymentMethod::TYPE_SINPE, 'allows_change' => false, 'requires_reference' => true],
            ['code' => 'credit', 'name' => 'Crédito', 'type' => PaymentMethod::TYPE_CREDIT, 'allows_change' => false, 'requires_reference' => false],
        ] as $method) {
            PaymentMethod::create(array_merge(['company_id' => $company->id, 'is_active' => true, 'affects_cash' => $method['type'] === PaymentMethod::TYPE_CASH], $method));
        }

        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'C', 'name' => 'Caja', 'is_active' => true]);
        CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'V-1', 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'currency_code' => 'CRC', 'opening_amount' => '0', 'opened_at' => now()]);

        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General', 'slug' => 'general-'.$company->id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$company->id, 'is_active' => true]);
        Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto', 'internal_code' => 'P-'.$company->id, 'cost' => 500, 'sale_price' => 42827, 'tax_rate' => 0, 'track_inventory' => false, 'is_active' => true]);

        return [$company, $branch, $user];
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}