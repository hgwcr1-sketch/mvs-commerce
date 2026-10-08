<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosReceipt;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cierre de UX del cobro (6B): "paga con"/vuelto informativo, recibo formal
 * print-friendly (sin chrome de la app) y acciones grandes WhatsApp/impresión.
 *
 * Reglas certificadas aquí:
 * - paga con < monto se rechaza; el vuelto NO aumenta el pago ni reduce más CxC;
 * - la impresión renderiza SOLO el recibo formal, con los datos reales;
 * - WhatsApp usa el teléfono real del cliente y nunca envía cédulas.
 */
class CobrosReceiptUXTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_tendered_is_validated_and_change_does_not_touch_money(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '1500.0000');
        $cash = $this->method($c, 'cash');

        // "Paga con" menor al monto: rechazado en preview y en confirmación, sin escrituras.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(route('cobros.collector.payments.store', $account), [
            'amount' => '100', 'payment_method_id' => $cash->id, 'tendered' => '50', 'client_token' => (string) Str::uuid(),
        ])->assertSessionHasErrors('tendered');

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(route('cobros.collector.payments.store', $account), [
            'amount' => '100', 'payment_method_id' => $cash->id, 'tendered' => '50', 'client_token' => (string) Str::uuid(), 'confirm' => '1',
        ])->assertSessionHasErrors('tendered');

        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);

        // Preview válido: muestra paga con y vuelto; cero escrituras.
        $token = (string) Str::uuid();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(route('cobros.collector.payments.store', $account), [
            'amount' => '100', 'payment_method_id' => $cash->id, 'tendered' => '150', 'client_token' => $token,
        ])->assertOk()->assertSee('Paga con')->assertSee('Vuelto');
        $this->assertDatabaseCount('accounts_receivable_payments', 0);

        // Confirmación: solo se descuenta el monto cobrado; el vuelto es informativo.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(route('cobros.collector.payments.store', $account), [
            'amount' => '100', 'payment_method_id' => $cash->id, 'tendered' => '150', 'client_token' => $token, 'confirm' => '1',
        ])->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));

        $this->assertSame('1400.0000', $account->fresh()->balance_due);
        $this->assertSame('100.0000', (string) AccountReceivablePayment::sole()->amount);

        $receipt = CobrosReceipt::sole();
        $this->assertSame('1500.0000', $receipt->balance_before);
        $this->assertSame('1400.0000', $receipt->balance_after);
        $this->assertSame('150.0000', $receipt->tendered_amount);
        $this->assertSame('50.0000', $receipt->change_amount);
    }

    public function test_print_view_renders_only_the_formal_receipt_with_real_data(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '1500.0000');
        $this->pay($u, $account, '100', '150');
        $receipt = CobrosReceipt::sole();

        // Pantalla del recibo: acciones grandes + enlace a la vista de impresión.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.receipt', $receipt->receipt_number))
            ->assertOk()
            ->assertSee('ENVIAR POR WHATSAPP')
            ->assertSee('🖨️ IMPRIMIR')
            ->assertSee(route('cobros.collector.payments.receipt.print', $receipt->receipt_number), false);

        $html = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.receipt.print', $receipt->receipt_number))
            ->assertOk()
            ->getContent();

        // Datos reales del recibo formal.
        foreach ([
            'Recibo de cobro',
            $receipt->receipt_number,
            $receipt->paid_at->timezone($c->timezone)->format('d/m/Y H:i'),
            $c->trade_name,
            $customer->name,
            $u->name,
            $this->method($c, 'cash')->name,
            'Saldo anterior',
            '1,500.00',
            'Monto abonado',
            '100.00',
            'Saldo nuevo',
            '1,400.00',
            'Paga con',
            '150.00',
            'Vuelto',
            '50.00',
            'Próxima fecha de vencimiento',
            $account->fresh()->due_date->format('d/m/Y'),
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, 'Falta: '.$expected);
        }

        // Sin chrome de la aplicación: nada de navbar, menús ni footer.
        foreach (['cobros-header', 'cobros-main', 'Menú CobrOS', 'Ir al contenido', 'cobros-navigation'] as $chrome) {
            $this->assertStringNotContainsString($chrome, $html, 'No debe imprimirse: '.$chrome);
        }
    }

    public function test_print_view_omits_next_due_when_there_is_no_reliable_information(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '1500.0000');
        // Pago total: saldo nuevo en cero => sin "próxima fecha" (no se inventan datos).
        $this->pay($u, $account, '1500', '1500');
        $receipt = CobrosReceipt::sole();

        $html = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.receipt.print', $receipt->receipt_number))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Saldo nuevo', $html);
        $this->assertStringContainsString('0.00', $html);
        $this->assertStringNotContainsString('Próxima fecha de vencimiento', $html);
    }

    public function test_whatsapp_uses_real_phone_and_never_sends_identification(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '1000.0000', [
            'phone_country_code' => '506', 'mobile' => '8888 1234', 'identification' => '3012345678',
        ]);
        $this->pay($u, $account, '100', '100');
        $receipt = CobrosReceipt::sole();

        $html = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.receipt', $receipt->receipt_number))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('#href="https://wa\.me/50688881234\?text=#', $html);

        preg_match('#href="(https://wa\.me/[^"]+)"#', $html, $matches);
        $this->assertNotEmpty($matches, 'Debe existir el enlace wa.me');
        $message = urldecode($matches[1]);
        foreach ([$receipt->receipt_number, $c->trade_name, 'Monto abonado', 'Saldo nuevo', 'Fecha'] as $expected) {
            $this->assertStringContainsString($expected, $message, 'Falta en el mensaje: '.$expected);
        }
        $this->assertStringNotContainsString('3012345678', $message, 'Nunca enviar cédulas');
        $this->assertStringNotContainsString($customer->identification, $html);

        // Sin teléfono: acción deshabilitada con explicación clara y sin enlace.
        [$c2, $b2, $u2] = $this->context();
        [$plain, $plainAccount] = $this->customerWithAccount($c2, $b2, $u2, '500.0000');
        $this->pay($u2, $plainAccount, '50', '50');

        $noPhone = $this->actingAs($u2)->withSession(['active_company_id' => $c2->id])
            ->get(route('cobros.collector.payments.receipt', CobrosReceipt::latest('id')->first()->receipt_number))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('wa.me/', $noPhone);
        $this->assertStringContainsString('Sin teléfono registrado en el cliente', $noPhone);
        $this->assertStringContainsString('disabled', $noPhone);
    }

    public function test_receipt_shows_no_internal_company_or_branch_labels(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '1500.0000');
        $this->pay($u, $account, '100', '150');
        $receipt = CobrosReceipt::sole();

        $print = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.receipt.print', $receipt->receipt_number))
            ->assertOk()->getContent();

        // Recibo formal: sin etiquetas internas de Empresa/Sucursal; branding discreto intacto.
        $this->assertStringNotContainsString('Empresa', $print);
        $this->assertStringNotContainsString('Sucursal', $print);
        $this->assertStringContainsString('MVS CobrOS', $print);
        foreach (['Cliente', 'Cobrador', 'Forma de pago', 'Saldo anterior', 'Monto abonado', 'Saldo nuevo', 'Paga con', 'Vuelto'] as $expected) {
            $this->assertStringContainsString($expected, $print, 'Falta: '.$expected);
        }

        $screen = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.receipt', $receipt->receipt_number))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('>Empresa</dt>', $screen);
        $this->assertStringNotContainsString('>Sucursal</dt>', $screen);
        foreach (['Cliente', 'Cobrador', 'Forma de pago', 'Monto cobrado'] as $expected) {
            $this->assertStringContainsString($expected, $screen, 'Falta: '.$expected);
        }
    }

    public function test_add_phone_from_receipt_is_authorized_and_enables_whatsapp(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '1000.0000', [
            'phone_country_code' => '506',
        ]);
        $this->pay($u, $account, '100', '100');
        $receipt = CobrosReceipt::sole();

        $before = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.receipt', $receipt->receipt_number))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('wa.me/', $before);
        $this->assertStringContainsString('AGREGAR TELÉFONO', $before);
        $this->assertStringContainsString('inputmode="tel"', $before);

        // Guardar el teléfono dentro de la misma empresa/cartera: vuelve al recibo.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.customer.phone', $customer),
            ['phone' => '8888 1234', 'receipt' => $receipt->receipt_number]
        )->assertRedirect(route('cobros.collector.payments.receipt', $receipt->receipt_number));

        $this->assertSame('88881234', $customer->fresh()->phone);

        // Habilitado de inmediato, sin tocar cobro ni saldos.
        $after = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.payments.receipt', $receipt->receipt_number))
            ->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#https://wa\.me/50688881234\?text=#', $after);
        $this->assertStringNotContainsString('AGREGAR TELÉFONO', $after);

        $this->assertSame('900.0000', $account->fresh()->balance_due);
        $this->assertDatabaseCount('accounts_receivable_payments', 1);
        $fresh = $receipt->fresh();
        $this->assertSame('1000.0000', $fresh->balance_before);
        $this->assertSame('900.0000', $fresh->balance_after);
    }

    public function test_phone_endpoint_enforces_tenant_assignment_and_validation(): void
    {
        [$c, $b, $u] = $this->context();
        [$mine, $mineAccount] = $this->customerWithAccount($c, $b, $u, '500.0000');
        $unassigned = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Libre '.uniqid(), 'is_active' => true]);
        [$foreignCompany, $foreignBranch, $foreignUser] = $this->context();
        [$foreign, $foreignAccount] = $this->customerWithAccount($foreignCompany, $foreignBranch, $foreignUser, '700.0000');

        // IDOR / tenant: cliente ajeno o sin asignar => 404 y sin escritura.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.customer.phone', $foreign),
            ['phone' => '+506 8888 9999']
        )->assertNotFound();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.customer.phone', $unassigned),
            ['phone' => '+506 8888 9999']
        )->assertNotFound();
        $this->assertNull($foreign->fresh()->phone);
        $this->assertNull($unassigned->fresh()->phone);

        // Sin permiso cobros.cobrar => 403.
        [$c3, $b3, $noPerm] = $this->context(['cobros.acceder']);
        $this->actingAs($noPerm)->withSession(['active_company_id' => $c3->id])->post(
            route('cobros.collector.customer.phone', $mine),
            ['phone' => '+506 8888 9999']
        )->assertForbidden();
        $this->assertNull($mine->fresh()->phone);

        // Válido: número internacional completo sin código de país configurado.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.customer.phone', $mine),
            ['phone' => '+50688881234']
        )->assertRedirect();
        $this->assertSame('50688881234', $mine->fresh()->phone);

        // Corto y sin prefijo de país: rechazado, sin inventar datos.
        $short = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Corto '.uniqid(), 'is_active' => true]);
        \App\Models\CobrosAssignment::create(['company_id' => $c->id, 'customer_id' => $short->id, 'collector_user_id' => $u->id, 'assigned_by' => $u->id, 'assigned_at' => now()]);
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.customer.phone', $short),
            ['phone' => '8888']
        )->assertSessionHasErrors('phone');
        $this->assertNull($short->fresh()->phone);
    }

    // ------------------------------------------------------------------
    // Helpers

    private function pay(User $u, AccountReceivable $account, string $amount, string $tendered): void
    {
        $company = Company::findOrFail((int) $account->company_id);

        $this->actingAs($u)->withSession(['active_company_id' => $company->id])->post(
            route('cobros.collector.payments.store', $account),
            [
                'amount' => $amount,
                'payment_method_id' => $this->method($company, 'cash')->id,
                'tendered' => $tendered,
                'client_token' => (string) Str::uuid(),
                'confirm' => '1',
            ]
        )->assertRedirect();
    }

    private function context(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        $company = Company::create(['trade_name' => 'Cobros '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'C'.random_int(1, 99), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cobrador '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);
        app(PaymentMethodProvisioner::class)->provision($company);

        return [$company->fresh(), $branch, $user];
    }

    private function method(Company $company, string $code): PaymentMethod
    {
        return PaymentMethod::query()->where('company_id', $company->id)->where('code', $code)->firstOrFail();
    }

    /** @return array{0: Customer, 1: AccountReceivable} */
    private function customerWithAccount(Company $c, Branch $b, User $u, string $balance, array $customerData = []): array
    {
        $customer = Customer::create([
            'company_id' => $c->id,
            'customer_type' => 'individual',
            'name' => $customerData['name'] ?? 'Cliente '.uniqid(),
            'identification' => $customerData['identification'] ?? null,
            'phone_country_code' => $customerData['phone_country_code'] ?? null,
            'phone' => $customerData['phone'] ?? null,
            'mobile' => $customerData['mobile'] ?? null,
            'is_active' => true,
        ]);
        CobrosAssignment::create([
            'company_id' => $c->id,
            'customer_id' => $customer->id,
            'collector_user_id' => $u->id,
            'assigned_by' => $u->id,
            'assigned_at' => now(),
        ]);

        $sale = Sale::create([
            'company_id' => $c->id, 'branch_id' => $b->id, 'user_id' => $u->id, 'customer_id' => $customer->id,
            'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now(),
        ]);
        $account = AccountReceivable::create([
            'company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id, 'sale_id' => $sale->id,
            'issued_at' => today()->subMonth(), 'due_date' => today($c->timezone)->addDays(7),
            'original_amount' => $balance, 'balance_due' => $balance, 'status' => 'pending', 'currency_code' => 'CRC',
        ]);

        return [$customer, $account];
    }
}
