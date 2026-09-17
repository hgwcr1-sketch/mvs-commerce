<?php

namespace Tests\Feature\Notifications;

use App\Models\Alert;
use App\Models\Branch;
use App\Models\Company;
use App\Models\NotificationPreference;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Notifications\AlertTypeRegistry;
use App\Services\Notifications\NotificationPreferenceService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_preference_defaults_to_enabled(): void
    {
        [$company, $branch, $user, $role] = $this->context(['notificaciones.configurar']);

        $service = app(NotificationPreferenceService::class);

        $this->assertTrue($service->isEnabledForUser($user, $company, $role, AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION));
        $this->assertTrue($service->allowsAlert($user, $company, $role, AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, Alert::SEVERITY_INFO));
    }

    public function test_user_preference_overrides_role_and_company(): void
    {
        [$company, $branch, $user, $role] = $this->context(['notificaciones.configurar']);
        $service = app(NotificationPreferenceService::class);
        $type = AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION;

        $service->setPreference($company, $type, true, Alert::SEVERITY_INFO);
        $service->setPreference($company, $type, false, Alert::SEVERITY_INFO, $role);
        $service->setPreference($company, $type, true, Alert::SEVERITY_ATTENTION, null, $user);

        $this->assertTrue($service->isEnabledForUser($user, $company, $role, $type));
        $this->assertFalse($service->allowsAlert($user, $company, $role, $type, Alert::SEVERITY_INFO));
        $this->assertTrue($service->allowsAlert($user, $company, $role, $type, Alert::SEVERITY_ATTENTION));
        $this->assertTrue($service->allowsAlert($user, $company, $role, $type, Alert::SEVERITY_CRITICAL));
    }

    public function test_role_preference_overrides_company_default(): void
    {
        [$company, $branch, $user, $role] = $this->context(['notificaciones.configurar']);
        $service = app(NotificationPreferenceService::class);
        $type = AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION;

        $service->setPreference($company, $type, true);
        $service->setPreference($company, $type, false, Alert::SEVERITY_INFO, $role);

        $this->assertFalse($service->isEnabledForUser($user, $company, $role, $type));
    }

    public function test_company_preferences_are_isolated(): void
    {
        [$firstCompany, , $firstUser, $firstRole] = $this->context(['notificaciones.configurar'], 'Uno');
        [$secondCompany, , $secondUser, $secondRole] = $this->context(['notificaciones.configurar'], 'Dos');
        $service = app(NotificationPreferenceService::class);
        $type = AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION;

        $service->setPreference($firstCompany, $type, false);

        $this->assertFalse($service->isEnabledForUser($firstUser, $firstCompany, $firstRole, $type));
        $this->assertTrue($service->isEnabledForUser($secondUser, $secondCompany, $secondRole, $type));
    }

    public function test_ensure_company_defaults_does_not_overwrite_existing(): void
    {
        [$company] = $this->context(['notificaciones.configurar']);
        $service = app(NotificationPreferenceService::class);
        $type = AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION;

        $service->setPreference($company, $type, false, Alert::SEVERITY_CRITICAL);
        $service->ensureCompanyDefaults($company);

        $preference = NotificationPreference::query()
            ->where('company_id', $company->id)
            ->where('type', $type)
            ->whereNull('role_id')
            ->whereNull('user_id')
            ->first();

        $this->assertFalse($preference->enabled);
        $this->assertSame(Alert::SEVERITY_CRITICAL, $preference->severity_min);
        $this->assertSame(count(AlertTypeRegistry::all()), NotificationPreference::query()->where('company_id', $company->id)->whereNull('role_id')->whereNull('user_id')->count());
    }

    public function test_set_preference_rejects_foreign_role_and_user(): void
    {
        [$company] = $this->context(['notificaciones.configurar'], 'Local');
        [, , $foreignUser, $foreignRole] = $this->context(['notificaciones.configurar'], 'Ajena');
        $service = app(NotificationPreferenceService::class);

        $this->expectException(ValidationException::class);
        $service->setPreference($company, AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, true, Alert::SEVERITY_INFO, $foreignRole, $foreignUser);
    }

    public function test_preferences_screen_requires_configure_permission(): void
    {
        [$company, $branch, $user] = $this->context(['notificaciones.ver']);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('notifications.preferences'))
            ->assertForbidden();
    }

    public function test_admin_can_view_and_save_company_defaults_and_role_override(): void
    {
        [$company, $branch, $user, $role] = $this->context(['notificaciones.configurar', 'notificaciones.ver']);
        $type = AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION;

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('notifications.preferences'))
            ->assertOk()
            ->assertSee('Configuración de Notificaciones')
            ->assertSee('Empresa (predeterminado)');

        $this->put(route('notifications.preferences.update'), [
            'preferences' => [
                $type.'_company' => [
                    'type' => $type,
                    'scope' => 'company',
                    'enabled' => '0',
                    'severity_min' => Alert::SEVERITY_ATTENTION,
                ],
            ],
            'override' => [
                'type' => $type,
                'scope' => 'role',
                'role_id' => $role->id,
                'enabled' => '1',
                'severity_min' => Alert::SEVERITY_INFO,
            ],
        ])->assertRedirect(route('notifications.preferences'));

        $this->assertDatabaseHas('notification_preferences', [
            'company_id' => $company->id,
            'type' => $type,
            'role_id' => null,
            'user_id' => null,
            'enabled' => 0,
            'severity_min' => Alert::SEVERITY_ATTENTION,
        ]);
        $this->assertDatabaseHas('notification_preferences', [
            'company_id' => $company->id,
            'type' => $type,
            'role_id' => $role->id,
            'user_id' => null,
            'enabled' => 1,
            'severity_min' => Alert::SEVERITY_INFO,
        ]);
    }

    public function test_renamed_super_admin_role_still_receives_notification_permissions_from_seeder(): void
    {
        [$company, $branch] = $this->context([]);
        $role = Role::query()->where('company_id', $company->id)->first();
        $role->update(['name' => 'Director general', 'is_super_admin' => true]);

        $this->seed(PermissionSeeder::class);

        $this->assertTrue($role->fresh()->permissions()->where('name', 'notificaciones.configurar')->exists());
        $this->assertTrue($role->fresh()->permissions()->where('name', 'notificaciones.ver')->exists());
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
