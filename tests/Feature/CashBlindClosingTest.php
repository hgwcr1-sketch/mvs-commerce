<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashCountDetail;
use App\Models\CashDenomination;
use App\Models\CashMovement;
use App\Models\CashPaymentReconciliation;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CashSessionEvent;
use App\Models\Company;
use App\Models\CompanyCashSetting;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\CashDenominationProvisioner;
use App\Services\CompanyCashSettingsProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashBlindClosingTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_is_idempotent_freezes_session_and_cancel_reopens_before_submission(): void
    {
        [$company, $branch, $user, , $session] = $this->context(); $token = (string) Str::uuid();
        $this->start($user, $company, $branch, $session, $token)->assertRedirect(route('cash.closing.create', $session));
        $this->start($user, $company, $branch, $session, $token)->assertRedirect();
        $this->assertSame(CashSession::STATUS_CLOSING, $session->fresh()->status); $this->assertSame(CashSession::OPEN_GUARD, $session->fresh()->open_guard);
        $this->assertSame(1, $session->events()->where('event_type', CashSessionEvent::TYPE_CLOSING_STARTED)->count());
        $this->actingAs($user)->withSession($this->ctx($company, $branch))->post(route('cash.closing.cancel', $session))->assertRedirect(route('cash.index'));
        $this->assertSame(CashSession::STATUS_OPEN, $session->fresh()->status); $this->assertSame(1, $session->events()->where('event_type', CashSessionEvent::TYPE_CLOSING_CANCELLED)->count());
    }

    public function test_blind_form_has_eleven_denominations_dynamic_methods_and_no_control_value_leaks(): void
    {
        [$company, $branch, $user, , $session] = $this->context();
        $cash = $this->method($company, 'cash', 'Efectivo', true, true); $card = $this->method($company, 'card', 'Tarjeta', true); $paypal = $this->method($company, 'paypal', 'PayPal', false);
        $this->payment($company, $branch, $user, $session, $paypal, 777);
        CashDenomination::forCompany($company->id)->delete();
        $this->start($user, $company, $branch, $session);
        $response = $this->actingAs($user)->withSession($this->ctx($company, $branch))->get(route('cash.closing.create', $session));
        $response->assertOk()->assertSee('Cierre ciego activo')->assertSee('Efectivo')->assertSee('Tarjeta')->assertSee('PayPal')->assertSee('Billetes')->assertSee('Monedas')->assertSee('Volver')
            ->assertDontSee('Esperado:')->assertDontSee('777.0000')->assertDontSee('difference')->assertDontSee('tolerance')
            ->assertSee('bg-amber-500 px-6 py-3 font-normal text-black hover:bg-amber-600', false)
            ->assertSee('autocomplete="off"', false)
            ->assertSee('Revise el conteo declarado')
            ->assertSee('Total de efectivo contado')
            ->assertSee('Denominaciones declaradas')
            ->assertSee('Formas de pago declaradas')
            ->assertSee('Volver a revisar')
            ->assertSee('Confirmar cierre')
            ->assertSee('positiveDenominations', false)
            ->assertSee('@submit.prevent="requestConfirmation"', false)
            ->assertSee('if(this.processing)return', false)
            ->assertSee('$refs.closingForm.submit()', false);
        $this->assertSame(11, substr_count($response->getContent(), 'name="denominations['));
        foreach (CashDenomination::forCompany($company->id)->forCurrency('CRC')->active()->get() as $denomination) {
            $response->assertSee('name="denominations['.$denomination->id.']" x-model.number="quantities['.$denomination->id.']" type="number" min="0" step="1" autocomplete="off"', false);
        }
        foreach ([$cash, $card, $paypal] as $method) {
            $response->assertSee('name="payments['.$method->id.'][reported_amount]" x-model.number="reportedPayments['.$method->id.']" type="number" min="0" step="1" autocomplete="off"', false);
        }
        $this->assertFalse($card->is_active === false); $this->assertFalse($paypal->is_active);
    }

    public function test_distinct_declared_quantities_are_saved_by_denomination_id_and_total_fifty_thousand(): void
    {
        [$company, $branch, $user, , $session] = $this->context();
        $denominations = CashDenomination::forCompany($company->id)->forCurrency('CRC')->active()->get();
        $twentyThousand = $denominations->firstWhere('value', '20000.0000');
        $tenThousand = $denominations->firstWhere('value', '10000.0000');
        $fiveThousand = $denominations->firstWhere('value', '5000.0000');
        $this->assertNotNull($twentyThousand); $this->assertNotNull($tenThousand); $this->assertNotNull($fiveThousand);

        $payload = $this->payload($company, $session, 0);
        $payload['denominations'][$twentyThousand->id] = 2;
        $payload['denominations'][$tenThousand->id] = 1;
        $payload['denominations'][$fiveThousand->id] = 0;

        $this->start($user, $company, $branch, $session);
        $this->submit($user, $company, $branch, $session, $payload)->assertRedirect(route('cash.closing.show', $session));

        $details = $session->countDetails()->closing()->get()->keyBy('cash_denomination_id');
        $this->assertSame(11, $details->count());
        $this->assertSame(11, $details->keys()->unique()->count());
        $this->assertFalse($details->contains(fn ($detail) => (int) $detail->denomination_value === 50000));
        $this->assertSame('50000.0000', $session->fresh()->counted_cash);

        foreach ($denominations as $denomination) {
            $expectedQuantity = match ((int) $denomination->value) { 20000 => 2, 10000 => 1, default => 0 };
            $detail = $details->get($denomination->id);
            $this->assertNotNull($detail);
            $this->assertSame($expectedQuantity, $detail->quantity);
            $this->assertSame(number_format((int) $denomination->value * $expectedQuantity, 4, '.', ''), $detail->total_amount);
        }
    }

    public function test_confirmation_calculates_cash_with_sales_and_movements_and_snapshots_all_rows(): void
    {
        [$company, $branch, $user, , $session] = $this->context(); $session->update(['opening_amount' => 1000]);
        $cash = $this->method($company, 'cash', 'Efectivo', true, true); $card = $this->method($company, 'card', 'Tarjeta', true);
        $this->payment($company, $branch, $user, $session, $cash, 500, 2000, 1500); $this->payment($company, $branch, $user, $session, $card, 300);
        $this->movement($session, $user, CashMovement::TYPE_ENTRY, CashMovement::DIRECTION_IN, 100); $this->movement($session, $user, CashMovement::TYPE_EXIT, CashMovement::DIRECTION_OUT, 50);
        $this->start($user, $company, $branch, $session);
        $payload = $this->payload($company, $session, 1550, [$cash->id => 500, $card->id => 300]);
        $this->submit($user, $company, $branch, $session, $payload)->assertRedirect(route('cash.closing.show', $session));
        $closed = $session->fresh();
        $this->assertSame(CashSession::STATUS_CLOSED, $closed->status); $this->assertNull($closed->open_guard);
        $this->assertSame('1550.0000', $closed->expected_cash); $this->assertSame('1550.0000', $closed->counted_cash); $this->assertSame('0.0000', $closed->difference_amount);
        $this->assertSame(11, $closed->countDetails()->closing()->count()); $this->assertSame(2, $closed->paymentReconciliations()->count());
        $reconciliations = $closed->paymentReconciliations()->get()->keyBy('payment_method_id');
        $this->assertSame('500.0000', $reconciliations->get($cash->id)->expected_amount);
        $this->assertSame('Tarjeta', $reconciliations->get($card->id)->payment_method_name_snapshot); $this->assertSame('300.0000', $reconciliations->get($card->id)->expected_amount);
    }

    public function test_surplus_and_shortage_are_stored_without_compensation(): void
    {
        foreach ([5 => '5.0000', -5 => '-5.0000'] as $delta => $expected) {
            [$company, $branch, $user, , $session] = $this->context('Diff'.$delta); $this->start($user, $company, $branch, $session);
            $this->submit($user, $company, $branch, $session, $this->payload($company, $session, 1000 + $delta))->assertRedirect();
            $this->assertSame($expected, $session->fresh()->difference_amount);
        }
    }

    public function test_voided_payments_and_non_completed_sales_are_excluded(): void
    {
        [$company, $branch, $user, , $session] = $this->context(); $card = $this->method($company, 'card', 'Tarjeta', true);
        $this->payment($company, $branch, $user, $session, $card, 100);
        $voidedPayment = SalePayment::latest('id')->first(); $voidedPayment->update(['status' => SalePayment::STATUS_VOIDED]);
        $this->payment($company, $branch, $user, $session, $card, 200); Sale::latest('id')->first()->update(['status' => Sale::STATUS_VOIDED]);
        $this->payment($company, $branch, $user, $session, $card, 300);
        $expected = app(\App\Services\Cash\CashPaymentExpectedAmountService::class)->expectedAmounts($session);
        $this->assertSame(300.0, $expected->get($card->id));
    }

    public function test_difference_at_tolerance_closes_without_authorization(): void
    {
        [$company, $branch, $user, , $session] = $this->context(); CompanyCashSetting::where('company_id',$company->id)->update(['require_difference_authorization'=>true]); $session->update(['tolerance_snapshot'=>5]);
        $this->start($user,$company,$branch,$session); $this->submit($user,$company,$branch,$session,$this->payload($company,$session,995))->assertRedirect();
        $this->assertSame(CashSession::STATUS_CLOSED,$session->fresh()->status); $this->assertNull($session->fresh()->difference_authorized_by);
    }

    public function test_each_difference_is_compared_to_tolerance_and_requires_specific_authorization(): void
    {
        [$company, $branch, $user, , $session] = $this->context();
        CompanyCashSetting::where('company_id', $company->id)->update(['require_difference_authorization' => true]); $session->update(['tolerance_snapshot' => 5]);
        $card = $this->method($company, 'card', 'Tarjeta', true); $sinpe = $this->method($company, 'sinpe', 'SINPE', true);
        $this->payment($company, $branch, $user, $session, $card, 100); $this->payment($company, $branch, $user, $session, $sinpe, 100);
        $this->start($user, $company, $branch, $session);
        $this->submit($user, $company, $branch, $session, $this->payload($company, $session, 1000, [$card->id => 90, $sinpe->id => 110]))->assertRedirect();
        $this->assertSame(CashSession::STATUS_CLOSING, $session->fresh()->status); $this->assertNotNull($session->fresh()->closing_submitted_at);
        $this->assertEqualsCanonicalizing([-10.0, 10.0], $session->paymentReconciliations()->pluck('difference_amount')->map(fn($v)=>(float)$v)->all());
        $without = $this->user($company, $branch, ['caja.administrar']);
        $this->actingAs($without)->withSession($this->ctx($company, $branch))->post(route('cash.closing.authorize', $session), ['confirmation' => 1])->assertForbidden();
        $authorizer = $this->user($company, $branch, ['caja.autorizar_diferencia', 'caja.ver_todas']);
        $this->actingAs($authorizer)->withSession($this->ctx($company, $branch))->post(route('cash.closing.authorize', $session), ['confirmation' => 1])->assertRedirect();
        $this->assertSame(CashSession::STATUS_CLOSED, $session->fresh()->status); $this->assertSame($authorizer->id, $session->fresh()->difference_authorized_by);
        $this->assertSame(1, $session->events()->where('event_type', CashSessionEvent::TYPE_DIFFERENCE_AUTHORIZED)->count());
    }

    public function test_confirmation_is_idempotent_and_cannot_be_cancelled_or_reopened_after_submission(): void
    {
        [$company, $branch, $user, , $session] = $this->context(); $this->start($user, $company, $branch, $session); $payload = $this->payload($company, $session, 1000);
        $this->submit($user, $company, $branch, $session, $payload)->assertRedirect(); $this->submit($user, $company, $branch, $session, $payload)->assertRedirect();
        $this->assertSame(11, CashCountDetail::count()); $this->assertSame(1, $session->events()->where('event_type', CashSessionEvent::TYPE_CLOSED)->count());
        $this->actingAs($user)->withSession($this->ctx($company, $branch))->post(route('cash.closing.cancel', $session))->assertSessionHasErrors('cash_session_id');
        $this->assertSame(CashSession::STATUS_CLOSED, $session->fresh()->status);
    }

    public function test_invalid_incomplete_extra_and_inactive_denominations_are_rejected(): void
    {
        [$company, $branch, $user, , $session] = $this->context(); $this->start($user, $company, $branch, $session); $payload = $this->payload($company, $session, 1000);
        array_pop($payload['denominations']); $this->submit($user, $company, $branch, $session, $payload)->assertSessionHasErrors('denominations');
        $payload = $this->payload($company, $session, 1000); $payload['denominations'][999999] = 0; $this->submit($user, $company, $branch, $session, $payload)->assertSessionHasErrors('denominations');
        CashDenomination::forCompany($company->id)->first()->update(['is_active' => false]); $this->submit($user, $company, $branch, $session, $this->payload($company, $session, 1000))->assertSessionHasErrors('denominations');
        $this->assertSame(0, CashCountDetail::count());
    }

    public function test_failure_in_reconciliation_or_event_rolls_back_all_closing_data(): void
    {
        [$company, $branch, $user, , $session] = $this->context(); $method = $this->method($company, 'card', 'Tarjeta', true); $this->start($user, $company, $branch, $session);
        CashPaymentReconciliation::creating(fn()=>throw new \RuntimeException('reconciliation failure')); $this->withoutExceptionHandling();
        try { $this->submit($user, $company, $branch, $session, $this->payload($company, $session, 1000, [$method->id=>0])); $this->fail('No falló.'); } catch (\RuntimeException $e) { $this->assertSame('reconciliation failure', $e->getMessage()); }
        $this->assertSame(0, CashCountDetail::count()); $this->assertSame(0, CashPaymentReconciliation::count()); $this->assertNull($session->fresh()->closing_submitted_at);
    }

    public function test_closed_event_failure_rolls_back_session_counts_and_reconciliations(): void
    {
        [$company,$branch,$user,,$session]=$this->context(); $this->start($user,$company,$branch,$session);
        CashSessionEvent::creating(function(CashSessionEvent $event){if($event->event_type===CashSessionEvent::TYPE_CLOSED)throw new \RuntimeException('event failure');}); $this->withoutExceptionHandling();
        try{$this->submit($user,$company,$branch,$session,$this->payload($company,$session,1000));$this->fail('No falló.');}catch(\RuntimeException $e){$this->assertSame('event failure',$e->getMessage());}
        $this->assertSame(CashSession::STATUS_CLOSING,$session->fresh()->status);$this->assertNull($session->fresh()->closing_submitted_at);$this->assertSame(0,CashCountDetail::count());$this->assertSame(0,CashPaymentReconciliation::count());
    }

    public function test_individual_and_shared_isolation_permissions_are_enforced(): void
    {
        [$company, $branch, $owner, , $session] = $this->context(); $other = $this->user($company, $branch, ['caja.cerrar']);
        $this->start($other, $company, $branch, $session)->assertSessionHasErrors('cash_session_id');
        CompanyCashSetting::where('company_id', $company->id)->update(['session_mode' => CompanyCashSetting::SESSION_MODE_SHARED]);
        $this->start($other, $company, $branch, $session)->assertRedirect();
        $this->actingAs($owner)->withSession($this->ctx($company, $branch))->get(route('cash.closing.create', $session))->assertForbidden();
        $admin = $this->user($company, $branch, ['caja.cerrar', 'caja.administrar']);
        $this->actingAs($admin)->withSession($this->ctx($company, $branch))->get(route('cash.closing.create', $session))->assertOk();
        $none = $this->user($company, $branch, []); $this->start($none, $company, $branch, $session)->assertForbidden();
    }

    public function test_basic_result_hides_breakdown_but_admin_sees_it_in_company_timezone(): void
    {
        [$company, $branch, $user, , $session] = $this->context(); $company->update(['timezone' => 'America/Costa_Rica']); $this->start($user, $company, $branch, $session);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-14 18:30:00','UTC')); $this->submit($user, $company, $branch, $session, $this->payload($company, $session, 1000)); CarbonImmutable::setTestNow();
        $this->actingAs($user)->withSession($this->ctx($company, $branch))->get(route('cash.closing.show', $session))->assertOk()->assertSee('Cierre enviado correctamente')->assertDontSee('Esperado')->assertDontSee('Diferencia');
        $admin = $this->user($company, $branch, ['caja.administrar']);
        $this->actingAs($admin)->withSession($this->ctx($company, $branch))->get(route('cash.closing.show', $session))->assertOk()->assertSee('Esperado')->assertSee('Diferencia')->assertSee('14/08/2026 12:30');
    }

    public function test_no_mutation_routes_exist_for_counts_or_reconciliations(): void
    {
        foreach (['cash.counts.edit','cash.counts.update','cash.counts.destroy','cash.reconciliations.edit','cash.reconciliations.update','cash.reconciliations.destroy'] as $name) $this->assertFalse(Route::has($name));
    }

    private function context(string $name='Empresa'): array
    {
        $company=Company::create(['trade_name'=>$name,'currency'=>'CRC','timezone'=>'America/Costa_Rica','is_active'=>true]); $branch=Branch::create(['company_id'=>$company->id,'name'=>'Principal','code'=>'P'.uniqid(),'is_active'=>true]); app(CompanyCashSettingsProvisioner::class)->provision($company); app(CashDenominationProvisioner::class)->provision($company);
        $user=$this->user($company,$branch,['caja.cerrar','caja.ver']); $register=CashRegister::create(['company_id'=>$company->id,'branch_id'=>$branch->id,'code'=>'C'.uniqid(),'name'=>'Caja','is_active'=>true]);
        $session=CashSession::create(['company_id'=>$company->id,'branch_id'=>$branch->id,'cash_register_id'=>$register->id,'session_number'=>'CAJA-'.$company->id,'opened_by'=>$user->id,'status'=>CashSession::STATUS_OPEN,'open_guard'=>CashSession::OPEN_GUARD,'opening_amount'=>1000,'tolerance_snapshot'=>0,'blind_closing_snapshot'=>true,'opened_at'=>now()]); return[$company,$branch,$user,$register,$session];
    }

    private function user(Company $company,Branch $branch,array $permissions): User
    {
        $user=User::factory()->create(); $role=Role::create(['company_id'=>$company->id,'name'=>'R'.uniqid(),'is_active'=>true]); foreach($permissions as $name){$permission=Permission::firstOrCreate(['name'=>$name],['label'=>$name,'module'=>'Caja','is_active'=>true]);$role->permissions()->attach($permission);} $user->companies()->attach($company->id,['role_id'=>$role->id]);$user->branches()->attach($branch->id);return$user;
    }

    private function method(Company $company,string $code,string $name,bool $active,bool $cash=false): PaymentMethod{return PaymentMethod::create(['company_id'=>$company->id,'code'=>$code,'name'=>$name,'type'=>$cash?PaymentMethod::TYPE_CASH:($code==='sinpe'?PaymentMethod::TYPE_SINPE:PaymentMethod::TYPE_OTHER),'is_active'=>$active,'affects_cash'=>$cash,'sort_order'=>1]);}
    private function payment(Company $company,Branch $branch,User $user,CashSession $session,PaymentMethod $method,float $amount,float $received=0,float $change=0): void{$sale=Sale::create(['company_id'=>$company->id,'branch_id'=>$branch->id,'user_id'=>$user->id,'cash_session_id'=>$session->id,'sale_number'=>'POS-'.Str::random(6),'checkout_token'=>Str::uuid(),'request_fingerprint'=>hash('sha256',Str::random()),'status'=>Sale::STATUS_COMPLETED,'completed_at'=>now()]);SalePayment::create(['sale_id'=>$sale->id,'cash_session_id'=>$session->id,'payment_method_id'=>$method->id,'affects_cash_snapshot'=>$method->affects_cash,'created_by'=>$user->id,'amount'=>$amount,'received_amount'=>$received?:$amount,'change_amount'=>$change,'cash_effect_amount'=>$method->affects_cash?$amount:0,'status'=>SalePayment::STATUS_COMPLETED]);}
    private function movement(CashSession $session,User $user,string $type,string $direction,float $amount): void{CashMovement::create(['company_id'=>$session->company_id,'branch_id'=>$session->branch_id,'cash_register_id'=>$session->cash_register_id,'cash_session_id'=>$session->id,'type'=>$type,'direction'=>$direction,'amount'=>$amount,'concept'=>$type,'reason'=>'Prueba','created_by'=>$user->id,'occurred_at'=>now()]);}
    private function start(User $user,Company $company,Branch $branch,CashSession $session,?string $token=null){return$this->actingAs($user)->withSession($this->ctx($company,$branch))->post(route('cash.closing.start',$session),['request_token'=>$token??(string)Str::uuid()]);}
    private function submit(User $user,Company $company,Branch $branch,CashSession $session,array $payload){return$this->actingAs($user)->withSession($this->ctx($company,$branch))->post(route('cash.closing.submit',$session),$payload);}
    private function ctx(Company $company,Branch $branch): array{return['active_company_id'=>$company->id,'active_branch_id'=>$branch->id];}
    private function payload(Company $company,CashSession $session,int $cashTotal,array $reports=[]): array
    {
        $denominations=CashDenomination::forCompany($company->id)->forCurrency('CRC')->active()->orderBy('sort_order')->get();$counts=$denominations->mapWithKeys(fn($d)=>[$d->id=>0])->all();$remaining=$cashTotal;foreach($denominations as $d){$counts[$d->id]=intdiv($remaining,(int)$d->value);$remaining%=(int)$d->value;}$methods=app(\App\Services\Cash\CashPaymentExpectedAmountService::class)->methods($session);$payments=$methods->mapWithKeys(fn($m)=>[$m->id=>['reported_amount'=>$reports[$m->id]??0,'reference'=>null,'notes'=>null]])->all();return['request_token'=>(string)Str::uuid(),'denominations'=>$counts,'payments'=>$payments,'closing_notes'=>null];
    }
}
