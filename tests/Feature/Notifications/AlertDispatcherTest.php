<?php

namespace Tests\Feature\Notifications;

use App\Models\Alert;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Purchase;
use App\Models\PurchaseVerification;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\MvsAlertNotification;
use App\Services\Notifications\AlertDispatcher;
use App\Services\Notifications\AlertTypeRegistry;
use App\Services\Purchases\PurchaseVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AlertDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_creates_alert_and_notifies_only_authorized_users(): void
    {
        Notification::fake();
        [$company, $branch] = $this->company();
        $authorized = $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.verificar']);
        $unauthorized = $this->user($company, $branch, ['notificaciones.compras']);

        $alert = app(AlertDispatcher::class)->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branch));

        $this->assertNotNull($alert);
        $this->assertSame(Alert::STATUS_NEW, $alert->status);
        $this->assertDatabaseHas('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $authorized->id]);
        $this->assertDatabaseMissing('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $unauthorized->id]);
        Notification::assertSentTo($authorized, MvsAlertNotification::class);
        Notification::assertNotSentTo($unauthorized, MvsAlertNotification::class);
    }

    public function test_open_alert_is_deduplicated_and_does_not_renotify(): void
    {
        Notification::fake();
        [$company, $branch] = $this->company();
        $user = $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.verificar']);
        $dispatcher = app(AlertDispatcher::class);
        $payload = $this->payload($company, $branch, 'same-key');

        $first = $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $payload);
        $second = $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Alert::query()->count());
        $this->assertSame(1, $first->recipients()->where('user_id', $user->id)->count());
        Notification::assertSentToTimes($user, MvsAlertNotification::class, 1);
    }

    public function test_resolved_alert_can_be_created_again(): void
    {
        Notification::fake();
        [$company, $branch] = $this->company();
        $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.verificar']);
        $dispatcher = app(AlertDispatcher::class);
        $payload = $this->payload($company, $branch, 'reopen');

        $first = $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $payload);
        $first->update(['status' => Alert::STATUS_RESOLVED, 'resolved_at' => now()]);
        $second = $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $payload);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Alert::query()->count());
    }

    public function test_unknown_type_is_ignored(): void
    {
        [$company, $branch] = $this->company();

        $this->assertNull(app(AlertDispatcher::class)->dispatch('unknown_type', $this->payload($company, $branch)));
        $this->assertSame(0, Alert::query()->count());
    }

    public function test_assigning_purchase_verification_creates_in_app_alert_only(): void
    {
        Notification::fake();
        Mail::fake();
        [$company, $branch] = $this->company();
        $assigner = $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.asignar', 'compras.recepcion.verificar']);
        $assignee = $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.verificar']);
        $foreign = $this->user(...array_merge($this->company('Ajena'), [['notificaciones.compras', 'compras.recepcion.verificar']]));

        $purchase = $this->purchase($company, $branch, $assigner);
        $verification = app(PurchaseVerificationService::class)->assign($purchase, $assigner, $assignee);

        $this->assertDatabaseHas('alerts', [
            'type' => AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION,
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'entity_type' => PurchaseVerification::class,
            'entity_id' => $verification->id,
            'responsible_user_id' => $assignee->id,
        ]);
        Notification::assertSentTo($assignee, MvsAlertNotification::class);
        Notification::assertNotSentTo($foreign, MvsAlertNotification::class);
        Mail::assertNothingSent();
    }

    public function test_existing_purchase_verification_flow_still_assigns(): void
    {
        Notification::fake();
        [$company, $branch] = $this->company();
        $assigner = $this->user($company, $branch, ['compras.recepcion.asignar']);
        $assignee = $this->user($company, $branch, ['compras.recepcion.verificar']);
        $purchase = $this->purchase($company, $branch, $assigner);

        $verification = app(PurchaseVerificationService::class)->assign($purchase, $assigner, $assignee);

        $this->assertSame('pending', $verification->status);
        $this->assertSame($assignee->id, $verification->assigned_to);
    }

    private function company(string $name = 'Empresa'): array
    {
        $company = Company::create([
            'trade_name' => $name.' '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'P'.uniqid(),
            'is_active' => true,
        ]);

        return [$company, $branch];
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function payload(Company $company, Branch $branch, string $key = 'pilot'): array
    {
        return [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'severity' => Alert::SEVERITY_ATTENTION,
            'entity_type' => PurchaseVerification::class,
            'entity_id' => 1,
            'dedupe_key' => $key,
            'notes' => 'Piloto',
        ];
    }

    private function purchase(Company $company, Branch $branch, User $user): Purchase
    {
        $supplier = Supplier::create([
            'company_id' => $company->id,
            'supplier_type' => 'company',
            'name' => 'Proveedor',
            'is_active' => true,
        ]);

        return Purchase::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'user_id' => $user->id,
            'number' => 'C-'.uniqid(),
            'purchase_date' => now(),
            'payment_type' => 'cash',
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 0,
            'total' => 100,
            'status' => 'posted',
        ]);
    }
}
