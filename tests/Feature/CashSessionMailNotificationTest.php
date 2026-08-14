<?php

namespace Tests\Feature;

use App\Jobs\SendCashSessionMailNotification;
use App\Mail\CashSessionClosedMail;
use App\Mail\CashSessionOpenedMail;
use App\Models\Branch;
use App\Models\CashCountDetail;
use App\Models\CashPaymentReconciliation;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CashSessionEvent;
use App\Models\CashSessionMailNotification;
use App\Models\Company;
use App\Models\CompanyCashSetting;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cash\CashClosingService;
use App\Services\Cash\CashSessionMailNotificationService;
use App\Services\Cash\CashSessionService;
use App\Services\CashDenominationProvisioner;
use App\Services\CompanyCashSettingsProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CashSessionMailNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_creates_one_normalized_outbox_and_dispatches_after_commit(): void
    {
        Queue::fake(); [$company, $branch, $user, $register, $settings] = $this->context();
        $settings->update(['closure_email_recipients' => [' ADMIN@EXAMPLE.COM ', 'admin@example.com', ' caja@example.com ']]);

        $session = app(CashSessionService::class)->open(['cash_register_id' => $register->id, 'opening_amount' => 50000], $user, $company->id, $branch->id);
        $notification = CashSessionMailNotification::firstOrFail();

        $this->assertSame(CashSessionMailNotification::TYPE_OPENED, $notification->notification_type);
        $this->assertSame(['admin@example.com', 'caja@example.com'], $notification->recipients);
        $this->assertSame(CashSessionMailNotification::STATUS_PENDING, $notification->status);
        $this->assertSame($session->id, $notification->cash_session_id);
        Queue::assertPushed(SendCashSessionMailNotification::class, fn ($job) => $job->notificationId === $notification->id);

        app(CashSessionMailNotificationService::class)->create($session, CashSessionMailNotification::TYPE_OPENED, $settings);
        $this->assertSame(1, CashSessionMailNotification::count());
        Queue::assertPushed(SendCashSessionMailNotification::class, 1);
    }

    public function test_opening_rollback_has_no_session_outbox_or_job(): void
    {
        Queue::fake(); [$company, $branch, $user, $register, $settings] = $this->context();
        $settings->update(['closure_email_recipients' => ['admin@example.com']]);
        CashSessionEvent::creating(fn () => throw new RuntimeException('event failure'));

        try { app(CashSessionService::class)->open(['cash_register_id' => $register->id, 'opening_amount' => 1000], $user, $company->id, $branch->id); }
        catch (RuntimeException $exception) { $this->assertSame('event failure', $exception->getMessage()); }

        $this->assertSame(0, CashSession::count());
        $this->assertSame(0, CashSessionMailNotification::count());
        Queue::assertNothingPushed();
    }

    public function test_no_recipients_creates_skipped_outbox_without_job(): void
    {
        Queue::fake(); [$company, $branch, $user, $register] = $this->context();
        app(CashSessionService::class)->open(['cash_register_id' => $register->id, 'opening_amount' => 1000], $user, $company->id, $branch->id);
        $this->assertDatabaseHas('cash_session_mail_notifications', ['notification_type' => 'opened', 'status' => 'skipped']);
        Queue::assertNothingPushed();
    }

    public function test_direct_close_dispatches_once_but_start_and_cancel_do_not(): void
    {
        Queue::fake(); [$company, $branch, $user, $register, $settings] = $this->context(['caja.cerrar']);
        $settings->update(['closure_email_recipients' => ['admin@example.com']]);
        $session = $this->rawSession($company, $branch, $user, $register);
        $service = app(CashClosingService::class);

        $service->start($user, $company->id, $branch->id, $session->id, (string) Str::uuid());
        $this->assertSame(0, CashSessionMailNotification::count());
        $service->cancel($user, $company->id, $branch->id, $session->id);
        $this->assertSame(0, CashSessionMailNotification::count());
        $service->start($user, $company->id, $branch->id, $session->id, (string) Str::uuid());
        $token = (string) Str::uuid();
        $service->submit($user, $company->id, $branch->id, $session->id, $this->closingPayload($company, $token, 1000));
        $service->submit($user, $company->id, $branch->id, $session->id, $this->closingPayload($company, $token, 1000));

        $this->assertDatabaseHas('cash_session_mail_notifications', ['cash_session_id' => $session->id, 'notification_type' => 'closed', 'status' => 'pending']);
        $this->assertSame(1, CashSessionMailNotification::count());
        Queue::assertPushed(SendCashSessionMailNotification::class, 1);
    }

    public function test_pending_difference_has_no_mail_and_authorization_creates_one_closed_mail(): void
    {
        Queue::fake(); [$company, $branch, $user, $register, $settings] = $this->context(['caja.cerrar', 'caja.autorizar_diferencia']);
        $settings->update(['closure_email_recipients' => ['admin@example.com'], 'require_difference_authorization' => true]);
        $session = $this->rawSession($company, $branch, $user, $register);
        $service = app(CashClosingService::class);
        $service->start($user, $company->id, $branch->id, $session->id, (string) Str::uuid());
        $service->submit($user, $company->id, $branch->id, $session->id, $this->closingPayload($company, (string) Str::uuid(), 1005));
        $this->assertSame(CashSession::STATUS_CLOSING, $session->fresh()->status);
        $this->assertSame(0, CashSessionMailNotification::count());
        Queue::assertNothingPushed();

        $service->authorize($user, $company->id, $branch->id, $session->id);
        $service->authorize($user, $company->id, $branch->id, $session->id);
        $this->assertSame(1, CashSessionMailNotification::where('notification_type', 'closed')->count());
        Queue::assertPushed(SendCashSessionMailNotification::class, 1);
    }

    public function test_each_recipient_gets_private_independent_mail_and_progress_is_saved(): void
    {
        Mail::fake(); [$company, $branch, $user, $register, $settings] = $this->context();
        $session = $this->rawSession($company, $branch, $user, $register);
        $settings->update(['closure_email_recipients' => ['one@example.com', 'two@example.com']]);
        $notification = app(CashSessionMailNotificationService::class)->create($session, CashSessionMailNotification::TYPE_OPENED, $settings);

        (new SendCashSessionMailNotification($notification->id))->handle();

        Mail::assertSent(CashSessionOpenedMail::class, 2);
        Mail::assertSent(CashSessionOpenedMail::class, fn ($mail) => $mail->hasTo('one@example.com') && ! $mail->hasTo('two@example.com'));
        Mail::assertSent(CashSessionOpenedMail::class, fn ($mail) => $mail->hasTo('two@example.com') && ! $mail->hasTo('one@example.com'));
        $this->assertEqualsCanonicalizing(['one@example.com', 'two@example.com'], $notification->fresh()->delivered_recipients);
        $this->assertSame(CashSessionMailNotification::STATUS_SENT, $notification->fresh()->status);
        $this->assertNotNull($notification->fresh()->sent_at);
    }

    public function test_retry_omits_delivered_recipients_and_maximum_attempts_are_not_recovered(): void
    {
        Mail::fake(); Queue::fake(); [$company, $branch, $user, $register] = $this->context();
        $session = $this->rawSession($company, $branch, $user, $register);
        $notification = CashSessionMailNotification::create(['company_id'=>$company->id,'cash_session_id'=>$session->id,'notification_type'=>'opened','recipients'=>['one@example.com','two@example.com'],'delivered_recipients'=>['one@example.com'],'status'=>'failed','attempts'=>1,'available_at'=>now()->subSecond()]);
        (new SendCashSessionMailNotification($notification->id))->handle();
        Mail::assertSent(CashSessionOpenedMail::class, 1);
        Mail::assertSent(CashSessionOpenedMail::class, fn ($mail) => $mail->hasTo('two@example.com'));

        $maxed = CashSessionMailNotification::create(['company_id'=>$company->id,'cash_session_id'=>$session->id,'notification_type'=>'closed','recipients'=>['x@example.com'],'status'=>'failed','attempts'=>5,'available_at'=>now()->subSecond()]);
        $this->artisan('cash:notifications:dispatch-pending')->assertSuccessful();
        Queue::assertNotPushed(SendCashSessionMailNotification::class, fn ($job) => $job->notificationId === $maxed->id);
    }

    public function test_transport_failure_is_sanitized_retriable_and_does_not_change_session(): void
    {
        [$company, $branch, $user, $register] = $this->context(); $session = $this->rawSession($company, $branch, $user, $register);
        $notification = CashSessionMailNotification::create(['company_id'=>$company->id,'cash_session_id'=>$session->id,'notification_type'=>'opened','recipients'=>['delivered@example.com','secret@example.com'],'status'=>'pending','available_at'=>now()]);
        $delivered = Mockery::mock(); $delivered->shouldReceive('send')->once();
        $pending = Mockery::mock(); $pending->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP failed for secret@example.com'));
        Mail::shouldReceive('to')->once()->with('delivered@example.com')->andReturn($delivered);
        Mail::shouldReceive('to')->once()->with('secret@example.com')->andReturn($pending);
        try { (new SendCashSessionMailNotification($notification->id))->handle(); } catch (RuntimeException) {}

        $failed = $notification->fresh();
        $this->assertSame('failed', $failed->status); $this->assertSame(1, $failed->attempts); $this->assertNotNull($failed->available_at);
        $this->assertSame(['delivered@example.com'], $failed->delivered_recipients);
        $this->assertStringNotContainsString('secret@example.com', $failed->last_error);
        $this->assertSame(CashSession::STATUS_OPEN, $session->fresh()->status);
    }

    public function test_recovery_command_dispatches_only_available_company_isolated_rows(): void
    {
        Queue::fake(); [$company, $branch, $user, $register] = $this->context(); [$other, $otherBranch, $otherUser, $otherRegister] = $this->context();
        $session = $this->rawSession($company, $branch, $user, $register); $otherSession = $this->rawSession($other, $otherBranch, $otherUser, $otherRegister);
        $available = CashSessionMailNotification::create(['company_id'=>$company->id,'cash_session_id'=>$session->id,'notification_type'=>'opened','recipients'=>['a@example.com'],'status'=>'failed','attempts'=>1,'available_at'=>now()->subSecond()]);
        $future = CashSessionMailNotification::create(['company_id'=>$other->id,'cash_session_id'=>$otherSession->id,'notification_type'=>'opened','recipients'=>['b@example.com'],'status'=>'failed','attempts'=>1,'available_at'=>now()->addHour()]);
        $this->artisan('cash:notifications:dispatch-pending')->expectsOutput('Avisos despachados: 1')->assertSuccessful();
        Queue::assertPushed(SendCashSessionMailNotification::class, fn ($job) => $job->notificationId === $available->id);
        Queue::assertNotPushed(SendCashSessionMailNotification::class, fn ($job) => $job->notificationId === $future->id);
        $this->assertSame($company->id, $available->company_id); $this->assertSame($other->id, $future->company_id);
    }

    public function test_mailables_render_timezone_snapshots_and_conditional_usd_without_web_leakage(): void
    {
        [$company, $branch, $user, $register] = $this->context(); $company->update(['timezone'=>'America/Costa_Rica']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-14 18:00:00','UTC'));
        $session = $this->rawSession($company, $branch, $user, $register, ['accepts_usd_snapshot'=>true,'opening_amount_usd'=>20,'usd_exchange_rate'=>525]);
        $openingHtml = (new CashSessionOpenedMail($session))->render();
        $this->assertStringContainsString('14/08/2026 12:00:00', $openingHtml); $this->assertStringContainsString('Fondo inicial USD', $openingHtml);

        $session->update(['status'=>'closed','open_guard'=>null,'closed_by'=>$user->id,'closed_at'=>now()->addHour(),'expected_cash'=>1000,'counted_cash'=>1000,'difference_amount'=>0,'closing_notes'=>'Nota auditada']);
        $denomination = \App\Models\CashDenomination::forCompany($company->id)->active()->first();
        CashCountDetail::create(['cash_session_id'=>$session->id,'cash_denomination_id'=>$denomination->id,'count_type'=>'closing','quantity'=>2,'denomination_value'=>1234,'total_amount'=>2468,'counted_by'=>$user->id,'counted_at'=>now()]);
        $method=PaymentMethod::create(['company_id'=>$company->id,'code'=>'historical','name'=>'Actual','type'=>'other','is_active'=>true,'affects_cash'=>false,'sort_order'=>1]);
        CashPaymentReconciliation::create(['cash_session_id'=>$session->id,'payment_method_id'=>$method->id,'payment_method_code_snapshot'=>'old-code','payment_method_name_snapshot'=>'Nombre histórico','payment_method_type_snapshot'=>'other','expected_amount'=>100,'reported_amount'=>90,'difference_amount'=>-10,'reconciled_by'=>$user->id,'reconciled_at'=>now()]);
        $closingHtml=(new CashSessionClosedMail($session->fresh()))->render();
        $this->assertStringContainsString('Nombre histórico', $closingHtml); $this->assertStringContainsString('old-code', $closingHtml); $this->assertStringContainsString('1.234', $closingHtml); $this->assertStringContainsString('Nota auditada', $closingHtml);
        CarbonImmutable::setTestNow();
    }

    private function context(array $permissions = []): array
    {
        $company=Company::create(['trade_name'=>'Empresa '.Str::random(5),'currency'=>'CRC','timezone'=>'America/Costa_Rica','is_active'=>true]);
        $branch=Branch::create(['company_id'=>$company->id,'name'=>'Principal','code'=>'P'.Str::random(5),'is_active'=>true]);
        $settings=app(CompanyCashSettingsProvisioner::class)->provision($company); app(CashDenominationProvisioner::class)->provision($company);
        $user=User::factory()->create(); $role=Role::create(['company_id'=>$company->id,'name'=>'Rol '.Str::random(5),'is_active'=>true]);
        foreach($permissions as $name){$permission=Permission::firstOrCreate(['name'=>$name],['label'=>$name,'module'=>'Caja','is_active'=>true]);$role->permissions()->syncWithoutDetaching([$permission->id]);}
        $user->companies()->attach($company->id,['role_id'=>$role->id]); $user->branches()->attach($branch->id);
        $register=CashRegister::create(['company_id'=>$company->id,'branch_id'=>$branch->id,'code'=>'C'.Str::random(5),'name'=>'Caja Principal','is_active'=>true]);
        return[$company,$branch,$user,$register,$settings];
    }

    private function rawSession(Company $company, Branch $branch, User $user, CashRegister $register, array $overrides=[]): CashSession
    {
        return CashSession::create(array_merge(['company_id'=>$company->id,'branch_id'=>$branch->id,'cash_register_id'=>$register->id,'session_number'=>'CAJA-'.Str::random(8),'opened_by'=>$user->id,'status'=>'open','open_guard'=>'OPEN','currency_code'=>'CRC','opening_amount'=>1000,'tolerance_snapshot'=>0,'blind_closing_snapshot'=>true,'accepts_usd_snapshot'=>false,'opening_amount_usd'=>0,'opened_at'=>now()],$overrides));
    }

    private function closingPayload(Company $company, string $token, int $total): array
    {
        $denominations=\App\Models\CashDenomination::forCompany($company->id)->forCurrency('CRC')->active()->orderByDesc('value')->get();
        $remaining=$total; $counts=[]; foreach($denominations as $denomination){$counts[$denomination->id]=intdiv($remaining,(int)$denomination->value);$remaining%=(int)$denomination->value;}
        return ['request_token'=>$token,'denominations'=>$counts,'payments'=>[],'closing_notes'=>null];
    }
}
