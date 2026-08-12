<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_permission_lists_only_active_company_methods(): void
    {
        $company = $this->company('Empresa uno');
        $otherCompany = $this->company('Empresa dos');
        $user = $this->userWithPermission($company);
        $this->method($company, ['name' => 'Método visible', 'code' => 'visible']);
        $this->method($otherCompany, ['name' => 'Método ajeno', 'code' => 'ajeno']);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('settings.pos.payment-methods.index'))
            ->assertOk()
            ->assertSee('Método visible')
            ->assertDontSee('Método ajeno');
    }

    public function test_user_without_permission_receives_forbidden_by_direct_url(): void
    {
        $company = $this->company('Empresa sin permiso');
        $user = $this->userWithPermission($company, false);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('settings.pos.payment-methods.index'))
            ->assertForbidden();
    }

    public function test_can_create_paypal_as_other_for_active_company(): void
    {
        $company = $this->company('Empresa PayPal');
        $user = $this->userWithPermission($company);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->post(route('settings.pos.payment-methods.store'), $this->payload([
                'name' => 'PayPal',
                'code' => 'Pay Pal',
                'type' => PaymentMethod::TYPE_OTHER,
            ]))
            ->assertRedirect(route('settings.pos.payment-methods.index'));

        $this->assertDatabaseHas('payment_methods', [
            'company_id' => $company->id,
            'name' => 'PayPal',
            'code' => 'pay_pal',
            'type' => PaymentMethod::TYPE_OTHER,
            'is_system' => false,
        ]);
    }

    public function test_same_code_can_be_used_in_different_companies(): void
    {
        $firstCompany = $this->company('Empresa uno');
        $secondCompany = $this->company('Empresa dos');
        $user = $this->userWithPermission($firstCompany);
        $this->attachPermission($user, $secondCompany);

        foreach ([$firstCompany, $secondCompany] as $company) {
            $this->actingAs($user)
                ->withSession(['active_company_id' => $company->id])
                ->post(route('settings.pos.payment-methods.store'), $this->payload(['code' => 'wallet']))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, PaymentMethod::where('code', 'wallet')->count());
    }

    public function test_code_cannot_be_repeated_in_same_company(): void
    {
        $company = $this->company('Empresa duplicado');
        $user = $this->userWithPermission($company);
        $this->method($company, ['code' => 'wallet']);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->post(route('settings.pos.payment-methods.store'), $this->payload(['code' => 'wallet']))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, PaymentMethod::forCompany($company->id)->where('code', 'wallet')->count());
    }

    public function test_cannot_open_or_modify_method_from_another_company(): void
    {
        $company = $this->company('Empresa activa');
        $otherCompany = $this->company('Empresa ajena');
        $user = $this->userWithPermission($company);
        $method = $this->method($otherCompany, ['name' => 'Ajeno', 'code' => 'ajeno']);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('settings.pos.payment-methods.edit', $method))
            ->assertNotFound();

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->put(route('settings.pos.payment-methods.update', $method), $this->payload(['name' => 'Alterado']))
            ->assertForbidden();

        $this->assertSame('Ajeno', $method->fresh()->name);
    }

    public function test_system_method_code_and_type_cannot_be_changed_by_manipulated_request(): void
    {
        $company = $this->company('Empresa sistema');
        $user = $this->userWithPermission($company);
        $method = $this->method($company, [
            'code' => 'cash',
            'type' => PaymentMethod::TYPE_CASH,
            'is_system' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->put(route('settings.pos.payment-methods.update', $method), $this->payload([
                'code' => 'alterado',
                'type' => PaymentMethod::TYPE_OTHER,
            ]))
            ->assertSessionHasErrors(['code', 'type']);

        $method->refresh();
        $this->assertSame('cash', $method->code);
        $this->assertSame(PaymentMethod::TYPE_CASH, $method->type);
    }

    public function test_system_method_cannot_be_deleted(): void
    {
        $company = $this->company('Empresa sistema');
        $user = $this->userWithPermission($company);
        $method = $this->method($company, ['is_system' => true]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->delete(route('settings.pos.payment-methods.destroy', $method))
            ->assertRedirect(route('settings.pos.payment-methods.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('payment_methods', ['id' => $method->id]);
    }

    public function test_method_with_historical_payments_cannot_be_deleted(): void
    {
        $company = $this->company('Empresa historial');
        $branch = $this->branch($company);
        $user = $this->userWithPermission($company);
        $method = $this->method($company);
        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => null,
            'sale_number' => 'V-1',
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'total' => 1000,
            'paid_total' => 1000,
        ]);
        SalePayment::create([
            'sale_id' => $sale->id,
            'payment_method_id' => $method->id,
            'created_by' => $user->id,
            'amount' => 1000,
            'received_amount' => 1000,
            'change_amount' => 0,
            'status' => SalePayment::STATUS_COMPLETED,
        ]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->delete(route('settings.pos.payment-methods.destroy', $method))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('payment_methods', ['id' => $method->id]);
    }

    public function test_custom_method_without_payments_can_be_deleted(): void
    {
        $company = $this->company('Empresa eliminar');
        $user = $this->userWithPermission($company);
        $method = $this->method($company);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->delete(route('settings.pos.payment-methods.destroy', $method))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('payment_methods', ['id' => $method->id]);
    }

    public function test_method_can_be_deactivated_and_reactivated_without_deletion(): void
    {
        $company = $this->company('Empresa estado');
        $user = $this->userWithPermission($company);
        $method = $this->method($company, ['is_active' => true]);

        $this->actingAs($user)->withSession(['active_company_id' => $company->id])
            ->patch(route('settings.pos.payment-methods.toggle-status', $method));
        $this->assertFalse($method->fresh()->is_active);

        $this->actingAs($user)->withSession(['active_company_id' => $company->id])
            ->patch(route('settings.pos.payment-methods.toggle-status', $method));
        $this->assertTrue($method->fresh()->is_active);
        $this->assertDatabaseHas('payment_methods', ['id' => $method->id]);
    }

    public function test_operational_flags_and_sort_order_are_saved(): void
    {
        $company = $this->company('Empresa indicadores');
        $user = $this->userWithPermission($company);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->post(route('settings.pos.payment-methods.store'), $this->payload([
                'code' => 'special',
                'affects_cash' => '1',
                'requires_reference' => '1',
                'allows_change' => '1',
                'sort_order' => 45,
            ]))
            ->assertSessionHasNoErrors();

        $method = PaymentMethod::forCompany($company->id)->where('code', 'special')->firstOrFail();
        $this->assertTrue($method->affects_cash);
        $this->assertTrue($method->requires_reference);
        $this->assertTrue($method->allows_change);
        $this->assertSame(45, $method->sort_order);
    }

    private function company(string $name): Company
    {
        return Company::create(['trade_name' => $name, 'is_active' => true]);
    }

    private function branch(Company $company): Branch
    {
        return Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'P-'.$company->id,
            'is_active' => true,
        ]);
    }

    private function userWithPermission(Company $company, bool $withPermission = true): User
    {
        $user = User::factory()->create();
        $this->attachPermission($user, $company, $withPermission);

        return $user;
    }

    private function attachPermission(User $user, Company $company, bool $withPermission = true): void
    {
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Rol '.uniqid(),
            'is_active' => true,
        ]);

        if ($withPermission) {
            $permission = Permission::firstOrCreate(
                ['name' => 'formas_pago.administrar'],
                ['label' => 'Administrar formas de pago', 'module' => 'Configuración', 'is_active' => true],
            );
            $role->permissions()->attach($permission);
        }

        $user->companies()->attach($company->id, ['role_id' => $role->id]);
    }

    private function method(Company $company, array $attributes = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'company_id' => $company->id,
            'name' => 'Billetera',
            'code' => 'wallet_'.uniqid(),
            'type' => PaymentMethod::TYPE_OTHER,
            'is_system' => false,
            'is_active' => true,
            'affects_cash' => false,
            'requires_reference' => false,
            'allows_change' => false,
            'sort_order' => 10,
        ], $attributes));
    }

    private function payload(array $attributes = []): array
    {
        return array_merge([
            'name' => 'Billetera digital',
            'code' => 'wallet',
            'type' => PaymentMethod::TYPE_OTHER,
            'is_active' => '1',
            'affects_cash' => '0',
            'requires_reference' => '0',
            'allows_change' => '0',
            'sort_order' => 10,
        ], $attributes);
    }
}
