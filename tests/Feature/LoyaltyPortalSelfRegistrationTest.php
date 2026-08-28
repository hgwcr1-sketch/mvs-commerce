<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalCredential;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyPortalSelfRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_shows_register_link(): void
    {
        $company = $this->company('Login Link');
        $this->get(route('loyalty.customer.login', $company))->assertOk()->assertSee('Registrarme / Crear mi cuenta')->assertSee(route('loyalty.customer.register', $company), false);
    }

    public function test_register_creates_new_active_customer_and_credential(): void
    {
        $company = $this->company('Registro Nuevo');

        $response = $this->post(route('loyalty.customer.register.store', $company), [
            'name' => 'Cliente Nuevo',
            'identification' => '1-2345-6789',
            'phone' => '8888-8888',
            'email' => 'nuevo@example.com',
            'username' => 'cliente_nuevo',
            'password' => 'ClaveSegura1',
            'password_confirmation' => 'ClaveSegura1',
        ]);

        $response->assertRedirect(route('loyalty.customer.home', $company));
        $customer = Customer::where('company_id', $company->id)->where('identification', '1-2345-6789')->firstOrFail();
        $this->assertTrue($customer->is_active);
        $this->assertSame('nuevo@example.com', $customer->email);
        $this->assertDatabaseHas('loyalty_portal_credentials', ['company_id' => $company->id, 'customer_id' => $customer->id, 'username' => 'cliente_nuevo']);
        // Visible en POS search (por nombre y por teléfono normalizado)
        $this->assertTrue($this->posCustomerVisible($company, 'Cliente Nuevo', $customer->id));
        $this->assertTrue($this->posCustomerVisible($company, '8888', $customer->id));
        // Visible en Clientes index (indirecto: está en DB)
        $this->assertDatabaseHas('customers', ['company_id' => $company->id, 'id' => $customer->id]);
    }

    public function test_register_reuses_existing_customer_by_identification(): void
    {
        $company = $this->company('Dedup ID');
        $existing = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Existente ID', 'identification' => '9-9999-9999', 'phone' => '2222-1111', 'email' => 'existente@example.com', 'is_active' => true]);

        $this->post(route('loyalty.customer.register.store', $company), [
            'name' => 'Intento Nuevo',
            'identification' => '9-9999-9999',
            'phone' => '8888-0000',
            'email' => 'otro@example.com',
            'username' => 'usuario_id',
            'password' => 'ClaveSegura1',
            'password_confirmation' => 'ClaveSegura1',
        ])->assertRedirect(route('loyalty.customer.home', $company));

        $this->assertSame(1, Customer::where('company_id', $company->id)->where('identification', '9-9999-9999')->count());
        $this->assertDatabaseHas('loyalty_portal_credentials', ['company_id' => $company->id, 'customer_id' => $existing->id, 'username' => 'usuario_id']);
        $this->assertDatabaseMissing('customers', ['email' => 'otro@example.com']);
    }

    public function test_register_reuses_existing_customer_by_normalized_phone(): void
    {
        $company = $this->company('Dedup Phone');
        $existing = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Existente Phone', 'phone' => '88888888', 'is_active' => true]);

        $this->post(route('loyalty.customer.register.store', $company), [
            'name' => 'Nuevo Phone',
            'phone' => '8888-8888',
            'username' => 'usuario_phone',
            'password' => 'ClaveSegura1',
            'password_confirmation' => 'ClaveSegura1',
        ])->assertRedirect(route('loyalty.customer.home', $company));

        $this->assertSame(1, Customer::where('company_id', $company->id)->where('phone', '88888888')->count());
        $this->assertDatabaseHas('loyalty_portal_credentials', ['customer_id' => $existing->id]);
    }

    public function test_register_reuses_existing_customer_by_email_case_insensitive(): void
    {
        $company = $this->company('Dedup Email');
        $existing = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Existente Email', 'email' => 'EXISTENTE@EXAMPLE.COM', 'is_active' => true]);

        $this->post(route('loyalty.customer.register.store', $company), [
            'name' => 'Nuevo Email',
            'email' => 'existente@example.com',
            'username' => 'usuario_email',
            'password' => 'ClaveSegura1',
            'password_confirmation' => 'ClaveSegura1',
        ])->assertRedirect(route('loyalty.customer.home', $company));

        $this->assertSame(1, Customer::where('company_id', $company->id)->whereRaw('LOWER(email)=?', ['existente@example.com'])->count());
        $this->assertDatabaseHas('loyalty_portal_credentials', ['customer_id' => $existing->id]);
    }

    public function test_register_does_not_cross_company_boundaries(): void
    {
        $companyA = $this->company('Empresa A');
        $companyB = $this->company('Empresa B');
        Customer::create(['company_id' => $companyA->id, 'customer_type' => 'individual', 'name' => 'Cliente A', 'identification' => 'ID-123', 'is_active' => true]);

        $this->post(route('loyalty.customer.register.store', $companyB), [
            'name' => 'Cliente B',
            'identification' => 'ID-123',
            'username' => 'cliente_b',
            'password' => 'ClaveSegura1',
            'password_confirmation' => 'ClaveSegura1',
        ])->assertRedirect(route('loyalty.customer.home', $companyB));

        $this->assertSame(1, Customer::where('company_id', $companyA->id)->where('identification', 'ID-123')->count());
        $this->assertSame(1, Customer::where('company_id', $companyB->id)->where('identification', 'ID-123')->count());
        $this->assertNotSame(Customer::where('company_id', $companyA->id)->first()->id, Customer::where('company_id', $companyB->id)->first()->id);
    }

    public function test_existing_customer_already_with_credential_is_blocked(): void
    {
        $company = $this->company('Ya tiene acceso');
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Con Acceso', 'identification' => 'ID-999', 'is_active' => true]);
        LoyaltyPortalCredential::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'username' => 'existente', 'email' => 'existente@example.com', 'password' => 'ClaveSegura1']);

        $this->post(route('loyalty.customer.register.store', $company), [
            'name' => 'Intento',
            'identification' => 'ID-999',
            'username' => 'nuevo_user',
            'password' => 'ClaveSegura1',
            'password_confirmation' => 'ClaveSegura1',
        ])->assertSessionHasErrors('username');

        $this->assertSame(1, Customer::where('company_id', $company->id)->where('identification', 'ID-999')->count());
        $this->assertDatabaseMissing('loyalty_portal_credentials', ['username' => 'nuevo_user']);
    }

    public function test_register_blocks_when_identification_and_phone_match_different_customers(): void
    {
        $company = $this->company('Conflicto ID+Phone');
        $customerA = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente A', 'identification' => 'ID-A', 'phone' => '11111111', 'is_active' => true]);
        $customerB = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente B', 'identification' => 'ID-B', 'phone' => '22222222', 'is_active' => true]);

        $initialCustomerCount = Customer::where('company_id', $company->id)->count();
        $initialCredentialCount = LoyaltyPortalCredential::where('company_id', $company->id)->count();

        $this->post(route('loyalty.customer.register.store', $company), [
            'name' => 'Intento Conflicto',
            'identification' => 'ID-A',
            'phone' => '22222222',
            'username' => 'conflicto_user',
            'password' => 'ClaveSegura1',
            'password_confirmation' => 'ClaveSegura1',
        ])->assertSessionHasErrors('identification');

        $this->assertSame($initialCustomerCount, Customer::where('company_id', $company->id)->count());
        $this->assertSame($initialCredentialCount, LoyaltyPortalCredential::where('company_id', $company->id)->count());
        $this->assertDatabaseMissing('loyalty_portal_credentials', ['username' => 'conflicto_user']);
        $this->assertDatabaseMissing('customers', ['name' => 'Intento Conflicto']);
    }

    public function test_register_blocks_when_email_matches_different_customer_than_phone(): void
    {
        $company = $this->company('Conflicto Email');
        Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Phone', 'phone' => '33333333', 'is_active' => true]);
        Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Email', 'email' => 'email@conflicto.com', 'is_active' => true]);

        $initialCustomerCount = Customer::where('company_id', $company->id)->count();

        $this->post(route('loyalty.customer.register.store', $company), [
            'name' => 'Intento Conflicto Email',
            'phone' => '33333333',
            'email' => 'email@conflicto.com',
            'username' => 'conflicto_email',
            'password' => 'ClaveSegura1',
            'password_confirmation' => 'ClaveSegura1',
        ])->assertSessionHasErrors('identification');

        $this->assertSame($initialCustomerCount, Customer::where('company_id', $company->id)->count());
        $this->assertDatabaseMissing('loyalty_portal_credentials', ['username' => 'conflicto_email']);
    }

    public function test_new_customer_appears_in_pos_search(): void
    {
        $company = $this->company('POS Search');
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI'.uniqid(), 'is_active' => true]);
        $user = $this->staff($company, $branch);

        $this->post(route('loyalty.customer.register.store', $company), [
            'name' => 'Cliente POS',
            'phone' => '7777-7777',
            'email' => 'pos@example.com',
            'username' => 'cliente_pos',
            'password' => 'ClaveSegura1',
            'password_confirmation' => 'ClaveSegura1',
        ])->assertRedirect();

        $customer = Customer::where('company_id', $company->id)->where('email', 'pos@example.com')->firstOrFail();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('pos.customers.search', ['q' => 'pos@example.com']))->assertOk()->assertJsonFragment(['id' => $customer->id]);
    }

    private function company(string $name): Company
    {
        return Company::create(['trade_name' => $name.' '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
    }

    private function staff(Company $company, Branch $branch): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Vendedor '.uniqid(), 'is_active' => true]);
        $permission = Permission::firstOrCreate(['name' => 'pos.acceder'], ['label' => 'POS', 'module' => 'POS', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        return $user;
    }

    private function posCustomerVisible(Company $company, string $term, int $customerId): bool
    {
        $branch = Branch::where('company_id', $company->id)->first() ?? Branch::create(['company_id' => $company->id, 'name' => 'Tmp', 'code' => 'TMP'.uniqid(), 'is_active' => true]);
        $user = $this->staff($company, $branch);
        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])->get(route('pos.customers.search', ['q' => $term]));
        $data = $response->json();
        foreach ($data as $row) { if ((int) $row['id'] === $customerId) return true; }
        return false;
    }
}
