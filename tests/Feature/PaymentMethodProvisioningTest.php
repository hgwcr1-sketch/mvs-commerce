<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_company_receives_exactly_the_five_initial_methods(): void
    {
        $company = $this->createCompany('Empresa existente');

        $this->seed(PaymentMethodSeeder::class);

        $this->assertSame(5, PaymentMethod::forCompany($company->id)->count());
        $this->assertEqualsCanonicalizing(
            ['cash', 'card', 'sinpe', 'credit', 'loyalty_points'],
            PaymentMethod::forCompany($company->id)->pluck('code')->all(),
        );
    }

    public function test_provisioning_twice_does_not_create_duplicates(): void
    {
        $company = $this->createCompany('Empresa idempotente');

        $this->seed(PaymentMethodSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $this->assertSame(5, PaymentMethod::forCompany($company->id)->count());
        $this->assertSame(1, PaymentMethod::forCompany($company->id)->where('code', 'cash')->count());
        $this->assertSame(1, PaymentMethod::forCompany($company->id)->where('code', 'card')->count());
        $this->assertSame(1, PaymentMethod::forCompany($company->id)->where('code', 'sinpe')->count());
        $this->assertSame(1, PaymentMethod::forCompany($company->id)->where('code', 'credit')->count());
        $this->assertSame(1, PaymentMethod::forCompany($company->id)->where('code', 'loyalty_points')->count());
    }

    public function test_existing_customization_is_not_overwritten(): void
    {
        $company = $this->createCompany('Empresa personalizada');
        PaymentMethod::create([
            'company_id' => $company->id,
            'code' => 'cash',
            'name' => 'Caja personalizada',
            'type' => PaymentMethod::TYPE_CASH,
            'is_system' => true,
            'is_active' => false,
            'affects_cash' => false,
            'requires_reference' => true,
            'allows_change' => false,
            'sort_order' => 99,
        ]);

        $this->seed(PaymentMethodSeeder::class);

        $cash = PaymentMethod::forCompany($company->id)->where('code', 'cash')->firstOrFail();
        $this->assertSame('Caja personalizada', $cash->name);
        $this->assertFalse($cash->is_active);
        $this->assertFalse($cash->affects_cash);
        $this->assertTrue($cash->requires_reference);
        $this->assertFalse($cash->allows_change);
        $this->assertSame(99, $cash->sort_order);
    }

    public function test_newly_provisioned_company_receives_the_five_methods(): void
    {
        $this->seed(PermissionSeeder::class);
        $owner = User::factory()->create();

        $company = app(CompanyProvisioner::class)->provision(
            $owner,
            ['trade_name' => 'Empresa nueva'],
        );

        $this->assertSame(5, PaymentMethod::forCompany($company->id)->count());
        $this->assertEqualsCanonicalizing(
            ['cash', 'card', 'sinpe', 'credit', 'loyalty_points'],
            PaymentMethod::forCompany($company->id)->pluck('code')->all(),
        );
    }

    public function test_each_company_receives_independent_methods(): void
    {
        $firstCompany = $this->createCompany('Empresa uno');
        $secondCompany = $this->createCompany('Empresa dos');

        $this->seed(PaymentMethodSeeder::class);

        $firstIds = PaymentMethod::forCompany($firstCompany->id)->pluck('id');
        $secondIds = PaymentMethod::forCompany($secondCompany->id)->pluck('id');

        $this->assertCount(5, $firstIds);
        $this->assertCount(5, $secondIds);
        $this->assertEmpty($firstIds->intersect($secondIds));
    }

    public function test_administrator_receives_the_payment_method_permission(): void
    {
        $company = $this->createCompany('Empresa administradora');
        $administrator = Role::create([
            'company_id' => $company->id,
            'name' => 'Administrador',
            'is_active' => true,
        ]);

        $this->seed(PermissionSeeder::class);

        $this->assertTrue(
            $administrator->fresh()->permissions()->where('name', 'formas_pago.administrar')->exists(),
        );
    }

    public function test_other_role_does_not_receive_the_permission_automatically(): void
    {
        $company = $this->createCompany('Empresa con otro rol');
        $otherRole = Role::create([
            'company_id' => $company->id,
            'name' => 'Supervisor',
            'is_active' => true,
        ]);

        $this->seed(PermissionSeeder::class);

        $this->assertFalse(
            $otherRole->fresh()->permissions()->where('name', 'formas_pago.administrar')->exists(),
        );
    }

    public function test_initial_methods_have_the_expected_operational_values(): void
    {
        $company = $this->createCompany('Empresa valores');

        $this->seed(PaymentMethodSeeder::class);

        $expected = [
            'cash' => ['Efectivo', 'cash', true, true, true, false, true, 10],
            'card' => ['Tarjeta', 'card', true, true, false, true, false, 20],
            'sinpe' => ['SINPE', 'sinpe', true, true, false, true, false, 30],
            'credit' => ['Crédito', 'credit', true, true, false, false, false, 40],
            'loyalty_points' => ['Puntos de lealtad', PaymentMethod::TYPE_LOYALTY_POINTS, true, true, false, false, false, 50],
        ];

        foreach ($expected as $code => $values) {
            $method = PaymentMethod::forCompany($company->id)->where('code', $code)->firstOrFail();

            $this->assertSame($values, [
                $method->name,
                $method->type,
                $method->is_system,
                $method->is_active,
                $method->affects_cash,
                $method->requires_reference,
                $method->allows_change,
                $method->sort_order,
            ]);
        }
    }

    public function test_loyalty_points_is_provisioned_without_cash_effect(): void
    {
        $company = $this->createCompany('Empresa fidelidad');

        $this->seed(PaymentMethodSeeder::class);

        $loyalty = PaymentMethod::forCompany($company->id)
            ->where('code', 'loyalty_points')
            ->firstOrFail();

        $this->assertSame(PaymentMethod::TYPE_LOYALTY_POINTS, $loyalty->type);
        $this->assertTrue($loyalty->is_system);
        $this->assertTrue($loyalty->is_active);
        $this->assertFalse($loyalty->affects_cash);
        $this->assertFalse($loyalty->requires_reference);
        $this->assertFalse($loyalty->allows_change);
    }

    private function createCompany(string $tradeName): Company
    {
        return Company::create([
            'trade_name' => $tradeName,
            'is_active' => true,
        ]);
    }
}
