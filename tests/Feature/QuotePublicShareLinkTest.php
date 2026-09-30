<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class QuotePublicShareLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_temporary_link_returns_pdf_without_authentication(): void
    {
        $quote = $this->quote();
        $url = URL::temporarySignedRoute('cotizaciones.public.pdf', now()->addDays(7), ['quote' => $quote]);

        $this->get($url)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_missing_or_tampered_signature_returns_forbidden(): void
    {
        $quote = $this->quote();
        $url = URL::temporarySignedRoute('cotizaciones.public.pdf', now()->addDays(7), ['quote' => $quote]);

        $this->get(route('cotizaciones.public.pdf', $quote))->assertForbidden();

        $tampered = (string) preg_replace('/signature=[^&]+/', 'signature=firma-invalida', $url);
        $this->get($tampered)->assertForbidden();
    }

    public function test_expired_link_returns_forbidden_without_touching_the_quote(): void
    {
        $quote = $this->quote(['expires_at' => today()->addDays(10)]);

        $expired = URL::temporarySignedRoute('cotizaciones.public.pdf', now()->subMinute(), ['quote' => $quote]);
        $this->get($expired)->assertForbidden();

        $quote->refresh();
        $this->assertSame(Quote::STATUS_ACTIVE, $quote->status);
        $this->assertSame(today()->addDays(10)->toDateString(), $quote->expires_at->toDateString());

        // El enlace público vencido no impide generar uno nuevo.
        $fresh = URL::temporarySignedRoute('cotizaciones.public.pdf', now()->addDays(7), ['quote' => $quote]);
        $this->get($fresh)->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_whatsapp_uses_public_link_and_email_keeps_print_route(): void
    {
        [$company, $branch, $user] = $this->context();
        $quote = $this->quote(['company' => $company, 'branch' => $branch, 'user' => $user]);

        $html = $this->asContext($user, $company, $branch)
            ->get(route('cotizaciones.show', $quote))
            ->assertOk()
            ->getContent();

        preg_match('/wa\.me\/[^"]+/', $html, $whatsapp);
        $this->assertNotEmpty($whatsapp, 'Falta el enlace de WhatsApp');
        $this->assertStringContainsString('compartidas%2Fcotizaciones%2F'.$quote->id, $whatsapp[0], 'WhatsApp no usa el enlace público temporal');

        preg_match('/mailto:[^"]+/', $html, $email);
        $this->assertNotEmpty($email, 'Falta el enlace de correo');
        $this->assertStringContainsString(rawurlencode(route('cotizaciones.print', $quote)), $email[0], 'El correo debe conservar la ruta de impresión actual');
    }

    private function quote(array $overrides = []): Quote
    {
        $company = $overrides['company'] ?? Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = $overrides['branch'] ?? Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $user = $overrides['user'] ?? $this->user($company, $branch);

        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente WhatsApp', 'customer_type' => 'individual', 'is_active' => true, 'mobile' => '88888888', 'email' => 'cliente@demo.test']);

        return Quote::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'quote_number' => 'COT-'.uniqid(),
            'status' => Quote::STATUS_ACTIVE,
            'currency_code' => 'CRC',
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 130,
            'total' => 1130,
            'expires_at' => $overrides['expires_at'] ?? today()->addDays(15),
        ]);
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);

        return [$company, $branch, $this->user($company, $branch)];
    }

    private function user(Company $company, Branch $branch): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        $role->permissions()->attach(Permission::firstOrCreate(['name' => 'cotizaciones.ver'], ['label' => 'cotizaciones.ver', 'module' => 'POS', 'is_active' => true]));
        $user->companies()->attach($company, ['role_id' => $role->id]);
        $user->branches()->attach($branch);

        return $user;
    }

    private function asContext(User $user, Company $company, Branch $branch)
    {
        return $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
    }
}
