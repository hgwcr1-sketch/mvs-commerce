<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\MaestroReferralReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosReferralScreenFocalTest extends TestCase
{
    use RefreshDatabase;

    private function context(bool $permission): array
    {
        $company = Company::create([
            'trade_name' => 'Referidos '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'R'.random_int(100, 999),
            'is_active' => true,
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Referidos '.uniqid(), 'is_active' => true]);
        if ($permission) {
            $role->permissions()->attach(Permission::firstOrCreate(
                ['name' => 'cobros.acceder'],
                ['label' => 'Acceder a CobrOS', 'module' => 'CobrOS', 'is_active' => true]
            ));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        $company->license()->create(['status' => 'active', 'plan' => 'Prueba']);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'cobros', 'is_enabled' => true, 'license_mode' => 'company_cupo']);


        return [$company, $user];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_authorized_user_can_render_screen_and_sidebar_link(): void
    {
        [$company, $user] = $this->context(true);
        $reader = $this->mock(MaestroReferralReader::class);
        $reader->shouldReceive('read')->once()->andReturn(['available' => false, 'referrals' => []]);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.referrals.index'));

        $response->assertOk()
            ->assertSee('Mis referidos')
            ->assertSee('href="'.route('cobros.referrals.index').'"', false);
    }

    public function test_user_without_cobros_permission_is_denied(): void
    {
        [$company, $user] = $this->context(false);
        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.referrals.index'))
            ->assertForbidden();
    }
}
