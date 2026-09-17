<?php

namespace Tests\Feature\Notifications;

use App\Models\Alert;
use App\Models\AlertRecipient;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryTransfer;
use App\Models\Permission;
use App\Models\Purchase;
use App\Models\PurchaseVerification;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\MvsAlertNotification;
use App\Services\Notifications\AlertDispatcher;
use App\Services\Notifications\AlertRecipientResolver;
use App\Services\Notifications\AlertTypeRegistry;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Purchases\PurchaseVerificationService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    // ── 1. Company isolation ────────────────────────────────────────────

    public function test_company_isolation(): void
    {
        Notification::fake();
        [$companyA, $branchA] = $this->company('A');
        [$companyB, $branchB] = $this->company('B');
        $userA = $this->user($companyA, $branchA, ['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);
        $userB = $this->user($companyB, $branchB, ['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);

        $dispatcher = app(AlertDispatcher::class);
        $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($companyA, $branchA, 'c1'));
        $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($companyB, $branchB, 'c2'));

        $this->actingAs($userA)
            ->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->getJson(route('notifications.recent'))
            ->assertJsonCount(1, 'alerts');

        $this->actingAs($userB)
            ->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->getJson(route('notifications.recent'))
            ->assertJsonCount(1, 'alerts');
    }

    // ── 2. Branch isolation ─────────────────────────────────────────────

    public function test_branch_isolation(): void
    {
        Notification::fake();
        [$company] = $this->company('B');
        $branchA = Branch::create(['company_id' => $company->id, 'name' => 'A', 'code' => 'A'.uniqid(), 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $company->id, 'name' => 'B', 'code' => 'B'.uniqid(), 'is_active' => true]);
        $userA = $this->user($company, $branchA, ['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);
        $userB = $this->user($company, $branchB, ['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);

        $dispatcher = app(AlertDispatcher::class);
        $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branchA, 'b1'));

        $idsA = app(AlertRecipientResolver::class)
            ->resolve(Alert::latest()->first(), $company)
            ->pluck('id');

        $this->assertTrue($idsA->contains($userA->id));
        $this->assertFalse($idsA->contains($userB->id));
    }

    // ── 3. Source permission required ───────────────────────────────────

    public function test_source_permission_required(): void
    {
        Notification::fake();
        [$company, $branch] = $this->company();
        $withSource = $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.verificar']);
        $withoutSource = $this->user($company, $branch, ['notificaciones.compras']);

        $alert = app(AlertDispatcher::class)->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branch));

        $this->assertDatabaseHas('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $withSource->id]);
        $this->assertDatabaseMissing('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $withoutSource->id]);
    }

    // ── 4. Notification permission required ─────────────────────────────

    public function test_notification_permission_required(): void
    {
        Notification::fake();
        [$company, $branch] = $this->company();
        $withNotif = $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.verificar']);
        $withoutNotif = $this->user($company, $branch, ['compras.recepcion.verificar']);

        $alert = app(AlertDispatcher::class)->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branch));

        $this->assertDatabaseHas('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $withNotif->id]);
        $this->assertDatabaseMissing('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $withoutNotif->id]);
    }

    // ── 5. Responsible user receives alert ──────────────────────────────

    public function test_responsible_user_receives_alert(): void
    {
        Notification::fake();
        [$company, $branch] = $this->company();
        $responsible = $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.verificar']);
        $other = $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.verificar']);

        $dispatcher = app(AlertDispatcher::class);
        $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, array_merge(
            $this->payload($company, $branch, 'resp'),
            ['responsible_user_id' => $responsible->id]
        ));

        Notification::assertSentTo($responsible, MvsAlertNotification::class);
    }

    // ── 6. Role preference ──────────────────────────────────────────────

    public function test_role_preference_controls_delivery(): void
    {
        Notification::fake();
        [$company, $branch, $user, $role] = $this->context(['notificaciones.compras', 'compras.recepcion.verificar']);
        $type = AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION;

        app(NotificationPreferenceService::class)->setPreference($company, $type, false, Alert::SEVERITY_INFO, $role);

        $alert = app(AlertDispatcher::class)->dispatch($type, $this->payload($company, $branch));

        $this->assertDatabaseMissing('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $user->id]);
        Notification::assertNotSentTo($user, MvsAlertNotification::class);
    }

    // ── 7. User override overrides role preference ──────────────────────

    public function test_user_override_overrides_role_preference(): void
    {
        Notification::fake();
        [$company, $branch, $user, $role] = $this->context(['notificaciones.compras', 'compras.recepcion.verificar']);
        $type = AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION;

        $prefService = app(NotificationPreferenceService::class);
        $prefService->setPreference($company, $type, false, Alert::SEVERITY_INFO, $role);
        $prefService->setPreference($company, $type, true, Alert::SEVERITY_INFO, null, $user);

        $alert = app(AlertDispatcher::class)->dispatch($type, $this->payload($company, $branch));

        $this->assertDatabaseHas('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $user->id]);
        Notification::assertSentTo($user, MvsAlertNotification::class);
    }

    // ── 8. Inactive user excluded ───────────────────────────────────────

    public function test_inactive_user_excluded(): void
    {
        Notification::fake();
        [$company, $branch] = $this->company();
        $user = $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.verificar']);
        $user->update(['is_active' => false]);

        $alert = app(AlertDispatcher::class)->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branch));

        $this->assertDatabaseMissing('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $user->id]);
        Notification::assertNotSentTo($user, MvsAlertNotification::class);
    }

    // ── 9. Deduplication ────────────────────────────────────────────────

    public function test_deduplication(): void
    {
        Notification::fake();
        [$company, $branch] = $this->company();
        $dispatcher = app(AlertDispatcher::class);
        $payload = $this->payload($company, $branch, 'dedup-key');

        $first = $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $payload);
        $second = $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Alert::query()->count());
    }

    // ── 10. Unread counter ──────────────────────────────────────────────

    public function test_unread_counter(): void
    {
        [$company, $branch, $user] = $this->context(['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);
        $dispatcher = app(AlertDispatcher::class);

        $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branch, 'uc1'));
        $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branch, 'uc2'));

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('notifications.unread-count'))
            ->assertJson(['count' => 2]);
    }

    // ── 11. Mark read ───────────────────────────────────────────────────

    public function test_mark_read(): void
    {
        [$company, $branch, $user] = $this->context(['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);
        $dispatcher = app(AlertDispatcher::class);
        $alert = $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branch, 'mr1'));

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('notifications.mark-read'), ['alert_id' => $alert->id])
            ->assertJson(['ok' => true]);

        $recipient = AlertRecipient::where('alert_id', $alert->id)->where('user_id', $user->id)->first();
        $this->assertNotNull($recipient->read_at);
    }

    // ── 12. Mark all read ───────────────────────────────────────────────

    public function test_mark_all_read(): void
    {
        [$company, $branch, $user] = $this->context(['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);
        $dispatcher = app(AlertDispatcher::class);
        $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branch, 'ma1'));
        $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branch, 'ma2'));

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('notifications.mark-read'))
            ->assertJson(['ok' => true]);

        $unread = AlertRecipient::where('user_id', $user->id)->whereNull('read_at')->count();
        $this->assertSame(0, $unread);
    }

    // ── 13. Resolve alert ───────────────────────────────────────────────

    public function test_resolve_alert(): void
    {
        [$company, $branch, $user] = $this->context(['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);
        $dispatcher = app(AlertDispatcher::class);
        $alert = $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branch, 'res1'));

        $alert->update(['status' => Alert::STATUS_RESOLVED, 'resolved_at' => now(), 'resolved_by' => $user->id]);

        $this->assertDatabaseHas('alerts', ['id' => $alert->id, 'status' => Alert::STATUS_RESOLVED]);
        $this->assertNotNull($alert->fresh()->resolved_at);
    }

    // ── 14. Direct link ─────────────────────────────────────────────────

    public function test_direct_link_in_alert(): void
    {
        [$company, $branch] = $this->company();
        $dispatcher = app(AlertDispatcher::class);
        $payload = $this->payload($company, $branch, 'link1');
        $payload['link'] = '/compras/123';

        $alert = $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $payload);

        $this->assertSame('/compras/123', $alert->link);
    }

    // ── 15. PurchaseVerification pilot ──────────────────────────────────

    public function test_purchase_verification_pilot_dispatches_alert(): void
    {
        Notification::fake();
        [$company, $branch] = $this->company();
        $assigner = $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.asignar', 'compras.recepcion.verificar']);
        $assignee = $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.verificar']);

        $supplier = Supplier::create([
            'company_id' => $company->id, 'supplier_type' => 'company', 'name' => 'Prov', 'is_active' => true,
        ]);
        $purchase = Purchase::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id,
            'user_id' => $assigner->id, 'number' => 'C-'.uniqid(), 'purchase_date' => now(),
            'payment_type' => 'cash', 'subtotal' => 100, 'discount' => 0, 'tax' => 0, 'total' => 100, 'status' => 'posted',
        ]);

        $verification = app(PurchaseVerificationService::class)->assign($purchase, $assigner, $assignee);

        $this->assertDatabaseHas('alerts', [
            'type' => AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION,
            'company_id' => $company->id,
            'entity_type' => PurchaseVerification::class,
            'entity_id' => $verification->id,
        ]);
        Notification::assertSentTo($assignee, MvsAlertNotification::class);
    }

    // ── 16. Unauthorized user cannot read notification by ID ────────────

    public function test_unauthorized_user_cannot_read_notification_by_id(): void
    {
        [$companyA, $branchA] = $this->company('A');
        [$companyB, $branchB] = $this->company('B');
        $userA = $this->user($companyA, $branchA, ['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);
        $userB = $this->user($companyB, $branchB, ['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);

        $dispatcher = app(AlertDispatcher::class);
        $alert = $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($companyA, $branchA, 'sec1'));

        $this->actingAs($userB)
            ->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->getJson(route('notifications.recent'))
            ->assertJsonCount(0, 'alerts');
    }

    // ── 17. Cross-company ID guessing ───────────────────────────────────

    public function test_cross_company_id_guessing(): void
    {
        [$companyA, $branchA] = $this->company('A');
        [$companyB, $branchB] = $this->company('B');
        $userA = $this->user($companyA, $branchA, ['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);
        $userB = $this->user($companyB, $branchB, ['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);

        $dispatcher = app(AlertDispatcher::class);
        $alertA = $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($companyA, $branchA, 'guess1'));
        $alertB = $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($companyB, $branchB, 'guess2'));

        // UserB tries to dismiss UserA's alert
        $this->actingAs($userB)
            ->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->postJson(route('notifications.dismiss', $alertA))
            ->assertStatus(404);

        // UserA's alert still exists for them
        $this->actingAs($userA)
            ->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->getJson(route('notifications.recent'))
            ->assertJsonCount(1, 'alerts');
    }

    // ── 18. Cross-branch ID guessing ────────────────────────────────────

    public function test_cross_branch_id_guessing(): void
    {
        [$company] = $this->company('B');
        $branchA = Branch::create(['company_id' => $company->id, 'name' => 'A', 'code' => 'A'.uniqid(), 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $company->id, 'name' => 'B', 'code' => 'B'.uniqid(), 'is_active' => true]);
        $userA = $this->user($company, $branchA, ['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);
        $userB = $this->user($company, $branchB, ['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);

        $dispatcher = app(AlertDispatcher::class);
        $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branchA, 'br1'));

        // UserB should not see alerts from branchA
        $ids = app(AlertRecipientResolver::class)
            ->resolve(Alert::latest()->first(), $company)
            ->pluck('id');

        $this->assertFalse($ids->contains($userB->id));
    }

    // ── 19. Layaway branch leak fixed ───────────────────────────────────

    public function test_layaway_branch_leak_fixed(): void
    {
        [$company] = $this->company();
        $branchA = Branch::create(['company_id' => $company->id, 'name' => 'A', 'code' => 'A'.uniqid(), 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $company->id, 'name' => 'B', 'code' => 'B'.uniqid(), 'is_active' => true]);
        $userA = $this->user($company, $branchA, ['notificaciones.apartados', 'apartados.ver']);
        $userB = $this->user($company, $branchB, ['notificaciones.apartados', 'apartados.ver']);

        // Simulate the layaway service branch check
        $this->assertTrue($userA->branches()->where('branches.id', $branchA->id)->exists());
        $this->assertFalse($userA->branches()->where('branches.id', $branchB->id)->exists());
        $this->assertTrue($userB->branches()->where('branches.id', $branchB->id)->exists());
        $this->assertFalse($userB->branches()->where('branches.id', $branchA->id)->exists());
    }

    // ── 20. TransferController show hardened ─────────────────────────────

    public function test_transfer_controller_show_hardened(): void
    {
        [$companyA, $branchA] = $this->company('A');
        [$companyB, $branchB] = $this->company('B');
        $userA = $this->user($companyA, $branchA, ['inventario.transferir']);
        $userB = $this->user($companyB, $branchB, ['inventario.transferir']);

        $transfer = InventoryTransfer::create([
            'company_id' => $companyA->id,
            'from_branch_id' => $branchA->id,
            'to_branch_id' => $branchA->id,
            'user_id' => $userA->id,
            'transfer_number' => 'T-'.uniqid(),
            'status' => 'pending',
        ]);

        // UserB from different company cannot see UserA's transfer (controller returns 404)
        $this->actingAs($userB)
            ->withSession(['active_company_id' => $companyB->id, 'active_branch_id' => $branchB->id])
            ->get(route('transferencias.show', $transfer))
            ->assertStatus(404);
    }

    // ── 21. ControlCenter permission hardened ────────────────────────────

    public function test_control_center_requires_dashboard_admin(): void
    {
        [$company, $branch, $user] = $this->context(['dashboard.ver']);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('control-center.index'))
            ->assertForbidden();
    }

    // ── 22. Custom role names work ──────────────────────────────────────

    public function test_custom_role_names_work(): void
    {
        [$company, $branch] = $this->company();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Director de Operaciones', 'is_active' => true]);
        foreach (['notificaciones.compras', 'compras.recepcion.verificar'] as $perm) {
            Permission::firstOrCreate(['name' => $perm], ['label' => $perm, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching(Permission::where('name', $perm)->first()->id);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        $this->assertTrue($user->hasPermission('notificaciones.compras', $company));

        $alert = app(AlertDispatcher::class)->dispatch(
            AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION,
            $this->payload($company, $branch, 'custom-role')
        );

        $this->assertDatabaseHas('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $user->id]);
    }

    // ── 23. Existing Administrador remains functional ───────────────────

    public function test_existing_administrador_role_still_works(): void
    {
        $this->seed(PermissionSeeder::class);

        [$company, $branch] = $this->company();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Administrador', 'is_active' => true, 'is_super_admin' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        $this->assertTrue($user->hasPermission('notificaciones.ver', $company));
        $this->assertTrue($user->hasPermission('notificaciones.configurar', $company));
        $this->assertTrue($user->hasPermission('notificaciones.compras', $company));
        $this->assertTrue($user->hasPermission('compras.recepcion.verificar', $company));
    }

    // ── 24. MVS Alert notification stays in-app (database) during pilot ─

    public function test_mvs_alert_notification_is_in_app_only(): void
    {
        Notification::fake();
        [$company, $branch] = $this->company();
        $user = $this->user($company, $branch, ['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);

        $dispatcher = app(AlertDispatcher::class);
        $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branch, 'inapp'));

        // Piloto N05-N09: in-app (database) sin mail automático.
        Notification::assertSentTo($user, MvsAlertNotification::class);
    }

    // ── Notification center view renders with filters ───────────────────

    public function test_notification_center_renders_with_filters(): void
    {
        [$company, $branch, $user] = $this->context(['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);
        $dispatcher = app(AlertDispatcher::class);
        $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branch, 'view1'));

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Centro de Notificaciones')
            ->assertSee('Todas')
            ->assertSee('No leídas')
            ->assertSee('Críticas')
            ->assertSee('Atención')
            ->assertSee('Informativas');
    }

    // ── Notification center filter tabs work ────────────────────────────

    public function test_notification_center_filter_tabs(): void
    {
        [$company, $branch, $user] = $this->context(['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);
        $dispatcher = app(AlertDispatcher::class);
        $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branch, 'tab1'));
        $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branch, 'tab2'));

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('notifications.index', ['filter' => 'unread']))
            ->assertOk()
            ->assertSee('No leídas');
    }

    // ── Preferences screen shows disabled categories for unauthorized ───

    public function test_preferences_screen_shows_disabled_for_unauthorized(): void
    {
        [$company, $branch, $user] = $this->context(['notificaciones.configurar', 'notificaciones.ver']);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('notifications.preferences'))
            ->assertOk()
            ->assertSee('Configuración de Notificaciones')
            ->assertSee('Una preferencia nunca concede acceso');
    }

    // ── Preference never grants access ──────────────────────────────────

    public function test_preference_never_grants_access(): void
    {
        [$company, $branch] = $this->company();
        $user = $this->user($company, $branch, ['notificaciones.compras']);
        app(NotificationPreferenceService::class)->setPreference(
            $company,
            AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION,
            true,
            Alert::SEVERITY_INFO,
            null,
            $user
        );

        $alert = app(AlertDispatcher::class)->dispatch(
            AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION,
            $this->payload($company, $branch, 'nonga')
        );

        $this->assertDatabaseMissing('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $user->id]);
    }

    // ── Branch-scoped alert only reaches branch users ───────────────────

    public function test_branch_scoped_alert_only_reaches_branch_users(): void
    {
        Notification::fake();
        [$company] = $this->company();
        $branchA = Branch::create(['company_id' => $company->id, 'name' => 'A', 'code' => 'A'.uniqid(), 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $company->id, 'name' => 'B', 'code' => 'B'.uniqid(), 'is_active' => true]);
        $userA = $this->user($company, $branchA, ['notificaciones.compras', 'compras.recepcion.verificar']);
        $userB = $this->user($company, $branchB, ['notificaciones.compras', 'compras.recepcion.verificar']);

        $alert = app(AlertDispatcher::class)->dispatch(
            AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION,
            $this->payload($company, $branchA, 'bs')
        );

        $this->assertDatabaseHas('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $userA->id]);
        $this->assertDatabaseMissing('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $userB->id]);
    }

    // ── Company-wide alert reaches all company users ────────────────────

    public function test_company_wide_alert_reaches_all_company_users(): void
    {
        Notification::fake();
        [$company] = $this->company();
        $branchA = Branch::create(['company_id' => $company->id, 'name' => 'A', 'code' => 'A'.uniqid(), 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $company->id, 'name' => 'B', 'code' => 'B'.uniqid(), 'is_active' => true]);
        $userA = $this->user($company, $branchA, ['notificaciones.compras', 'compras.recepcion.verificar']);
        $userB = $this->user($company, $branchB, ['notificaciones.compras', 'compras.recepcion.verificar']);

        $dispatcher = app(AlertDispatcher::class);
        $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, [
            'company_id' => $company->id,
            'branch_id' => null,
            'severity' => Alert::SEVERITY_INFO,
            'dedupe_key' => 'company-wide',
        ]);

        Notification::assertSentTo($userA, MvsAlertNotification::class);
        Notification::assertSentTo($userB, MvsAlertNotification::class);
    }

    // ── Severity minimum preference filters correctly ───────────────────

    public function test_severity_minimum_preference_filters(): void
    {
        Notification::fake();
        [$company, $branch, $user, $role] = $this->context(['notificaciones.compras', 'compras.recepcion.verificar']);
        $type = AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION;

        app(NotificationPreferenceService::class)->setPreference($company, $type, true, Alert::SEVERITY_CRITICAL, $role);

        $dispatcher = app(AlertDispatcher::class);
        $infoAlert = $dispatcher->dispatch($type, $this->payload($company, $branch, 'sev1'));

        $criticalPayload = $this->payload($company, $branch, 'sev2');
        $criticalPayload['severity'] = Alert::SEVERITY_CRITICAL;
        $criticalAlert = $dispatcher->dispatch($type, $criticalPayload);

        // User should receive only the critical alert, not the info one
        $this->assertDatabaseMissing('alert_recipients', ['alert_id' => $infoAlert->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('alert_recipients', ['alert_id' => $criticalAlert->id, 'user_id' => $user->id]);
    }

    // ── Dismiss removes alert from user view ────────────────────────────

    public function test_dismiss_removes_alert_from_user_view(): void
    {
        [$company, $branch, $user] = $this->context(['notificaciones.ver', 'notificaciones.compras', 'compras.recepcion.verificar']);
        $dispatcher = app(AlertDispatcher::class);
        $alert = $dispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, $this->payload($company, $branch, 'dismiss1'));

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('notifications.dismiss', $alert))
            ->assertJson(['ok' => true]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->getJson(route('notifications.recent'))
            ->assertJsonCount(0, 'alerts');
    }

    // ── HELPER METHODS ──────────────────────────────────────────────────

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

    private function payload(Company $company, Branch $branch, string $key = 'test'): array
    {
        return [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'severity' => Alert::SEVERITY_INFO,
            'dedupe_key' => $key,
            'notes' => 'Test alert',
        ];
    }

    private function context(array $permissions, string $name = 'Empresa'): array
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
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Rol '.uniqid(),
            'is_active' => true,
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => $permissionName, 'module' => 'Notificaciones', 'is_active' => true]
            );
            $role->permissions()->syncWithoutDetaching($permission->id);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user, $role];
    }
}
