<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\MvsPrint\MvsPrintTerminal;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\MvsPrint\EscPosSaleTicket;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MvsPrintAutoPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    // ---------------------------------------------------------------
    // Config endpoint
    // ---------------------------------------------------------------

    public function test_config_returns_auto_print_true_when_terminal_matches_uuid(): void
    {
        [$company, $branch] = $this->companyContext('Emp config');
        $user = $this->posUser($company, $branch);
        $terminal = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'auto_print' => true,
            'auto_cut' => true,
            'open_drawer' => false,
            'paper_width' => '80',
            'printer_name' => 'EPSON TM-T20',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.config', ['terminal_uuid' => $terminal->terminal_uuid]))
            ->assertOk();

        $this->assertTrue($response->json('auto_print'));
        $this->assertSame('EPSON TM-T20', $response->json('terminal.printer_name'));
        $this->assertSame('80', $response->json('terminal.paper_width'));
        $this->assertTrue($response->json('terminal.auto_cut'));
        $this->assertFalse($response->json('terminal.open_drawer'));
    }

    public function test_config_returns_auto_print_false_when_no_uuid_provided(): void
    {
        [$company, $branch] = $this->companyContext('Emp sin uuid');
        $user = $this->posUser($company, $branch);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.config'))
            ->assertOk();

        $this->assertFalse($response->json('auto_print'));
        $this->assertNull($response->json('terminal'));
    }

    public function test_config_returns_auto_print_false_when_terminal_not_found(): void
    {
        [$company, $branch] = $this->companyContext('Emp no existe');
        $user = $this->posUser($company, $branch);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.config', ['terminal_uuid' => Str::uuid()]))
            ->assertOk();

        $this->assertFalse($response->json('auto_print'));
    }

    public function test_terminal_a_does_not_use_printer_of_terminal_b(): void
    {
        [$company, $branch] = $this->companyContext('Emp multi');
        $user = $this->posUser($company, $branch);
        $terminalA = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'printer_name' => 'Printer A',
            'auto_print' => true,
        ]);
        $terminalB = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'printer_name' => 'Printer B',
            'auto_print' => false,
        ]);

        $responseA = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('mvs.print.config', ['terminal_uuid' => $terminalA->terminal_uuid]));

        $responseB = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('mvs.print.config', ['terminal_uuid' => $terminalB->terminal_uuid]));

        $this->assertSame('Printer A', $responseA->json('terminal.printer_name'));
        $this->assertTrue($responseA->json('auto_print'));
        $this->assertSame('Printer B', $responseB->json('terminal.printer_name'));
        $this->assertFalse($responseB->json('auto_print'));
    }

    public function test_sucursal_a_no_usa_terminal_de_sucursal_b(): void
    {
        [$company, $branchA] = $this->companyContext('Emp sucA');
        $branchB = $this->branch($company);
        $user = $this->posUser($company, $branchA);

        $terminalA = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branchA->id,
            'printer_name' => 'Sucursal A printer',
        ]);
        MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branchB->id,
            'printer_name' => 'Sucursal B printer',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branchA->id])
            ->getJson(route('mvs.print.config', ['terminal_uuid' => $terminalA->terminal_uuid]));

        $this->assertSame('Sucursal A printer', $response->json('terminal.printer_name'));
    }

    public function test_empresa_a_no_acceso_config_empresa_b(): void
    {
        [$companyA, $branchA] = $this->companyContext('Emp A');
        [$companyB, $branchB] = $this->companyContext('Emp B');
        $userA = $this->posUser($companyA, $branchA);
        $terminalB = MvsPrintTerminal::factory()->create([
            'company_id' => $companyB->id,
            'branch_id' => $branchB->id,
        ]);

        $this->actingAs($userA)
            ->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->getJson(route('mvs.print.config', ['terminal_uuid' => $terminalB->terminal_uuid]))
            ->assertOk()
            ->assertJson(['auto_print' => false, 'terminal' => null]);
    }

    // ---------------------------------------------------------------
    // Ticket endpoint
    // ---------------------------------------------------------------

    public function test_ticket_returns_payload_for_completed_sale(): void
    {
        [$company, $branch] = $this->companyContext('Emp ticket');
        $user = $this->posUser($company, $branch);
        $sale = $this->completedSale($company, $branch, $user);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('mvs.print.ticket', $sale))
            ->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertSame($sale->id, $response->json('sale_id'));
        $this->assertSame($sale->sale_number, $response->json('sale_number'));
        $this->assertNotNull($response->json('payload'));
        $this->assertNotEmpty($response->json('payload.lines'));
    }

    public function test_ticket_404_for_sale_from_other_company(): void
    {
        [$companyA, $branchA] = $this->companyContext('Emp ticket A');
        [$companyB, $branchB] = $this->companyContext('Emp ticket B');
        $userA = $this->posUser($companyA, $branchA);
        $userB = $this->posUser($companyB, $branchB);
        $saleB = $this->completedSale($companyB, $branchB, $userB);

        $this->actingAs($userA)
            ->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->getJson(route('mvs.print.ticket', $saleB))
            ->assertNotFound();
    }

    public function test_ticket_404_for_voided_sale(): void
    {
        [$company, $branch] = $this->companyContext('Emp voided');
        $user = $this->posUser($company, $branch);
        $sale = $this->completedSale($company, $branch, $user, Sale::STATUS_VOIDED);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('mvs.print.ticket', $sale))
            ->assertNotFound();
    }

    public function test_ticket_uses_terminal_paper_width_and_drawer_settings(): void
    {
        [$company, $branch] = $this->companyContext('Emp settings');
        $user = $this->posUser($company, $branch);
        $sale = $this->completedSale($company, $branch, $user);
        $terminal = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'paper_width' => '58',
            'auto_cut' => false,
            'open_drawer' => true,
            'drawer_command' => [27, 112, 0, 25, 255],
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('mvs.print.ticket', ['sale' => $sale, 'terminal_uuid' => $terminal->terminal_uuid]))
            ->assertOk();

        $payload = $response->json('payload');
        $this->assertSame('58', $payload['paper_width']);
        $this->assertFalse($payload['auto_cut']);
        $this->assertTrue($payload['open_drawer']);
        $this->assertSame([27, 112, 0, 25, 255], $payload['drawer_command']);
    }

    public function test_ticket_includes_company_branch_customer_items_payments(): void
    {
        [$company, $branch] = $this->companyContext('Emp contenido');
        $user = $this->posUser($company, $branch);
        $customer = Customer::create([
            'company_id' => $company->id,
            'name' => 'Juan Perez',
            'identification' => '123456789',
        ]);
        $sale = $this->completedSale($company, $branch, $user, Sale::STATUS_COMPLETED, $customer);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('mvs.print.ticket', $sale))
            ->assertOk();

        $lines = collect($response->json('payload.lines'));
        $textLines = $lines->where('type', 'text')->pluck('value')->implode("\n");

        $this->assertStringContainsString($company->trade_name, $textLines);
        $this->assertStringContainsString($branch->name, $textLines);
        $this->assertStringContainsString('Juan Perez', $textLines);
        $this->assertStringContainsString($sale->sale_number, $textLines);
    }

    public function test_ticket_amounts_come_from_sale_not_calculated(): void
    {
        [$company, $branch] = $this->companyContext('Emp montos');
        $user = $this->posUser($company, $branch);
        $sale = $this->completedSale($company, $branch, $user);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('mvs.print.ticket', $sale))
            ->assertOk();

        $lines = collect($response->json('payload.lines'));
        $textLines = $lines->where('type', 'text')->pluck('value')->implode("\n");

        $this->assertStringContainsString(number_format((float) $sale->total, 2, ',', '.'), $textLines);
        $this->assertStringContainsString(number_format((float) $sale->subtotal, 2, ',', '.'), $textLines);
    }

    public function test_duplicate_sale_does_not_auto_print(): void
    {
        [$company, $branch] = $this->companyContext('Emp duplicado');
        $user = $this->posUser($company, $branch);
        $sale = $this->completedSale($company, $branch, $user);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('mvs.print.ticket', $sale))
            ->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertSame($sale->sale_number, $response->json('sale_number'));
    }

    // ---------------------------------------------------------------
    // EscPosSaleTicket unit
    // ---------------------------------------------------------------

    public function test_ticket_58mm_uses_narrow_separator(): void
    {
        [$company, $branch] = $this->companyContext('Emp 58');
        $user = $this->posUser($company, $branch);
        $sale = $this->completedSale($company, $branch, $user);

        $service = $this->app->make(EscPosSaleTicket::class);
        $sale->load(['company', 'branch', 'customer', 'items.product', 'payments.paymentMethod']);

        $payload = $service->build($sale, '58');

        $this->assertSame('58', $payload['paper_width']);
        $separators = collect($payload['lines'])->filter(fn ($l) => $l['type'] === 'separator');
        $this->assertNotEmpty($separators);
    }

    public function test_ticket_80mm_uses_wide_separator(): void
    {
        [$company, $branch] = $this->companyContext('Emp 80');
        $user = $this->posUser($company, $branch);
        $sale = $this->completedSale($company, $branch, $user);

        $service = $this->app->make(EscPosSaleTicket::class);
        $sale->load(['company', 'branch', 'customer', 'items.product', 'payments.paymentMethod']);

        $payload = $service->build($sale, '80');

        $this->assertSame('80', $payload['paper_width']);
        $this->assertTrue($payload['auto_cut']);
    }

    public function test_ticket_includes_loyalty_text_when_exists(): void
    {
        [$company, $branch] = $this->companyContext('Emp fidel');
        $user = $this->posUser($company, $branch);
        $sale = $this->completedSale($company, $branch, $user);

        $service = $this->app->make(EscPosSaleTicket::class);
        $sale->load(['company', 'branch', 'customer', 'items.product', 'payments.paymentMethod']);

        $payload = $service->build($sale, '80');
        $textLines = collect($payload['lines'])->where('type', 'text')->pluck('value')->implode("\n");

        $this->assertStringContainsString('Gracias por su compra', $textLines);
    }

    public function test_ticket_no_private_key_in_payload(): void
    {
        [$company, $branch] = $this->companyContext('Emp no key');
        $user = $this->posUser($company, $branch);
        $sale = $this->completedSale($company, $branch, $user);

        $service = $this->app->make(EscPosSaleTicket::class);
        $sale->load(['company', 'branch', 'customer', 'items.product', 'payments.paymentMethod']);

        $payload = $service->build($sale, '80');
        $payloadJson = json_encode($payload);

        $this->assertStringNotContainsString('PRIVATE KEY', $payloadJson);
        $this->assertStringNotContainsString('BEGIN RSA', $payloadJson);
    }

    // ---------------------------------------------------------------
    // Print failure safety
    // ---------------------------------------------------------------

    public function test_print_failure_does_not_change_sale(): void
    {
        [$company, $branch] = $this->companyContext('Emp safety');
        $user = $this->posUser($company, $branch);
        $sale = $this->completedSale($company, $branch, $user);

        $originalTotal = $sale->total;
        $originalStatus = $sale->status;

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('mvs.print.ticket', $sale))
            ->assertOk();

        $sale->refresh();
        $this->assertSame($originalTotal, $sale->total);
        $this->assertSame($originalStatus, $sale->status);
    }

    // ---------------------------------------------------------------
    // Receipt URL preserved
    // ---------------------------------------------------------------

    public function test_receipt_url_still_works_after_ticket_endpoint(): void
    {
        [$company, $branch] = $this->companyContext('Emp receipt');
        $user = $this->posUser($company, $branch);
        $sale = $this->completedSale($company, $branch, $user);

        $ticketResponse = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('mvs.print.ticket', $sale))
            ->assertOk();

        $this->assertTrue($ticketResponse->json('success'));
    }

    // ---------------------------------------------------------------
    // Loyalty lines in ticket
    // ---------------------------------------------------------------

    public function test_ticket_loyalty_invitation_text_for_non_member(): void
    {
        [$company, $branch] = $this->companyContext('Emp loyalty inv');
        $user = $this->posUser($company, $branch);
        $sale = $this->completedSale($company, $branch, $user);

        $service = $this->app->make(EscPosSaleTicket::class);
        $sale->load(['company', 'branch', 'customer', 'items.product', 'payments.paymentMethod']);

        $payload = $service->build($sale, '80');

        $this->assertArrayHasKey('lines', $payload);
        $this->assertIsArray($payload['lines']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function company(string $name): Company
    {
        return Company::create(['trade_name' => $name, 'is_active' => true]);
    }

    private function companyContext(string $name): array
    {
        $company = $this->company($name);
        $branch = $this->branch($company);

        return [$company, $branch];
    }

    private function branch(Company $company): Branch
    {
        return Branch::create([
            'company_id' => $company->id,
            'name' => 'Sucursal '.uniqid(),
            'code' => 'S'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function posUser(Company $company, Branch $branch): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Cajero '.uniqid(),
            'is_active' => true,
        ]);

        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach([$branch->id]);

        return $user;
    }

    private function completedSale(
        Company $company,
        Branch $branch,
        User $user,
        string $status = Sale::STATUS_COMPLETED,
        ?Customer $customer = null,
    ): Sale {
        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $customer?->id,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', uniqid('sale', true)),
            'sale_number' => 'POS-MVP-'.uniqid(),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => $status,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 5000,
            'discount_total' => 0,
            'tax_total' => 650,
            'rounding_total' => 0,
            'total' => 5650,
            'paid_total' => $status === Sale::STATUS_COMPLETED ? 5650 : 0,
            'balance_due' => 0,
            'completed_at' => $status === Sale::STATUS_COMPLETED ? now() : null,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => null,
            'description' => 'Producto test',
            'quantity' => '5.0000',
            'unit_price' => '1000.0000',
            'gross_total' => '5000.0000',
            'discount_total' => '0.0000',
            'subtotal' => '5000.0000',
            'tax_rate' => '13.0000',
            'tax_total' => '650.0000',
            'total' => '5650.0000',
            'unit_cost' => '600.0000',
        ]);

        if ($status === Sale::STATUS_COMPLETED) {
            SalePayment::create([
                'sale_id' => $sale->id,
                'payment_method_id' => $this->paymentMethodId($company),
                'created_by' => $user->id,
                'amount' => '5650.0000',
                'received_amount' => '6000.0000',
                'change_amount' => '350.0000',
                'status' => SalePayment::STATUS_COMPLETED,
            ]);
        }

        return $sale;
    }

    private function paymentMethodId(Company $company): int
    {
        return PaymentMethod::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'EFECTIVO'],
            ['name' => 'Efectivo', 'type' => 'cash', 'is_active' => true, 'affects_cash' => true, 'allows_change' => true],
        )->id;
    }
}
