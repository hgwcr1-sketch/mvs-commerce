<?php

namespace Tests\Feature;

use App\Mail\SaleReceiptMail;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalProfile;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Modal post-venta del POS: datos que alimentan el modal, estado fiscal,
 * correo precargado/enviable por JSON y símbolo ₡ en la superficie POS.
 */
class PosPostSaleModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_returns_every_field_the_post_sale_modal_needs(): void
    {
        Mail::fake();
        [$company, $branch, $user, $product, $customer] = $this->context();

        $response = $this->checkout($user, $company, $branch, $product, $customer);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonStructure([
            'sale_id',
            'sale_number',
            'electronic_status',
            'subtotal',
            'tax_total',
            'total',
            'total_change',
            'payments',
            'receipt_url',
            'receipt_pdf_url',
            'customer_name',
            'customer_email',
            'fiscal',
        ]);

        $this->assertSame($customer->name, $response->json('customer_name'));
        $this->assertSame($customer->email, $response->json('customer_email'));
        $this->assertStringContainsString('/comprobante', (string) $response->json('receipt_url'));
        $this->assertStringContainsString('/comprobante.pdf', (string) $response->json('receipt_pdf_url'));
        // Venta sin documento electrónico: sin fiscal (la vista no muestra clave ni QR inventados).
        $this->assertNull($response->json('fiscal'));
        $this->assertNull($response->json('electronic_status'));
    }

    public function test_pos_page_renders_the_post_sale_modal_with_colon_sign_and_actions(): void
    {
        [$company, $branch, $user, $product, $customer] = $this->context();

        $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->getContent();

        foreach ([
            'Resumen del comprobante',
            'Correo del cliente',
            'Enviar comprobante por correo',
            'Imprimir comprobante',
            'Reimprimir',
            'Descargar PDF',
            'Imprimir comprobante fiscal',
            'Nueva venta',
            'Comprobante electrónico en proceso',
            'x-model="checkout.receiptEmail"',
            'checkout.result.receipt_pdf_url',
            "checkout.result.electronic_status === 'accepted'",
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "El modal post-venta no incluye: {$needle}");
        }

        $this->assertStringContainsString("\u{20A1}", $html, 'El POS debe mostrar el símbolo ₡');
        $this->assertStringNotContainsString("\u{00A2}", $html, 'El POS no debe mostrar el símbolo ¢ en lugar de ₡');
    }

    public function test_receipt_mail_endpoint_answers_json_for_the_pos_modal(): void
    {
        Mail::fake();
        [$company, $branch, $user, $sale] = $this->saleContext();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.receipt.mail', $sale), ['email' => 'cliente@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Comprobante enviado a cliente@example.com.');

        Mail::assertSent(SaleReceiptMail::class, fn (SaleReceiptMail $mail) => $mail->hasTo('cliente@example.com'));
    }

    public function test_receipt_mail_endpoint_reports_json_errors_without_sending(): void
    {
        Mail::fake();
        [$company, $branch, $user, $sale] = $this->saleContext();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.receipt.mail', $sale), ['email' => 'no-es-correo'])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_receipt_mail_endpoint_reports_transport_failure_as_json(): void
    {
        [$company, $branch, $user, $sale] = $this->saleContext();
        Mail::shouldReceive('to')->once()->with('cliente@example.com')->andThrow(new RuntimeException('Transporte no disponible'));

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.receipt.mail', $sale), ['email' => 'cliente@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => Sale::STATUS_COMPLETED]);
    }

    //  ── helpers  ──

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Comercio Modal', 'legal_name' => 'Comercio Modal S.A.', 'identification_number' => '3101000012', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        app(PaymentMethodProvisioner::class)->provision($company);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = $this->user($company, $branch, ['pos.acceder', 'ventas.crear']);
        $product = $this->product($company);
        $this->stock($branch, $product, 100);
        $this->ensureCashSession($company, $branch, $user);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Modal', 'email' => 'cliente@example.com', 'is_active' => true]);

        return [$company, $branch, $user, $product, $customer];
    }

    private function saleContext(): array
    {
        $company = Company::create(['trade_name' => 'Comercio Modal', 'legal_name' => 'Comercio Modal S.A.', 'identification_number' => '3101000013', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = $this->user($company, $branch, ['ventas.ver']);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente', 'email' => 'cliente@example.com', 'is_active' => true]);
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'customer_id' => $customer->id, 'sale_number' => 'POS-MODAL-001', 'document_type' => 'electronic_ticket', 'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 1000, 'discount_total' => 0, 'tax_total' => 17, 'rounding_total' => 0, 'total' => 1017, 'paid_total' => 1017, 'balance_due' => 0, 'completed_at' => now()]);
        DB::table('sale_items')->insert(['sale_id' => $sale->id, 'product_code' => 'P01', 'description' => 'Producto', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'discount_total' => 0, 'subtotal' => 1000, 'tax_rate' => 1.7, 'tax_total' => 17, 'total' => 1017, 'unit_cost' => 500, 'created_at' => now(), 'updated_at' => now()]);
        $method = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash', 'name' => 'Efectivo', 'type' => 'cash', 'affects_cash' => true, 'allows_change' => true, 'requires_reference' => false, 'is_active' => true]);
        DB::table('sale_payments')->insert(['sale_id' => $sale->id, 'payment_method_id' => $method->id, 'created_by' => $user->id, 'status' => 'completed', 'amount' => 1017, 'received_amount' => 1017, 'change_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return [$company, $branch, $user, $sale];
    }

    private function checkout(User $user, Company $company, Branch $branch, Product $product, Customer $customer)
    {
        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $method = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();

        return $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.checkout'), [
                'checkout_token' => (string) Str::uuid(),
                'cash_session_id' => $cashSession->id,
                'customer_id' => $customer->id,
                'payments' => [['payment_method_id' => $method->id, 'amount' => 10000]],
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ]);
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function product(Company $company): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'allows_decimals' => false, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix,
            'internal_code' => 'P-'.$suffix,
            'cost' => 500,
            'sale_price' => 10000,
            'tax_rate' => 0,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        if ($product->fiscal_profile_id === null && (float) $product->tax_rate === 0.0) {
            $product->fiscal_profile_id = FiscalProfile::query()->where('tax_code', '01')->where('tax_rate_code', '10')->value('id');
            $product->save();
        }

        return $product;
    }

    private function stock(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function ensureCashSession(Company $company, Branch $branch, User $user): CashSession
    {
        $session = CashSession::query()->forCompany($company->id)->forBranch($branch->id)->where('opened_by', $user->id)->where('status', CashSession::STATUS_OPEN)->first();
        if ($session) {
            return $session;
        }
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CAJA-'.uniqid(), 'name' => 'Caja', 'is_active' => true]);

        return CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'S-'.uniqid(), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'opening_amount' => 0, 'opened_at' => now()]);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
