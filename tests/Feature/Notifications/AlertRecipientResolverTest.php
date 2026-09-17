<?php

namespace Tests\Feature\Notifications;

use App\Models\Alert;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Notifications\AlertRecipientResolver;
use App\Services\Notifications\AlertTypeRegistry;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertRecipientResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_notification_and_source_permissions(): void
    {
        [$company, $branch] = $this->company();
        $authorized = $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.verificar']);
        $onlyNotification = $this->user($company, $branch, ['notificaciones.compras']);
        $onlySource = $this->user($company, $branch, ['compras.recepcion.verificar']);
        $alert = $this->alert($company, $branch);

        $ids = app(AlertRecipientResolver::class)->resolve($alert, $company)->pluck('id');

        $this->assertTrue($ids->contains($authorized->id));
        $this->assertFalse($ids->contains($onlyNotification->id));
        $this->assertFalse($ids->contains($onlySource->id));
    }

    public function test_preference_never_grants_access_without_source_permission(): void
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
        $alert = $this->alert($company, $branch);

        $this->assertFalse(app(AlertRecipientResolver::class)->resolve($alert, $company)->pluck('id')->contains($user->id));
    }

    public function test_disabled_preference_excludes_authorized_user(): void
    {
        [$company, $branch] = $this->company();
        $user = $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.verificar']);
        app(NotificationPreferenceService::class)->setPreference(
            $company,
            AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION,
            false,
            Alert::SEVERITY_INFO,
            null,
            $user
        );

        $this->assertFalse(app(AlertRecipientResolver::class)->resolve($this->alert($company, $branch), $company)->pluck('id')->contains($user->id));
    }

    public function test_severity_minimum_filters_recipients(): void
    {
        [$company, $branch] = $this->company();
        $user = $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.verificar']);
        app(NotificationPreferenceService::class)->setPreference(
            $company,
            AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION,
            true,
            Alert::SEVERITY_CRITICAL,
            null,
            $user
        );

        $info = $this->alert($company, $branch, Alert::SEVERITY_INFO);
        $critical = $this->alert($company, $branch, Alert::SEVERITY_CRITICAL);

        $resolver = app(AlertRecipientResolver::class);
        $this->assertFalse($resolver->resolve($info, $company)->pluck('id')->contains($user->id));
        $this->assertTrue($resolver->resolve($critical, $company)->pluck('id')->contains($user->id));
    }

    public function test_isolates_company_and_branch_and_excludes_inactive_users(): void
    {
        [$company, $branchA] = $this->company('A');
        $branchB = Branch::create(['company_id' => $company->id, 'name' => 'B', 'code' => 'B'.uniqid(), 'is_active' => true]);
        [$otherCompany, $otherBranch] = $this->company('Otra');

        $sameBranch = $this->user($company, $branchA, ['notificaciones.compras', 'compras.recepcion.verificar']);
        $otherBranchUser = $this->user($company, $branchB, ['notificaciones.compras', 'compras.recepcion.verificar']);
        $inactive = $this->user($company, $branchA, ['notificaciones.compras', 'compras.recepcion.verificar']);
        $inactive->update(['is_active' => false]);
        $foreign = $this->user($otherCompany, $otherBranch, ['notificaciones.compras', 'compras.recepcion.verificar']);

        $ids = app(AlertRecipientResolver::class)->resolve($this->alert($company, $branchA), $company)->pluck('id');

        $this->assertTrue($ids->contains($sameBranch->id));
        $this->assertFalse($ids->contains($otherBranchUser->id));
        $this->assertFalse($ids->contains($inactive->id));
        $this->assertFalse($ids->contains($foreign->id));
    }

    public function test_super_admin_flag_authorizes_without_named_administrador_role(): void
    {
        [$company, $branch] = $this->company();
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Director general',
            'is_active' => true,
            'is_super_admin' => true,
        ]);
        foreach (['notificaciones.compras', 'compras.recepcion.verificar'] as $name) {
            Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        $this->assertTrue(app(AlertRecipientResolver::class)->resolve($this->alert($company, $branch), $company)->pluck('id')->contains($user->id));
    }

    public function test_does_not_resolve_alert_from_another_company(): void
    {
        [$company, $branch] = $this->company('Local');
        [$other] = $this->company('Ajena');
        $this->user($company, $branch, ['notificaciones.compras', 'compras.recepcion.verificar']);

        $this->assertCount(0, app(AlertRecipientResolver::class)->resolve($this->alert($other, $branch), $company));
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

    private function alert(Company $company, Branch $branch, string $severity = Alert::SEVERITY_ATTENTION): Alert
    {
        return Alert::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'type' => AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION,
            'severity' => $severity,
            'status' => Alert::STATUS_NEW,
            'occurred_at' => now(),
        ]);
    }
}
