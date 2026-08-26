<?php

namespace Tests\Feature;

use App\Mail\SaleReceiptMail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class SaleReceiptMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_the_p04_pdf_to_the_customer_from_the_sale(): void
    {
        Mail::fake();
        [$company, $branch, $user, $sale] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('pos.receipt.mail', $sale), ['email' => 'cliente@example.com'])
            ->assertRedirect()->assertSessionHas('success');

        Mail::assertSent(SaleReceiptMail::class, function (SaleReceiptMail $mail) use ($sale) {
            $this->assertTrue($mail->hasTo('cliente@example.com'));
            $this->assertSame($sale->id, $mail->sale->id);
            $this->assertSame("comprobante-{$sale->sale_number}.pdf", $mail->attachments()[0]->as);

            return true;
        });
    }

    public function test_rejects_invalid_email_without_attempting_delivery(): void
    {
        Mail::fake();
        [$company, $branch, $user, $sale] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->from(route('ventas.show', $sale))->post(route('pos.receipt.mail', $sale), ['email' => 'correo-invalido'])
            ->assertRedirect(route('ventas.show', $sale))->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_transport_failure_does_not_change_or_remove_the_completed_sale(): void
    {
        [$company, $branch, $user, $sale] = $this->context();
        Mail::shouldReceive('to')->once()->with('cliente@example.com')->andThrow(new RuntimeException('Transporte no disponible'));

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('pos.receipt.mail', $sale), ['email' => 'cliente@example.com'])
            ->assertRedirect()->assertSessionHasErrors('email');

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => Sale::STATUS_COMPLETED, 'total' => 1017]);
    }

    public function test_mail_respects_company_branch_module_and_receipt_permissions(): void
    {
        Mail::fake();
        [$company, $branch, $creator, $sale] = $this->context();
        $otherBranch = Branch::create(['company_id' => $company->id, 'name' => 'Otra', 'code' => 'OTRA', 'is_active' => true]);
        $creator->branches()->attach($otherBranch->id);

        $this->actingAs($creator)->withSession($this->activeSession($company, $otherBranch))
            ->post(route('pos.receipt.mail', $sale), ['email' => 'cliente@example.com'])->assertNotFound();
        $unauthorized = $this->user($company, $branch, []);
        $this->actingAs($unauthorized)->withSession($this->activeSession($company, $branch))
            ->post(route('pos.receipt.mail', $sale), ['email' => 'cliente@example.com'])->assertForbidden();
        $company->modules()->create(['module_key' => 'sales', 'is_enabled' => false]);
        $this->actingAs($creator)->withSession($this->activeSession($company, $branch))
            ->post(route('pos.receipt.mail', $sale), ['email' => 'cliente@example.com'])->assertForbidden();

        Mail::assertNothingSent();
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Comercio MVS', 'legal_name' => 'Comercio MVS S.A.', 'identification_number' => '3101000000', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = $this->user($company, $branch, ['ventas.ver']);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente', 'email' => 'cliente@example.com', 'is_active' => true]);
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'customer_id' => $customer->id, 'sale_number' => 'POS-P05-001', 'document_type' => 'electronic_ticket', 'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 1000, 'discount_total' => 0, 'tax_total' => 17, 'rounding_total' => 0, 'total' => 1017, 'paid_total' => 1017, 'balance_due' => 0, 'completed_at' => now()]);
        DB::table('sale_items')->insert(['sale_id' => $sale->id, 'product_code' => 'P01', 'description' => 'Producto', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'discount_total' => 0, 'subtotal' => 1000, 'tax_rate' => 1.7, 'tax_total' => 17, 'total' => 1017, 'unit_cost' => 500, 'created_at' => now(), 'updated_at' => now()]);
        $method = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash', 'name' => 'Efectivo', 'type' => 'cash', 'affects_cash' => true, 'allows_change' => true, 'requires_reference' => false, 'is_active' => true]);
        DB::table('sale_payments')->insert(['sale_id' => $sale->id, 'payment_method_id' => $method->id, 'created_by' => $user->id, 'status' => 'completed', 'amount' => 1017, 'received_amount' => 1017, 'change_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);

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
