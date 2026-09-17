<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\LayawayItem;
use App\Models\LayawayPayment;
use App\Models\MvsPrint\MvsPrintTerminal;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MvsPrintLayawayTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * A. COMPROBANTE INICIAL
     */
    public function test_layaway_ticket_payload_contains_company_branch_number_customer_items_total_prima_balance_method_expiry(): void
    {
        [$company, $branch, $user, $cash, $session, $layaway, $payment, $customer, $product] = $this->layawayContext(initialAmount: 200, receivedAmount: 500);

        $response = $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('mvs.print.ticket.layaway', $layaway))
            ->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertSame($layaway->id, $response->json('layaway_id'));
        $this->assertSame($layaway->number, $response->json('layaway_number'));

        $lines = collect($response->json('payload.lines'));
        $text = $lines->where('type', 'text')->pluck('value')->implode("\n");

        $this->assertStringContainsString($company->trade_name, $text);
        $this->assertStringContainsString($branch->name, $text);
        $this->assertStringContainsString($layaway->number, $text);
        $this->assertStringContainsString($customer->name, $text);
        $this->assertStringContainsString($product->name, $text);
        $this->assertStringContainsString(number_format((float) $layaway->total, 0, ',', '.'), $text);
        $this->assertStringContainsString('Prima: '.self::CRC().' '.number_format((float) $payment->amount, 0, ',', '.'), $text);
        $this->assertStringContainsString('Saldo pendiente: '.self::CRC().' '.number_format((float) $layaway->balance_due, 0, ',', '.'), $text);
        $this->assertStringContainsString($cash->name, $text);
        $this->assertStringContainsString('Vence: '.$layaway->expires_at->format('d/m/Y'), $text);
    }

    /**
     * B. EFECTIVO
     */
    public function test_cash_layaway_ticket_includes_received_and_change_amounts_and_allows_drawer(): void
    {
        [$company, $branch, $user, $cash, $session, $layaway, $payment] = $this->layawayContext(initialAmount: 200, receivedAmount: 500);
        $terminal = $this->terminal($company, $branch, openDrawer: true);

        $response = $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('mvs.print.ticket.layaway', ['layaway' => $layaway, 'terminal_uuid' => $terminal->terminal_uuid]))
            ->assertOk();

        $payload = $response->json('payload');
        $lines = collect($payload['lines']);
        $text = $lines->where('type', 'text')->pluck('value')->implode("\n");

        $this->assertStringContainsString('Recibido: '.self::CRC().' '.number_format((float) $payment->received_amount, 0, ',', '.'), $text);
        $this->assertStringContainsString('Vuelto:   '.self::CRC().' '.number_format((float) $payment->change_amount, 0, ',', '.'), $text);
        $this->assertSame('200.0000', (string) $payment->amount);
        $this->assertSame('500.0000', (string) $payment->received_amount);
        $this->assertSame('300.0000', (string) $payment->change_amount);
        $this->assertTrue($payload['open_drawer']);
    }

    /**
     * C. NO EFECTIVO
     */
    public function test_non_cash_layaway_ticket_hides_received_change_and_keeps_drawer_closed(): void
    {
        [$company, $branch, $user, , $session] = $this->baseContext();
        $card = $this->paymentMethod($company, 'Tarjeta', PaymentMethod::TYPE_CARD, allowsChange: false);
        $layaway = $this->createLayaway($company, $branch, $user, $card, $session, initialAmount: 200);
        $payment = $layaway->payments->first();
        $terminal = $this->terminal($company, $branch, openDrawer: true);

        $response = $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('mvs.print.ticket.layaway', ['layaway' => $layaway, 'terminal_uuid' => $terminal->terminal_uuid]))
            ->assertOk();

        $payload = $response->json('payload');
        $lines = collect($payload['lines']);
        $text = $lines->where('type', 'text')->pluck('value')->implode("\n");

        $this->assertStringContainsString($card->name, $text);
        $this->assertStringNotContainsString('Recibido:', $text);
        $this->assertStringNotContainsString('Vuelto:', $text);
        $this->assertFalse($payload['open_drawer']);
        $this->assertSame('200.0000', (string) $payment->received_amount);
        $this->assertSame('0.0000', (string) $payment->change_amount);
    }

    /**
     * D. ABONO
     */
    public function test_payment_ticket_payload_contains_amount_previous_balance_current_balance_reference_user_customer(): void
    {
        [$company, $branch, $user, $cash, $session, $layaway, $firstPayment] = $this->layawayContext(initialAmount: 200, receivedAmount: 200);

        // Second payment of 500
        $secondPayment = $this->pay($layaway, $cash, $session, $user, amount: 500, receivedAmount: 1000, reference: 'REF-123');
        $layaway->refresh();

        $response = $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('mvs.print.ticket.layaway.payment', ['layaway' => $layaway, 'payment' => $secondPayment]))
            ->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertSame($secondPayment->id, $response->json('payment_id'));

        $lines = collect($response->json('payload.lines'));
        $text = $lines->where('type', 'text')->pluck('value')->implode("\n");

        $previousBalance = '1800.0000';
        $currentBalance = '1300.0000';

        $this->assertStringContainsString('COMPROBANTE DE ABONO', $text);
        $this->assertStringContainsString(self::CRC().' '.number_format(500, 0, ',', '.'), $text);
        $this->assertStringContainsString('Ref: REF-123', $text);
        $this->assertStringContainsString($customer->name ?? $layaway->customer->name, $text);
        $this->assertStringContainsString($user->name, $text);
        $this->assertStringContainsString('Saldo pendiente: '.self::CRC().' '.number_format((float) $currentBalance, 0, ',', '.'), $text);
        $this->assertStringContainsString('Metodo: '.$cash->name, $text);
    }

    /**
     * E. REIMPRESIÓN
     */
    public function test_reprint_is_read_only_and_does_not_create_records_modify_balance_inventory_or_open_drawer(): void
    {
        [$company, $branch, $user, $cash, $session, $layaway, $payment] = $this->layawayContext(initialAmount: 200, receivedAmount: 500);
        $terminal = $this->terminal($company, $branch, openDrawer: true, paperWidth: '58');

        $beforeCounts = [
            'layaways' => Layaway::count(),
            'layaway_payments' => LayawayPayment::count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
        ];
        $originalBalance = $layaway->balance_due;
        $originalStock = DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $layaway->items->first()->product_id)
            ->value('stock');

        $response = $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('mvs.print.ticket.layaway', ['layaway' => $layaway, 'terminal_uuid' => $terminal->terminal_uuid, 'reprint' => 1]))
            ->assertOk();

        $payload = $response->json('payload');
        $this->assertSame('58', $payload['paper_width']);
        $this->assertTrue($payload['auto_cut']);
        $this->assertFalse($payload['open_drawer']);

        $this->assertSame($beforeCounts['layaways'], Layaway::count());
        $this->assertSame($beforeCounts['layaway_payments'], LayawayPayment::count());
        $this->assertSame($beforeCounts['inventory_movements'], DB::table('inventory_movements')->count());
        $this->assertSame($originalBalance, $layaway->fresh()->balance_due);
        $this->assertSame($originalStock, DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $layaway->items->first()->product_id)
            ->value('stock'));
    }

    /**
     * F. SEGURIDAD
     */
    public function test_layaway_ticket_blocks_other_company(): void
    {
        [$companyA, $branchA, $userA] = $this->baseContext('Empresa A');
        [$companyB, $branchB, $userB] = $this->baseContext('Empresa B');
        $layawayB = $this->createLayaway(
            $companyB,
            $branchB,
            $userB,
            $this->paymentMethod($companyB, 'Efectivo B', PaymentMethod::TYPE_CASH, code: 'EFECTIVO-B'),
            $this->openSession($companyB, $branchB, $userB),
            initialAmount: 100,
        );

        $this->actingAs($userA)
            ->withSession($this->activeSession($companyA, $branchA))
            ->getJson(route('mvs.print.ticket.layaway', $layawayB))
            ->assertNotFound();
    }

    public function test_layaway_ticket_blocks_other_branch(): void
    {
        [$company, $branchA, $user] = $this->baseContext('Empresa');
        $branchB = $this->branch($company, 'Secundaria');
        $user->branches()->attach($branchB);
        $layawayB = $this->createLayaway(
            $company,
            $branchB,
            $user,
            $this->paymentMethod($company, 'Efectivo A', PaymentMethod::TYPE_CASH, code: 'EFECTIVO-A'),
            $this->openSession($company, $branchB, $user),
            initialAmount: 100,
        );

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branchA))
            ->getJson(route('mvs.print.ticket.layaway', $layawayB))
            ->assertNotFound();
    }

    public function test_layaway_ticket_blocks_unauthenticated_users(): void
    {
        [$company, $branch, $user, $cash, $session, $layaway] = $this->layawayContext(initialAmount: 100);

        // Not authenticated
        $this->withSession($this->activeSession($company, $branch))
            ->getJson(route('mvs.print.ticket.layaway', $layaway))
            ->assertRedirect();
    }

    public function test_payment_ticket_blocks_payment_belonging_to_another_layaway(): void
    {
        [$company, $branch, $user, $cash, $session, $layawayA] = $this->layawayContext(initialAmount: 100);
        $layawayB = $this->createLayaway($company, $branch, $user, $cash, $session, initialAmount: 100);
        $paymentB = $layawayB->payments->first();

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('mvs.print.ticket.layaway.payment', ['layaway' => $layawayA, 'payment' => $paymentB]))
            ->assertNotFound();
    }

    /**
     * G. FORMATO
     */
    public function test_layaway_ticket_58mm_and_80mm_use_colon_symbol_no_mojibake_and_word_wrap(): void
    {
        [$company, $branch, $user, $cash, $session, $layaway] = $this->layawayContext(initialAmount: 200);
        $company->update([
            'legal_name' => 'Comercio MVS Sociedad Anonima de Responsabilidad Limitada',
            'address' => 'Avenida Central, Calle Tres, San José, Costa Rica',
        ]);
        $branch->update(['name' => 'Sucursal Principal Centro de San José']);

        foreach (['58', '80'] as $width) {
            $terminal = $this->terminal($company, $branch, paperWidth: $width);

            $response = $this->actingAs($user)
                ->withSession($this->activeSession($company, $branch))
                ->getJson(route('mvs.print.ticket.layaway', ['layaway' => $layaway, 'terminal_uuid' => $terminal->terminal_uuid]))
                ->assertOk();

            $payload = $response->json('payload');
            $this->assertSame($width, $payload['paper_width']);

            $textLines = collect($payload['lines'])->where('type', 'text')->pluck('value');
            $joined = $textLines->implode("\n");

            // Símbolo de colones
            $this->assertStringContainsString('₡', $joined);
            $this->assertStringNotContainsString('CRC', $joined);
            $this->assertStringNotContainsString('â', $joined);

            // Word wrap: ninguna línea supera el ancho correspondiente
            $max = $width === '58' ? 32 : 48;
            foreach ($textLines as $line) {
                if (mb_strlen($line) > $max) {
                    // La única excepción permitida es la fila fija de detalle en 80mm.
                    $this->assertSame('80', $width);
                    $this->assertStringContainsString(' x ', $line);
                }
            }
        }
    }

    public function test_payment_ticket_58mm_and_80mm_use_colon_symbol_no_mojibake_and_word_wrap(): void
    {
        [$company, $branch, $user, $cash, $session, $layaway] = $this->layawayContext(initialAmount: 200, receivedAmount: 500);
        $payment = $this->pay($layaway, $cash, $session, $user, amount: 300, receivedAmount: 300);
        $company->update(['legal_name' => 'Comercio MVS Sociedad Anonima de Responsabilidad Limitada']);

        foreach (['58', '80'] as $width) {
            $terminal = $this->terminal($company, $branch, paperWidth: $width);

            $response = $this->actingAs($user)
                ->withSession($this->activeSession($company, $branch))
                ->getJson(route('mvs.print.ticket.layaway.payment', ['layaway' => $layaway, 'payment' => $payment, 'terminal_uuid' => $terminal->terminal_uuid]))
                ->assertOk();

            $payload = $response->json('payload');
            $this->assertSame($width, $payload['paper_width']);

            $textLines = collect($payload['lines'])->where('type', 'text')->pluck('value');
            $joined = $textLines->implode("\n");

            $this->assertStringContainsString('₡', $joined);
            $this->assertStringNotContainsString('CRC', $joined);
            $this->assertStringNotContainsString('â', $joined);

            $max = $width === '58' ? 32 : 48;
            foreach ($textLines as $line) {
                if (mb_strlen($line) > $max) {
                    $this->assertSame('80', $width);
                    $this->assertStringContainsString(' x ', $line);
                }
            }
        }
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private static function CRC(): string
    {
        return '₡';
    }

    private function baseContext(string $name = 'Empresa'): array
    {
        $company = Company::create([
            'trade_name' => $name.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'layaway_validity_days' => 30,
            'layaway_alert_days' => 5,
            'is_active' => true,
        ]);
        $branch = $this->branch($company, 'Principal');
        $user = $this->user($company, $branch, ['pos.acceder', 'ventas.crear', 'apartados.ver', 'apartados.crear', 'apartados.abonar']);

        $cash = $this->paymentMethod($company, 'Efectivo', PaymentMethod::TYPE_CASH);
        $session = $this->openSession($company, $branch, $user);

        return [$company, $branch, $user, $cash, $session];
    }

    private function layawayContext(float $initialAmount = 200, ?float $receivedAmount = null): array
    {
        [$company, $branch, $user, $cash, $session] = $this->baseContext();
        $layaway = $this->createLayaway($company, $branch, $user, $cash, $session, initialAmount: $initialAmount, receivedAmount: $receivedAmount);
        $payment = $layaway->payments->first();

        return [$company, $branch, $user, $cash, $session, $layaway, $payment, $layaway->customer, $layaway->items->first()->product];
    }

    private function createLayaway(
        Company $company,
        Branch $branch,
        User $user,
        PaymentMethod $method,
        CashSession $session,
        float $initialAmount = 200,
        ?float $receivedAmount = null,
    ): Layaway {
        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'name' => 'Cliente '.uniqid(),
            'is_active' => true,
        ]);

        $product = $this->product($company);
        $this->stock($branch, $product, 10);

        $total = 2000;
        $balance = $total - $initialAmount;

        $layaway = Layaway::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'number' => 'APT-'.uniqid(),
            'status' => Layaway::STATUS_ACTIVE,
            'currency_code' => 'CRC',
            'total' => $total,
            'paid_total' => $initialAmount,
            'balance_due' => $balance,
            'expires_at' => today()->addDays(30),
        ]);

        LayawayItem::create([
            'layaway_id' => $layaway->id,
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => 2,
            'unit_price' => 1000,
            'tax_rate' => 0,
            'subtotal' => 2000,
            'tax_total' => 0,
            'total' => 2000,
        ]);

        $this->createPayment($layaway, $method, $session, $user, $initialAmount, $receivedAmount);

        return $layaway->load(['items.product', 'payments.paymentMethod', 'customer']);
    }

    private function pay(
        Layaway $layaway,
        PaymentMethod $method,
        CashSession $session,
        User $user,
        float $amount,
        ?float $receivedAmount = null,
        ?string $reference = null,
    ): LayawayPayment {
        $payment = $this->createPayment($layaway, $method, $session, $user, $amount, $receivedAmount, $reference);

        $paid = round((float) $layaway->paid_total + $amount, 4);
        $balance = max(0, round((float) $layaway->total - $paid, 4));
        $layaway->update([
            'paid_total' => $paid,
            'balance_due' => $balance,
            'status' => $balance <= 0 ? Layaway::STATUS_PAID : Layaway::STATUS_ACTIVE,
            'paid_at' => $balance <= 0 ? now() : null,
        ]);

        return $payment;
    }

    private function createPayment(
        Layaway $layaway,
        PaymentMethod $method,
        CashSession $session,
        User $user,
        float $amount,
        ?float $receivedAmount = null,
        ?string $reference = null,
    ): LayawayPayment {
        if ($method->allows_change && $receivedAmount !== null) {
            $received = $receivedAmount;
            $change = $received - $amount;
        } elseif ($method->allows_change) {
            $received = $amount;
            $change = 0;
        } else {
            $received = $amount;
            $change = 0;
        }

        return LayawayPayment::create([
            'layaway_id' => $layaway->id,
            'company_id' => $layaway->company_id,
            'branch_id' => $layaway->branch_id,
            'user_id' => $user->id,
            'cash_session_id' => $session->id,
            'payment_method_id' => $method->id,
            'amount' => $amount,
            'received_amount' => $received,
            'change_amount' => $change,
            'affects_cash_snapshot' => (bool) $method->affects_cash,
            'cash_effect_amount' => $method->affects_cash ? $amount : 0,
            'reference' => $reference,
            'paid_at' => now(),
        ]);
    }

    private function paymentMethod(Company $company, string $name, string $type, bool $allowsChange = true, ?string $code = null): PaymentMethod
    {
        return PaymentMethod::create([
            'company_id' => $company->id,
            'code' => $code ?? strtoupper($name),
            'name' => $name,
            'type' => $type,
            'is_active' => true,
            'affects_cash' => $type === PaymentMethod::TYPE_CASH,
            'allows_change' => $allowsChange,
        ]);
    }

    private function openSession(Company $company, Branch $branch, User $user): CashSession
    {
        $register = CashRegister::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'code' => 'C'.uniqid(),
            'name' => 'Caja',
            'is_active' => true,
        ]);

        return CashSession::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'session_number' => 'CAJA-'.uniqid(),
            'opened_by' => $user->id,
            'status' => CashSession::STATUS_OPEN,
            'open_guard' => CashSession::OPEN_GUARD,
            'currency_code' => 'CRC',
            'opening_amount' => '0',
            'opened_at' => now(),
        ]);
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create([
            'company_id' => $company->id,
            'name' => $name,
            'code' => $name.'-'.$company->id,
            'is_active' => true,
        ]);
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Rol '.uniqid(),
            'is_active' => true,
        ]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company, ['role_id' => $role->id]);
        $user->branches()->attach($branch);

        return $user;
    }

    private function product(Company $company): Product
    {
        $id = uniqid();
        $category = ProductCategory::create([
            'company_id' => $company->id,
            'name' => 'Cat '.$id,
            'slug' => 'cat-'.$id,
            'is_active' => true,
        ]);
        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Unidad',
            'abbreviation' => 'U',
            'slug' => 'u-'.$id,
            'allows_decimals' => false,
            'is_active' => true,
        ]);

        return Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$id,
            'internal_code' => 'P-'.$id,
            'cost' => 500,
            'sale_price' => 1000,
            'tax_rate' => 0,
            'track_inventory' => true,
            'is_active' => true,
        ]);
    }

    private function stock(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => $stock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function terminal(Company $company, Branch $branch, string $paperWidth = '80', bool $openDrawer = false): MvsPrintTerminal
    {
        return MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'printer_name' => 'POS-58-Series',
            'paper_width' => $paperWidth,
            'auto_cut' => true,
            'open_drawer' => $openDrawer,
            'drawer_command' => [27, 112, 0, 25, 255],
            'enabled' => true,
        ]);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
