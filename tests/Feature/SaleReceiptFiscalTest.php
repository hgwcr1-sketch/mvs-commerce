<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\ElectronicDocument;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\SaleReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaleReceiptFiscalTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_shows_full_electronic_identity_in_every_format_and_pdf(): void
    {
        [$company, $branch, $user, $sale] = $this->context();
        $clave = '50605102600310283267200100001010000000113150083629';
        $consecutivo = '00100001010000000113';
        $this->fiscal($company, $sale, 'accepted', $clave, $consecutivo);

        foreach (['letter', '58mm', '80mm'] as $format) {
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->get(route('pos.receipt', $sale).'?format='.$format)
                ->assertOk()
                ->assertSee('Clave electrónica')
                ->assertSee($clave)
                ->assertSee($consecutivo)
                ->assertSee('Estado: Aceptado')
                ->assertSee('Condición de venta')
                ->assertSee('Contado')
                ->assertSee('001 / 00001')
                ->assertSee('QR del comprobante electrónico', false)
                ->assertSee('Portal de Hacienda')
                ->assertSee('₡1.017');
        }

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.receipt.pdf', $sale).'?format=letter')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_receipt_hides_electronic_block_for_sales_without_document(): void
    {
        [$company, $branch, $user, $sale] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.receipt', $sale))
            ->assertOk()
            ->assertDontSee('Clave electrónica')
            ->assertDontSee('QR del comprobante electrónico', false)
            ->assertDontSee('Portal de Hacienda');
    }

    public function test_rejected_attempt_shows_state_and_pending_doc_without_clave_is_hidden(): void
    {
        [$company, $branch, $user, $sale] = $this->context();
        $clave = '50605102600310283267200100001010000000113150083628';
        $this->fiscal($company, $sale, 'rejected', $clave, '00100001010000000112');
        ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'document_type' => '01',
            'environment' => 'production',
            'status' => 'pending',
            'attempt_number' => 9,
        ]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.receipt', $sale))
            ->assertOk()
            ->assertSee('Estado: Rechazado')
            ->assertSee($clave);

        $other = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'sale_number' => 'POS-F01-002', 'document_type' => 'electronic_invoice', 'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 1000, 'discount_total' => 0, 'tax_total' => 130, 'rounding_total' => 0, 'total' => 1130, 'paid_total' => 1130, 'balance_due' => 0, 'completed_at' => now()]);
        ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $other->id,
            'document_type' => '01',
            'environment' => 'production',
            'status' => 'pending',
            'attempt_number' => 1,
        ]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.receipt', $other))
            ->assertOk()
            ->assertDontSee('Clave electrónica');
    }

    private function fiscal(Company $company, Sale $sale, string $status, string $clave, string $consecutivo): ElectronicDocument
    {
        CompanyFiscalConfig::firstOrCreate(['company_id' => $company->id], [
            'environment' => 'production',
            'fiscal_branch_code' => '001',
            'fiscal_terminal_code' => '00001',
        ]);

        return ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'document_type' => '01',
            'environment' => 'production',
            'status' => $status,
            'clave' => $clave,
            'consecutivo' => $consecutivo,
            'attempt_number' => 4,
        ]);
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Comercio Fiscal', 'legal_name' => 'Comercio Fiscal S.A.', 'identification_number' => '3102832672', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol fiscal', 'is_active' => true]);
        foreach (['pos.acceder', 'ventas.crear', 'ventas.ver'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Ventas', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'sale_number' => 'POS-F01-001', 'document_type' => 'electronic_invoice', 'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 1000, 'discount_total' => 100, 'tax_total' => 117, 'rounding_total' => 0, 'total' => 1017, 'paid_total' => 1017, 'balance_due' => 0, 'completed_at' => now()]);
        DB::table('sale_items')->insert(['sale_id' => $sale->id, 'product_code' => 'P01', 'description' => 'Producto', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'discount_total' => 100, 'subtotal' => 900, 'tax_rate' => 13, 'tax_total' => 117, 'total' => 1017, 'unit_cost' => 500, 'created_at' => now(), 'updated_at' => now()]);
        $method = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash', 'name' => 'Efectivo', 'type' => 'cash', 'affects_cash' => true, 'allows_change' => true, 'requires_reference' => false, 'is_active' => true]);
        DB::table('sale_payments')->insert(['sale_id' => $sale->id, 'payment_method_id' => $method->id, 'created_by' => $user->id, 'status' => 'completed', 'amount' => 1017, 'received_amount' => 1017, 'change_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return [$company, $branch, $user, $sale];
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
