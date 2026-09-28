<?php

namespace Tests\Feature;

use App\Mail\LoyaltyPortalPasswordResetMail;
use App\Mail\SaleReceiptMail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyPortalCredential;
use App\Models\LoyaltyPortalLink;
use App\Models\LoyaltyPortalPost;
use App\Models\LoyaltySetting;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LoyaltyCustomerPortalProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_secure_link_can_activate_password_and_login_to_one_company_portal(): void
    {
        [$company, , $customer] = $this->context();
        $this->withSession($this->portalSession($company, $customer))->post(route('loyalty.customer.activate'), ['username' => 'cliente1', 'email' => 'cliente@example.com', 'password' => 'Clave-Segura1', 'password_confirmation' => 'Clave-Segura1'])->assertRedirect();
        $this->assertDatabaseHas('loyalty_portal_credentials', ['company_id' => $company->id, 'customer_id' => $customer->id, 'username' => 'cliente1']);

        $this->post(route('loyalty.customer.login.store', $company), ['username' => 'cliente1', 'password' => 'Clave-Segura1'])
            ->assertRedirect(route('loyalty.customer.home', $company))->assertSessionHas('loyalty_portal_customer_id', $customer->id);
        $other = Company::create(['trade_name' => 'Otra', 'legal_name' => 'Otra', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $this->withSession($this->portalSession($company, $customer))->get(route('loyalty.customer.home', $other))->assertForbidden();
    }

    public function test_password_recovery_uses_hashed_expiring_single_use_token(): void
    {
        Mail::fake();
        [$company, , $customer] = $this->context();
        LoyaltyPortalCredential::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'username' => 'cliente', 'email' => 'cliente@example.com', 'password' => 'Clave-Segura1']);

        $this->post(route('loyalty.customer.password.email', $company), ['email' => 'cliente@example.com'])->assertSessionHas('success');
        Mail::assertSent(LoyaltyPortalPasswordResetMail::class);
        $reset = DB::table('loyalty_portal_password_resets')->first();
        $this->assertSame(64, strlen($reset->token_hash));
        $this->assertNull($reset->used_at);
    }

    public function test_portal_consolidates_branches_and_exposes_real_expiration_minimum_and_content(): void
    {
        [$company, $branches, $customer] = $this->context();
        $account = LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => 500, 'total_earned' => 700, 'total_redeemed' => 200, 'last_qualifying_purchase_at' => now()->subMonth(), 'is_active' => true]);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => 5, 'point_value' => 1, 'redemption_minimum_enabled' => true, 'redemption_minimum_amount' => 1000, 'expiration_enabled' => true, 'expiration_months' => 3]);
        foreach ($branches as $index => $branch) {
            LoyaltyMovement::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'loyalty_account_id' => $account->id, 'customer_id' => $customer->id, 'type' => LoyaltyMovement::TYPE_PURCHASE, 'points' => 250, 'balance_before' => $index * 250, 'balance_after' => ($index + 1) * 250, 'description' => 'Compra sucursal', 'event_key' => 'portal-'.$index, 'effective_at' => now()]);
        }
        LoyaltyPortalPost::create(['company_id' => $company->id, 'type' => 'notice', 'title' => 'Aviso real', 'is_active' => true]);
        LoyaltyPortalLink::create(['company_id' => $company->id, 'type' => 'store', 'label' => 'Ver tienda', 'url' => 'https://example.com', 'is_active' => true]);

        $this->withSession($this->portalSession($company, $customer))->get(route('loyalty.customer.home', $company))->assertOk()
            ->assertSee($branches[0]->name)->assertSee($branches[1]->name)->assertSee('Te faltan')->assertSee('días restantes')->assertSee('Aviso real')->assertSee('Ver tienda');
    }

    public function test_customer_can_download_and_resend_only_own_sales(): void
    {
        Mail::fake();
        [$company, $branches, $customer] = $this->context();
        $sale = $this->sale($company, $branches[0], $customer);
        $foreign = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Ajeno', 'is_active' => true]);
        $foreignSale = $this->sale($company, $branches[0], $foreign);
        $session = $this->portalSession($company, $customer);

        $this->withSession($session)->get(route('loyalty.customer.receipt.pdf', [$company, $sale]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->withSession($session)->post(route('loyalty.customer.receipt.mail', [$company, $sale]), ['email' => 'cliente@example.com'])->assertRedirect();
        Mail::assertSent(SaleReceiptMail::class);
        $this->withSession($session)->get(route('loyalty.customer.receipt.pdf', [$company, $foreignSale]))->assertNotFound();
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa Portal', 'legal_name' => 'Empresa Portal S.A.', 'identification_number' => uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branches = collect([
            Branch::create(['company_id' => $company->id, 'name' => 'Centro', 'code' => 'CEN', 'is_active' => true]),
            Branch::create(['company_id' => $company->id, 'name' => 'Oeste', 'code' => 'OES', 'is_active' => true]),
        ]);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Portal', 'email' => 'cliente@example.com', 'is_active' => true]);

        return [$company, $branches, $customer];
    }

    private function sale(Company $company, Branch $branch, Customer $customer): Sale
    {
        $user = User::factory()->create();
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'customer_id' => $customer->id, 'sale_number' => 'PORTAL-'.uniqid(), 'document_type' => 'electronic_ticket', 'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 100, 'discount_total' => 0, 'tax_total' => 0, 'rounding_total' => 0, 'total' => 100, 'paid_total' => 100, 'balance_due' => 0, 'completed_at' => now()]);
        DB::table('sale_items')->insert(['sale_id' => $sale->id, 'product_code' => 'P', 'description' => 'Producto', 'quantity' => 1, 'unit_price' => 100, 'gross_total' => 100, 'discount_total' => 0, 'subtotal' => 100, 'tax_rate' => 0, 'tax_total' => 0, 'total' => 100, 'unit_cost' => 50, 'created_at' => now(), 'updated_at' => now()]);
        $method = PaymentMethod::firstOrCreate(['company_id' => $company->id, 'code' => 'cash'], ['name' => 'Efectivo', 'type' => 'cash', 'is_active' => true]);
        DB::table('sale_payments')->insert(['sale_id' => $sale->id, 'payment_method_id' => $method->id, 'created_by' => $user->id, 'status' => 'completed', 'amount' => 100, 'received_amount' => 100, 'change_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return $sale;
    }

    private function portalSession(Company $company, Customer $customer): array
    {
        return ['loyalty_portal_company_id' => $company->id, 'loyalty_portal_customer_id' => $customer->id];
    }
}
