<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMessageTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Loyalty\LoyaltyMessageTemplateService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyOperationalDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissions_are_seeded_and_sidebar_only_shows_authorized_options(): void
    {
        $this->seed(PermissionSeeder::class);
        foreach (['fidelidad.dashboard', 'fidelidad.oportunidades', 'fidelidad.clientes', 'fidelidad.whatsapp', 'fidelidad.contactar', 'fidelidad.configuracion'] as $name) {
            $this->assertDatabaseHas('permissions', ['name' => $name, 'module' => 'Fidelidad']);
        }
        [$company, $branch, $user] = $this->context(['fidelidad.dashboard']);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->get(route('loyalty.dashboard'))
            ->assertOk()->assertSee('Fidelización')->assertDontSee('Oportunidades</a>', false);
    }

    public function test_routes_reject_users_without_each_permission(): void
    {
        [$company, $branch, $user] = $this->context([]);
        $session = $this->activeSession($company, $branch);
        $this->actingAs($user)->withSession($session)->get(route('loyalty.dashboard'))->assertForbidden();
        $this->actingAs($user)->withSession($session)->get(route('loyalty.opportunities.index'))->assertForbidden();
        $this->actingAs($user)->withSession($session)->get(route('loyalty.settings'))->assertForbidden();
    }

    public function test_dashboard_classifies_exclusive_ranges_and_isolates_companies(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 12:00:00');
        [$company, $branch, $user] = $this->context(['fidelidad.dashboard']);
        [$other] = $this->context([]);
        $this->customer($company, 40, 'Treinta');
        $this->customer($company, 70, 'Sesenta');
        $this->customer($company, 100, 'Noventa');
        $this->customer($other, 100, 'Ajeno');
        Customer::create(['company_id' => $company->id, 'name' => 'Cumple', 'customer_type' => 'individual', 'birth_date' => '1990-08-22', 'is_active' => true]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->get(route('loyalty.dashboard'))
            ->assertOk()->assertSeeInOrder(['Cumpleaños hoy', '1', '30–59 días', '1', '60–89 días', '1', '90 días o más', '1']);
        CarbonImmutable::setTestNow();
    }

    public function test_opportunities_filter_ranges_without_duplicates(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 12:00:00');
        [$company, $branch, $user] = $this->context(['fidelidad.oportunidades']);
        $this->customer($company, 40, 'Cliente 40');
        $this->customer($company, 70, 'Cliente 70');
        $this->customer($company, 100, 'Cliente 100');
        $session = $this->activeSession($company, $branch);
        $this->actingAs($user)->withSession($session)->get(route('loyalty.opportunities.index', ['type' => 'inactive_60']))
            ->assertOk()->assertSee('Cliente 70')->assertDontSee('Cliente 40')->assertDontSee('Cliente 100');
        CarbonImmutable::setTestNow();
    }

    public function test_message_templates_are_safe_encoded_and_isolated(): void
    {
        [$company] = $this->context([]);
        [$other] = $this->context([]);
        LoyaltyMessageTemplate::create(['company_id' => $company->id, 'opportunity_type' => 'inactive_30', 'body' => 'Hola {nombre} {dias_sin_comprar} {puntos} {desconocido}']);
        LoyaltyMessageTemplate::create(['company_id' => $other->id, 'opportunity_type' => 'inactive_30', 'body' => 'OTRA']);
        $customer = new Customer(['name' => 'Ana & José', 'phone_country_code' => '+506', 'phone' => '8352-6142']);
        $customer->setAttribute('loyalty_balance', '12.5000');
        $service = app(LoyaltyMessageTemplateService::class);
        $message = $service->message($company->id, 'inactive_30', $customer, 35, 'Centro');
        $company->update(['whatsapp_enabled' => true]);

        $this->assertSame('Hola Ana & José 35 12.50 {desconocido}', $message);
        $this->assertStringContainsString('50683526142?text=Hola%20Ana%20%26%20Jos%C3%A9', $service->whatsappUrl($company->fresh(), $customer, $message));
        $this->assertSame('OTRA', $service->templates($other->id)['inactive_30']);
        $company->update(['whatsapp_enabled' => false]);
        $this->assertNull($service->whatsappUrl($company->fresh(), $customer, $message));
        $customer->phone = null;
        $company->update(['whatsapp_enabled' => true]);
        $this->assertNull($service->whatsappUrl($company->fresh(), $customer, $message));
    }

    public function test_templates_can_be_updated_for_active_company_only(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.configuracion']);
        [$other] = $this->context([]);
        LoyaltyMessageTemplate::create(['company_id' => $other->id, 'opportunity_type' => 'birthday', 'body' => 'Ajena']);
        $payload = ['templates' => ['birthday' => 'Cumple {nombre}', 'inactive_30' => '30 {nombre}', 'inactive_60' => '60 {nombre}', 'inactive_90' => '90 {nombre}']];
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->put(route('configuracion.loyalty-templates.update'), $payload)->assertRedirect();
        $this->assertDatabaseHas('loyalty_message_templates', ['company_id' => $company->id, 'body' => 'Cumple {nombre}']);
        $this->assertDatabaseHas('loyalty_message_templates', ['company_id' => $other->id, 'body' => 'Ajena']);
    }

    public function test_contact_is_audited_and_cross_company_customer_is_rejected(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.contactar']);
        [$other] = $this->context([]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Propio', 'customer_type' => 'individual', 'is_active' => true]);
        $foreign = Customer::create(['company_id' => $other->id, 'name' => 'Ajeno', 'customer_type' => 'individual', 'is_active' => true]);
        $session = $this->activeSession($company, $branch);
        $this->actingAs($user)->withSession($session)->post(route('loyalty.opportunities.contact', $customer), ['opportunity_type' => 'birthday'])->assertRedirect();
        $this->assertDatabaseHas('loyalty_customer_contacts', ['company_id' => $company->id, 'customer_id' => $customer->id, 'user_id' => $user->id, 'branch_id' => $branch->id, 'channel' => 'whatsapp']);
        $this->actingAs($user)->withSession($session)->post(route('loyalty.opportunities.contact', $foreign), ['opportunity_type' => 'birthday'])->assertNotFound();
    }

    public function test_whatsapp_action_requires_permission_valid_phone_and_enabled_company(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 12:00:00');
        [$company, $branch, $user] = $this->context(['fidelidad.oportunidades', 'fidelidad.whatsapp']);
        $company->update(['whatsapp_enabled' => true]);
        $customer = $this->customer($company, 40, 'Cliente WhatsApp');
        $customer->update(['phone_country_code' => '+506', 'phone' => '83526142']);
        $session = $this->activeSession($company, $branch);

        $this->actingAs($user)->withSession($session)->get(route('loyalty.opportunities.index'))
            ->assertOk()->assertSee('Abrir WhatsApp')->assertSee('50683526142', false);

        $user->roleInCompany($company)->permissions()->detach(Permission::where('name', 'fidelidad.whatsapp')->firstOrFail());
        $this->actingAs($user)->withSession($session)->get(route('loyalty.opportunities.index'))->assertDontSee('Abrir WhatsApp');
        CarbonImmutable::setTestNow();
    }

    public function test_recent_global_purchase_removes_customer_and_contact_filter_works(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 12:00:00');
        [$company, $branch, $user] = $this->context(['fidelidad.oportunidades', 'fidelidad.contactar']);
        $recent = $this->customer($company, 5, 'Compra reciente otra sucursal');
        $inactive = $this->customer($company, 40, 'Cliente contactado');
        $session = $this->activeSession($company, $branch);
        $this->actingAs($user)->withSession($session)->post(route('loyalty.opportunities.contact', $inactive), ['opportunity_type' => 'inactive_30']);

        $this->actingAs($user)->withSession($session)->get(route('loyalty.opportunities.index', ['contacted' => 1]))
            ->assertOk()->assertSee($inactive->name)->assertDontSee($recent->name);
        $this->actingAs($user)->withSession($session)->get(route('loyalty.opportunities.index', ['contacted' => 0]))
            ->assertDontSee($inactive->name)->assertDontSee($recent->name);
        CarbonImmutable::setTestNow();
    }

    private function customer(Company $company, int $days, string $name): Customer
    {
        $customer = Customer::create(['company_id' => $company->id, 'name' => $name, 'customer_type' => 'individual', 'is_active' => true]);
        LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => 10, 'last_qualifying_purchase_at' => CarbonImmutable::now()->subDays($days), 'is_active' => true]);

        return $customer;
    }

    private function context(array $permissions): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Fidelidad', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
