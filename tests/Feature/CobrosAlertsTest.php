<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\Alert;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosReceipt;
use App\Models\CobrosVisit;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Notifications\MvsAlertNotification;
use App\Services\Cobros\CobrosAlertService;
use App\Services\CompanyLicenseService;
use App\Services\Notifications\AlertTypeRegistry;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 5A — notificaciones y alertas CobrOS.
 *
 * Reglas certificadas aquí: canal CobrOS del Centro de Notificaciones,
 * destinatarios solo dueño/supervisor con notificaciones.cobros, empresa y
 * sucursal respetadas, deduplicación de eventos y condiciones diarias, y
 * pantalla propia sin escrituras financieras.
 */
class CobrosAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_and_annulment_alert_owner_and_supervisor_only(): void
    {
        Notification::fake();
        [$c, $b, $owner] = $this->context('owner');
        [, , $supervisor] = $this->context('supervisor', null, $c, $b);
        [$collector, , $account] = $this->collectorWithAccount($c, $b, '5000.0000');
        $this->pay($c, $account, $collector, '2000.00');

        $registered = Alert::query()->where('company_id', $c->id)
            ->where('type', 'cobros.payment_registered')->first();
        $this->assertNotNull($registered);
        $this->assertSame($b->id, $registered->branch_id);
        $this->assertDatabaseHas('alert_recipients', ['alert_id' => $registered->id, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('alert_recipients', ['alert_id' => $registered->id, 'user_id' => $supervisor->id]);
        $this->assertDatabaseMissing('alert_recipients', ['alert_id' => $registered->id, 'user_id' => $collector->id]);
        Notification::assertSentTo($owner, MvsAlertNotification::class);
        Notification::assertNotSentTo($collector, MvsAlertNotification::class);

        // Reintento idéntico (mismo token/payload): no duplica alerta ni aviso.
        $receipt = CobrosReceipt::query()->forCompany($c->id)->sole();
        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            [
                'amount' => '2000.00', 'payment_method_id' => $this->method($c, 'cash')->id,
                'reference' => null, 'notes' => null, 'client_token' => $receipt->client_token, 'confirm' => '1',
            ]
        )->assertRedirect();
        $this->assertSame(1, Alert::query()->where('company_id', $c->id)->where('type', 'cobros.payment_registered')->count());
        Notification::assertSentToTimes($owner, MvsAlertNotification::class, 1);

        // Anulación: alerta de severidad ATENCION con su reverso.
        $annuller = $this->context('owner', 'anular', $c, $b)[2];
        $this->actingAs($annuller)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', $receipt->receipt_number),
            ['reason' => 'Anulación con alerta al dueño', 'client_token' => (string) Str::uuid()]
        )->assertRedirect(route('cobros.collector.payments.receipt', $receipt->receipt_number));

        $annulled = Alert::query()->where('company_id', $c->id)->where('type', 'cobros.payment_annulled')->first();
        $this->assertNotNull($annulled);
        $this->assertSame(Alert::SEVERITY_ATTENTION, $annulled->severity);
        $this->assertDatabaseHas('alert_recipients', ['alert_id' => $annulled->id, 'user_id' => $owner->id]);
        Notification::assertSentTo($owner, MvsAlertNotification::class);
    }

    public function test_recipients_require_notification_and_source_permissions(): void
    {
        Notification::fake();
        [$c, $b] = $this->context('owner');
        // Dueño sin notificaciones.cobros: no recibe.
        $withoutNotify = $this->user($c, $b, ['cobros.acceder', 'cobros.portal_dueno']);
        // Solo permiso de notificación, sin rol CobrOS de origen: no recibe.
        $notifyOnly = $this->user($c, $b, ['cobros.acceder', 'notificaciones.cobros', 'cobros.cobrar']);
        // Dueño completo: recibe.
        [$collector, , $account] = $this->collectorWithAccount($c, $b, '3000.0000');
        $this->pay($c, $account, $collector, '1000.00');

        $alert = Alert::query()->where('company_id', $c->id)->where('type', 'cobros.payment_registered')->sole();
        $this->assertDatabaseMissing('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $withoutNotify->id]);
        $this->assertDatabaseMissing('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $notifyOnly->id]);
        Notification::assertNotSentTo($withoutNotify, MvsAlertNotification::class);
        Notification::assertNotSentTo($notifyOnly, MvsAlertNotification::class);
    }

    public function test_preference_can_disable_cobros_alerts(): void
    {
        Notification::fake();
        [$c, $b, $owner] = $this->context('owner');
        $ownerPermission = Permission::where('name', 'notificaciones.cobros')->firstOrFail();
        app(NotificationPreferenceService::class)
            ->setPreference($c, 'cobros.payment_registered', false, null, null, $owner);

        [$collector, , $account] = $this->collectorWithAccount($c, $b, '1500.0000');
        $this->pay($c, $account, $collector, '500.00');

        $alert = Alert::query()->where('company_id', $c->id)->where('type', 'cobros.payment_registered')->sole();
        $this->assertDatabaseMissing('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $owner->id]);
        Notification::assertNotSentTo($owner, MvsAlertNotification::class);
        unset($ownerPermission);
    }

    public function test_evaluator_creates_condition_alerts_and_deduplicates(): void
    {
        Notification::fake();
        [$c, $b, $owner] = $this->context('owner');
        [$collector, $customer, $account] = $this->collectorWithAccount($c, $b, '4000.0000');
        $this->pay($c, $account, $collector, '1000.00');

        CobrosVisit::create([
            'company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id,
            'collector_user_id' => $collector->id, 'scheduled_for' => today($c->timezone)->toDateString(),
            'status' => CobrosVisit::STATUS_PROMISE, 'promise_date' => today($c->timezone)->subDay()->toDateString(),
            'promise_amount' => '500.0000',
        ]);
        CobrosVisit::create([
            'company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id,
            'collector_user_id' => $collector->id, 'scheduled_for' => today($c->timezone)->toDateString(),
            'status' => CobrosVisit::STATUS_NOT_FOUND,
        ]);
        $c->license()->firstOrFail()->update(['expires_at' => today()->addDays(10), 'status' => 'active']);

        $service = app(CobrosAlertService::class);
        $first = $service->evaluate($c->fresh());
        $this->assertSame(1, $first['promise_overdue']);
        $this->assertSame(1, $first['customer_not_found']);
        $this->assertSame(1, $first['custody_pending']);
        $this->assertSame(1, $first['license_expiring']);
        $this->assertSame(5, Alert::query()->where('company_id', $c->id)
            ->whereIn('type', CobrosAlertService::TYPES)->count());

        // Segunda pasada: deduplicado, ni alertas ni notificaciones nuevas.
        $second = $service->evaluate($c->fresh());
        $this->assertSame(0, array_sum($second));
        $this->assertSame(5, Alert::query()->where('company_id', $c->id)
            ->whereIn('type', CobrosAlertService::TYPES)->count());
        Notification::assertSentToTimes($owner, MvsAlertNotification::class, 5);

        // Sucursal correcta en las alertas con origen en visita/cobro.
        $this->assertSame($b->id, Alert::query()->where('company_id', $c->id)->where('type', 'cobros.promise_overdue')->sole()->branch_id);
        $this->assertSame($b->id, Alert::query()->where('company_id', $c->id)->where('type', 'cobros.custody_pending')->sole()->branch_id);
    }

    public function test_evaluator_respects_tenant_and_license_threshold(): void
    {
        Notification::fake();
        [$c, $b, $owner] = $this->context('owner');
        [$fc, $fb] = $this->context('supervisor');
        $foreignCollector = $this->user($fc, $fb, ['cobros.acceder', 'cobros.cobrar']);

        // Condición solo en la empresa ajena.
        $foreignCustomer = Customer::create(['company_id' => $fc->id, 'customer_type' => 'individual', 'name' => 'Ajeno '.uniqid(), 'is_active' => true]);
        CobrosVisit::create([
            'company_id' => $fc->id, 'branch_id' => $fb->id, 'customer_id' => $foreignCustomer->id,
            'collector_user_id' => $foreignCollector->id, 'scheduled_for' => today($fc->timezone)->toDateString(),
            'status' => CobrosVisit::STATUS_NOT_FOUND,
        ]);
        // Licencia lejana en la empresa propia: no alerta.
        $c->license()->firstOrFail()->update(['expires_at' => today()->addDays(60), 'status' => 'active']);

        $summary = app(CobrosAlertService::class)->evaluate($c->fresh());

        $this->assertSame(0, array_sum($summary));
        $this->assertSame(0, Alert::query()->where('company_id', $c->id)->count());
        $this->assertSame(0, Alert::query()->where('company_id', $fc->id)->count());
        unset($b, $owner);
    }

    public function test_alerts_screen_is_visible_only_to_owner_and_supervisor(): void
    {
        Notification::fake();
        [$c, $b, $owner] = $this->context('owner');
        [, , $supervisor] = $this->context('supervisor', null, $c, $b);
        [$collector, , $account] = $this->collectorWithAccount($c, $b, '2500.0000');
        $this->pay($c, $account, $collector, '2500.00');

        // Otra empresa con su propia alerta.
        [$fc, $fb] = $this->context('supervisor');
        [$foreignCollector, , $foreignAccount] = $this->collectorWithAccount($fc, $fb, '900.0000');
        $this->pay($fc, $foreignAccount, $foreignCollector, '900.00');
        $foreignReceipt = CobrosReceipt::query()->forCompany($fc->id)->sole();

        $ownerResponse = $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.alerts'))
            ->assertOk()->assertSee('Alertas CobrOS')->assertSee('Cobro registrado (CobrOS)')
            ->assertSee(CobrosReceipt::query()->forCompany($c->id)->sole()->receipt_number);

        // Asegura: el recibo del tenant propio no se resuelve por IDs de otro.
        $this->assertNotEmpty($foreignReceipt);
        $this->assertSame($fc->id, $foreignReceipt->company_id);

        // Asegura: el recibo del tenant ajeno no es reexpuesto por sole().
        $this->assertNotEmpty($foreignReceipt);
        $this->assertSame($fc->id, $foreignReceipt->company_id);

        // La alerta del cobro propio lleva su recibo visible en el historial.
        $this->assertStringContainsString('Recibo '.$foreignReceipt->receipt_number, $ownerResponse->getContent());

        $this->actingAs($supervisor)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.supervisor.alerts'))->assertOk()->assertSee('Alertas CobrOS');

        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.alerts'))->assertForbidden();
        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.supervisor.alerts'))->assertForbidden();
    }

    public function test_alerts_screen_never_writes_financial_data(): void
    {
        Notification::fake();
        [$c, $b, $owner] = $this->context('owner');
        [$collector, , $account] = $this->collectorWithAccount($c, $b, '3500.0000');
        $this->pay($c, $account, $collector, '700.00');

        $before = $this->financialSnapshot();
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.alerts'))->assertOk();
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertDatabaseCount('cash_sessions', 0);
    }

    public function test_evaluate_command_runs_for_active_companies(): void
    {
        Notification::fake();
        [$c, $b] = $this->context('owner');
        [$collector, $customer, $account] = $this->collectorWithAccount($c, $b, '2000.0000');
        $this->pay($c, $account, $collector, '400.00');
        CobrosVisit::create([
            'company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id,
            'collector_user_id' => $collector->id, 'scheduled_for' => today($c->timezone)->toDateString(),
            'status' => CobrosVisit::STATUS_NOT_FOUND,
        ]);

        $this->artisan('cobros:evaluate-alerts')->assertExitCode(0);

        $this->assertSame(1, Alert::query()->where('company_id', $c->id)
            ->where('type', 'cobros.customer_not_found')->count());
        $this->assertSame(1, Alert::query()->where('company_id', $c->id)
            ->where('type', 'cobros.custody_pending')->count());
    }

    public function test_registry_declares_the_cobros_channel_with_owner_scope(): void
    {
        foreach (CobrosAlertService::TYPES as $type) {
            $definition = AlertTypeRegistry::forType($type);
            $this->assertNotNull($definition, $type);
            $this->assertSame('CobrOS', $definition['module']);
            $this->assertSame('notificaciones.cobros', $definition['notification_permission']);
            $this->assertSame(['cobros.portal_dueno', 'cobros.supervisar'], $definition['source_permissions']);
        }
        $this->assertNotNull(Permission::where('name', 'notificaciones.cobros')->first());
    }

    private function financialSnapshot(): array
    {
        return [
            'accounts' => AccountReceivable::query()->orderBy('id')->get(['id', 'balance_due', 'status'])->toArray(),
            'payments' => AccountReceivablePayment::query()->orderBy('id')->get(['id', 'amount', 'cash_session_id'])->toArray(),
            'receipts' => CobrosReceipt::query()->orderBy('id')->get(['id', 'receipt_number', 'annulled_at'])->toArray(),
        ];
    }

    private function method(Company $c, string $code): PaymentMethod
    {
        return PaymentMethod::query()->where('company_id', $c->id)->where('code', $code)->firstOrFail();
    }

    private function pay(Company $c, AccountReceivable $account, User $collector, string $amount): void
    {
        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            [
                'amount' => $amount, 'payment_method_id' => $this->method($c, 'cash')->id, 'reference' => null,
                'notes' => null, 'client_token' => (string) Str::uuid(), 'confirm' => '1',
            ]
        )->assertRedirect();
    }

    /** @return array{0: User, 1: Customer, 2: AccountReceivable} */
    private function collectorWithAccount(Company $c, Branch $b, string $balance): array
    {
        $user = $this->user($c, $b, ['cobros.acceder', 'cobros.cobrar']);

        $customer = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true]);
        CobrosAssignment::create([
            'company_id' => $c->id, 'customer_id' => $customer->id,
            'collector_user_id' => $user->id, 'assigned_by' => $user->id, 'assigned_at' => now(),
        ]);
        $sale = Sale::create(['company_id' => $c->id, 'branch_id' => $b->id, 'user_id' => $user->id, 'customer_id' => $customer->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);
        $account = AccountReceivable::create(['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id, 'sale_id' => $sale->id, 'issued_at' => today()->subMonth(), 'due_date' => today($c->timezone)->addDays(7), 'original_amount' => $balance, 'balance_due' => $balance, 'status' => 'pending', 'currency_code' => 'CRC']);

        return [$user, $customer, $account];
    }

    private function user(Company $c, Branch $b, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $c->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($c->id, ['role_id' => $role->id]);
        $user->branches()->attach($b->id);

        return $user;
    }

    /**
     * Crea empresa+sucursal y (opcionalmente) su primer usuario con permisos.
     *
     * @return array{0: Company, 1: Branch, 2?: User}
     */
    private function context(string $role = 'owner', ?string $extra = null, ?Company $company = null, ?Branch $branch = null): array
    {
        $company ??= Company::create(['trade_name' => 'Cobros '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch ??= Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'A'.random_int(1, 99), 'is_active' => true]);

        $permissions = ['cobros.acceder', 'notificaciones.cobros'];
        $permissions[] = match ($role) {
            'owner' => 'cobros.portal_dueno',
            'supervisor' => 'cobros.supervisar',
            default => 'cobros.cobrar',
        };
        if ($extra === 'anular') {
            $permissions[] = 'cobros.anular';
        }

        $user = $this->user($company, $branch, $permissions);
        app(CompanyLicenseService::class)->updateModules(
            $company,
            User::factory()->create(['is_platform_admin' => true, 'is_active' => true]),
            ['cobros', 'notifications']
        );
        app(PaymentMethodProvisioner::class)->provision($company);

        return [$company->fresh(), $branch, $user];
    }
}
