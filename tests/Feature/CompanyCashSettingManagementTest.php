<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\CompanyCashSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CompanyCashSettingManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_opens_only_active_company_settings(): void
    {
        [$company] = $this->context('Empresa activa');
        [$other] = $this->context('Empresa ajena');
        $settings = $this->settings($company, ['session_mode' => 'shared']);
        $otherSettings = $this->settings($other, ['session_mode' => 'individual']);
        $user = $this->userWithPermissions($company, ['caja.administrar']);

        $this->getAs($user, $company)->assertOk()->assertSee('Compartida');
        $this->assertSame('individual', $otherSettings->fresh()->session_mode);
        $this->assertSame($company->id, $settings->company_id);
    }

    public function test_user_without_permission_receives_forbidden(): void
    {
        [$company] = $this->context('Empresa');
        $this->getAs($this->userWithPermissions($company, []), $company)->assertForbidden();
    }

    public function test_manipulated_company_id_is_ignored_and_other_company_is_unchanged(): void
    {
        [$company] = $this->context('Empresa');
        [$other] = $this->context('Ajena');
        $settings = $this->settings($company);
        $otherSettings = $this->settings($other, ['blind_closing' => true]);
        $user = $this->userWithPermissions($company, ['caja.administrar']);

        $this->putAs($user, $company, $this->payload(['company_id' => $other->id, 'blind_closing' => '0']))->assertSessionHasNoErrors();
        $this->assertFalse($settings->fresh()->blind_closing);
        $this->assertTrue($otherSettings->fresh()->blind_closing);
    }

    public function test_enables_multiple_registers(): void
    {
        [$company] = $this->context('Empresa');
        $settings = $this->settings($company, ['allow_multiple_registers' => false]);
        $user = $this->userWithPermissions($company, ['caja.administrar']);
        $this->putAs($user, $company, $this->payload(['allow_multiple_registers' => '1']))->assertSessionHasNoErrors();
        $this->assertTrue($settings->fresh()->allow_multiple_registers);
    }

    public function test_cannot_disable_multiple_registers_with_two_active_in_branch(): void
    {
        [$company, $branch] = $this->context('Empresa');
        $settings = $this->settings($company, ['allow_multiple_registers' => true]);
        $this->register($company, $branch, 'uno'); $this->register($company, $branch, 'dos');
        $user = $this->userWithPermissions($company, ['caja.administrar']);
        $this->putAs($user, $company, $this->payload(['allow_multiple_registers' => '0']))->assertSessionHasErrors('allow_multiple_registers');
        $this->assertTrue($settings->fresh()->allow_multiple_registers);
    }

    public function test_changes_session_mode_without_open_sessions(): void
    {
        [$company] = $this->context('Empresa');
        $settings = $this->settings($company);
        $user = $this->userWithPermissions($company, ['caja.administrar']);
        $this->putAs($user, $company, $this->payload(['session_mode' => 'shared']))->assertSessionHasNoErrors();
        $this->assertSame('shared', $settings->fresh()->session_mode);
    }

    public function test_does_not_change_mode_with_open_session(): void
    {
        [$company, $branch] = $this->context('Empresa');
        $settings = $this->settings($company);
        $user = $this->userWithPermissions($company, ['caja.administrar']);
        $register = $this->register($company, $branch, 'uno');
        $this->cashSession($company, $branch, $register, $user);
        $this->putAs($user, $company, $this->payload(['session_mode' => 'shared']))->assertSessionHasErrors('session_mode');
        $this->assertSame('individual', $settings->fresh()->session_mode);
    }

    public function test_blind_closing_is_saved(): void
    {
        [$company] = $this->context('Empresa'); $settings = $this->settings($company);
        $this->putAs($this->userWithPermissions($company, ['caja.administrar']), $company, $this->payload(['blind_closing' => '0']))->assertSessionHasNoErrors();
        $this->assertFalse($settings->fresh()->blind_closing);
    }

    public function test_negative_tolerance_is_rejected(): void
    {
        [$company] = $this->context('Empresa'); $settings = $this->settings($company, ['difference_tolerance' => 5]);
        $this->putAs($this->userWithPermissions($company, ['caja.administrar']), $company, $this->payload(['difference_tolerance' => -1]))->assertSessionHasErrors('difference_tolerance');
        $this->assertSame('5.0000', $settings->fresh()->difference_tolerance);
    }

    public function test_disabled_usd_clears_range_and_uses_crc_only(): void
    {
        [$company] = $this->context('Empresa');
        $settings = $this->settings($company, ['accepts_usd' => true, 'usd_exchange_rate_min' => 500, 'usd_exchange_rate_max' => 600, 'usd_change_policy' => 'either']);
        $this->putAs($this->userWithPermissions($company, ['caja.administrar']), $company, $this->payload(['accepts_usd' => '0', 'usd_exchange_rate_min' => 550, 'usd_exchange_rate_max' => 560, 'usd_change_policy' => 'usd_only']))->assertSessionHasNoErrors();
        $settings->refresh(); $this->assertFalse($settings->accepts_usd); $this->assertNull($settings->usd_exchange_rate_min); $this->assertNull($settings->usd_exchange_rate_max); $this->assertSame('crc_only', $settings->usd_change_policy);
    }

    public function test_enabled_usd_saves_valid_range(): void
    {
        [$company] = $this->context('Empresa'); $settings = $this->settings($company);
        $this->putAs($this->userWithPermissions($company, ['caja.administrar']), $company, $this->payload(['accepts_usd' => '1', 'usd_exchange_rate_min' => '510.2500', 'usd_exchange_rate_max' => '530.7500', 'usd_change_policy' => 'either']))->assertSessionHasNoErrors();
        $settings->refresh(); $this->assertTrue($settings->accepts_usd); $this->assertSame('510.2500', $settings->usd_exchange_rate_min); $this->assertSame('530.7500', $settings->usd_exchange_rate_max); $this->assertSame('either', $settings->usd_change_policy);
    }

    public function test_maximum_exchange_rate_below_minimum_is_rejected(): void
    {
        [$company] = $this->context('Empresa');
        $this->putAs($this->userWithPermissions($company, ['caja.administrar']), $company, $this->payload(['accepts_usd' => '1', 'usd_exchange_rate_min' => 600, 'usd_exchange_rate_max' => 500]))->assertSessionHasErrors('usd_exchange_rate_max');
    }

    public function test_invalid_usd_policy_is_rejected(): void
    {
        [$company] = $this->context('Empresa');
        $this->putAs($this->userWithPermissions($company, ['caja.administrar']), $company, $this->payload(['accepts_usd' => '1', 'usd_change_policy' => 'invalid']))->assertSessionHasErrors('usd_change_policy');
    }

    public function test_emails_are_normalized_and_deduplicated(): void
    {
        [$company] = $this->context('Empresa'); $settings = $this->settings($company);
        $this->putAs($this->userWithPermissions($company, ['caja.administrar']), $company, $this->payload(['closure_email_recipients' => [' ADMIN@EXAMPLE.COM ', '', 'admin@example.com', ' cierre@example.com ']]))->assertSessionHasNoErrors();
        $this->assertSame(['admin@example.com', 'cierre@example.com'], $settings->fresh()->closure_email_recipients);
    }

    public function test_maximum_ten_emails_is_enforced(): void
    {
        [$company] = $this->context('Empresa'); $emails=[]; for($i=0;$i<11;$i++){$emails[]="correo{$i}@example.com";}
        $this->putAs($this->userWithPermissions($company, ['caja.administrar']), $company, $this->payload(['closure_email_recipients' => $emails]))->assertSessionHasErrors('closure_email_recipients');
    }

    public function test_require_open_session_requires_active_registers_in_every_active_branch(): void
    {
        [$company] = $this->context('Empresa'); $settings = $this->settings($company, ['require_open_session' => false]);
        $this->putAs($this->userWithPermissions($company, ['caja.administrar']), $company, $this->payload(['require_open_session' => '1']))->assertSessionHasErrors('require_open_session');
        $this->assertFalse($settings->fresh()->require_open_session);
    }

    public function test_view_shows_functional_opening_control_and_back_button(): void
    {
        [$company] = $this->context('Empresa'); $this->settings($company);
        $this->getAs($this->userWithPermissions($company, ['caja.administrar']), $company)->assertOk()->assertSee('Exigir apertura antes de cobrar')->assertSee('Cuando está activo, el POS exige una sesión de caja abierta antes de completar una venta.')->assertSee('name="require_open_session"', false)->assertSee('Volver');
    }

    public function test_update_sends_no_email(): void
    {
        Mail::fake(); [$company] = $this->context('Empresa'); $this->settings($company);
        $this->putAs($this->userWithPermissions($company, ['caja.administrar']), $company, $this->payload(['closure_email_recipients' => ['admin@example.com']]))->assertSessionHasNoErrors();
        Mail::assertNothingSent(); Mail::assertNothingQueued();
    }

    public function test_sidebar_contains_three_accesses_according_to_permissions(): void
    {
        [$company] = $this->context('Empresa'); $this->settings($company);
        $cash = $this->userWithPermissions($company, ['caja.administrar']);
        $this->getAs($cash, $company)->assertSee('Configuración de Caja')->assertSee('Cajas')->assertDontSee('Formas de pago');
        $all = $this->userWithPermissions($company, ['caja.administrar', 'formas_pago.administrar']);
        $this->getAs($all, $company)->assertSeeInOrder(['Configuración de Caja', 'Cajas', 'Formas de pago']);
    }

    public function test_missing_configuration_is_provisioned_for_active_company_only(): void
    {
        [$company] = $this->context('Empresa'); [$other] = $this->context('Ajena');
        $this->getAs($this->userWithPermissions($company, ['caja.administrar']), $company)->assertOk();
        $this->assertDatabaseHas('company_cash_settings', ['company_id' => $company->id]);
        $this->assertDatabaseMissing('company_cash_settings', ['company_id' => $other->id]);
    }

    private function context(string $name): array { $company=Company::create(['trade_name'=>$name,'is_active'=>true]); $branch=Branch::create(['company_id'=>$company->id,'name'=>'Principal','code'=>'P-'.$company->id,'is_active'=>true]); return [$company,$branch]; }
    private function settings(Company $company,array $attributes=[]): CompanyCashSetting { $settings=app(CompanyCashSettingsProvisioner::class)->provision($company); $settings->update($attributes); return $settings; }
    private function userWithPermissions(Company $company,array $names): User { $user=User::factory()->create(); $role=Role::create(['company_id'=>$company->id,'name'=>'Rol '.uniqid(),'is_active'=>true]); foreach($names as $name){$p=Permission::firstOrCreate(['name'=>$name],['label'=>$name,'module'=>'Pruebas','is_active'=>true]);$role->permissions()->attach($p);} $user->companies()->attach($company->id,['role_id'=>$role->id]); return $user; }
    private function payload(array $values=[]): array { return array_merge(['allow_multiple_registers'=>'0','session_mode'=>'individual','blind_closing'=>'1','accepts_usd'=>'0','usd_change_policy'=>'crc_only','difference_tolerance'=>0,'require_difference_authorization'=>'0','auto_print_closure'=>'0','closure_email_recipients'=>[]],$values); }
    private function register(Company $company,Branch $branch,string $code): CashRegister { return CashRegister::create(['company_id'=>$company->id,'branch_id'=>$branch->id,'code'=>$code,'name'=>$code,'is_active'=>true]); }
    private function cashSession(Company $company,Branch $branch,CashRegister $register,User $user): CashSession { return CashSession::create(['company_id'=>$company->id,'branch_id'=>$branch->id,'cash_register_id'=>$register->id,'session_number'=>'CAJA-'.uniqid(),'opened_by'=>$user->id,'status'=>CashSession::STATUS_OPEN,'open_guard'=>CashSession::OPEN_GUARD,'opening_amount'=>0,'opened_at'=>now()]); }
    private function getAs(User $user,Company $company){return $this->actingAs($user)->withSession(['active_company_id'=>$company->id])->get(route('settings.cash.edit'));}
    private function putAs(User $user,Company $company,array $data){return $this->actingAs($user)->withSession(['active_company_id'=>$company->id])->put(route('settings.cash.update'),$data);}
}
