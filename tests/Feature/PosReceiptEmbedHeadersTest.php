<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cabeceras de framing del comprobante POS: la vista previa embebida
 * (embed=1) del modal post-venta solo permite el mismo origen; el resto
 * de rutas y parámetros conservan DENY + frame-ancestors 'none'.
 */
class PosReceiptEmbedHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_with_embed_allows_only_same_origin_framing_and_keeps_csp(): void
    {
        [$company, $branch, $user, $sale] = $this->saleContext();

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.receipt', $sale).'?format=80mm&embed=1');

        $response->assertOk()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        foreach (["base-uri 'self'", "form-action 'self'", "object-src 'none'", "worker-src 'self' blob:"] as $directive) {
            $this->assertStringContainsString($directive, $csp, "CSP perdió: {$directive}");
        }

        $head = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->head(route('pos.receipt', $sale).'?embed=1');
        $head->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_receipt_without_or_with_manipulated_embed_stays_denied(): void
    {
        [$company, $branch, $user, $sale] = $this->saleContext();

        foreach (['', '?format=80mm', '?embed=0', '?embed=false', '?embed=abc', '?embed=1&embed=0'] as $suffix) {
            $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->get(route('pos.receipt', $sale).$suffix);

            $response->assertOk()->assertHeader('X-Frame-Options', 'DENY');
            $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'), "Debe seguir DENY con: {$suffix}");
        }
    }

    public function test_embed_on_other_routes_and_on_receipt_pdf_stays_denied(): void
    {
        [$company, $branch, $user, $sale] = $this->saleContext();

        $pdf = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.receipt.pdf', $sale).'?format=letter&embed=1');
        $pdf->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $pdf->headers->get('Content-Security-Policy'));

        $index = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index').'?embed=1');
        $index->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $index->headers->get('Content-Security-Policy'));
    }

    //  ── helpers  ──

    private function saleContext(): array
    {
        $company = Company::create(['trade_name' => 'Comercio Embed', 'legal_name' => 'Comercio Embed S.A.', 'identification_number' => '3101000014', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = $this->user($company, $branch, ['ventas.ver']);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente', 'email' => 'cliente@example.com', 'is_active' => true]);
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'customer_id' => $customer->id, 'sale_number' => 'POS-EMBED-001', 'document_type' => 'ticket', 'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 1000, 'discount_total' => 0, 'tax_total' => 17, 'rounding_total' => 0, 'total' => 1017, 'paid_total' => 1017, 'balance_due' => 0, 'completed_at' => now()]);
        DB::table('sale_items')->insert(['sale_id' => $sale->id, 'product_code' => 'P01', 'description' => 'Producto', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'discount_total' => 0, 'subtotal' => 1000, 'tax_rate' => 1.7, 'tax_total' => 17, 'total' => 1017, 'unit_cost' => 500, 'created_at' => now(), 'updated_at' => now()]);

        return [$company, $branch, $user, $sale];
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

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
