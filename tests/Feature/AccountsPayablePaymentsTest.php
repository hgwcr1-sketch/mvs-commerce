<?php

namespace Tests\Feature;

use App\Models\AccountPayable;
use App\Models\Branch;
use App\Models\CashDenomination;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Cash\CashClosingService;
use App\Services\Cash\CashExpectedAmountService;
use App\Services\Cash\CashPaymentExpectedAmountService;
use App\Services\CashDenominationProvisioner;
use App\Services\CompanyCashSettingsProvisioner;
use App\Services\Purchases\AccountsPayableService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountsPayablePaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_payment_updates_paid_balance_and_status(): void
    {
        [$c,$b,$u,$s,$a,$cash]=$this->context(); $payment=$this->pay($a,$u,$c,$b,$s,$cash,400);
        $this->assertSame('400.0000',$payment->amount); $this->assertSame('400.0000',$a->fresh()->paid_amount); $this->assertSame('600.0000',$a->fresh()->balance_due); $this->assertSame(AccountPayable::STATUS_PARTIAL,$a->fresh()->status);
    }

    public function test_total_payment_marks_account_paid(): void
    {
        [$c,$b,$u,$s,$a,$cash]=$this->context(); $this->pay($a,$u,$c,$b,$s,$cash,1000);
        $this->assertSame('1000.0000',$a->fresh()->paid_amount); $this->assertSame('0.0000',$a->fresh()->balance_due); $this->assertSame(AccountPayable::STATUS_PAID,$a->fresh()->status);
    }

    public function test_overpayment_is_rejected(): void
    {
        [$c,$b,$u,$s,$a,$cash]=$this->context(); $this->expectException(ValidationException::class); $this->pay($a,$u,$c,$b,$s,$cash,1001);
    }

    public function test_paid_account_rejects_more_payments(): void
    {
        [$c,$b,$u,$s,$a,$cash]=$this->context(); $this->pay($a,$u,$c,$b,$s,$cash,1000); $this->expectException(ValidationException::class); $this->pay($a,$u,$c,$b,$s,$cash,1);
    }

    public function test_cancelled_account_rejects_payments(): void
    {
        [$c,$b,$u,$s,$a,$cash]=$this->context(); $a->update(['status'=>AccountPayable::STATUS_CANCELLED,'balance_due'=>0]); $this->expectException(ValidationException::class); $this->pay($a,$u,$c,$b,$s,$cash,1);
    }

    public function test_cash_payment_reduces_expected_cash_once(): void
    {
        [$c,$b,$u,$s,$a,$cash]=$this->context(); $payment=$this->pay($a,$u,$c,$b,$s,$cash,200);
        $this->assertTrue($payment->affects_cash_snapshot); $this->assertSame('200.0000',$payment->cash_effect_amount); $this->assertSame(800.0,app(CashExpectedAmountService::class)->calculate($s));
    }

    public function test_card_and_sinpe_are_classified_by_payment_method(): void
    {
        [$c,$b,$u,$s,$a,,$card,$sinpe]=$this->context(); $this->pay($a,$u,$c,$b,$s,$card,300); $this->pay($a,$u,$c,$b,$s,$sinpe,200);
        $expected=app(CashPaymentExpectedAmountService::class)->breakdown($s); $this->assertSame(300.0,$expected[$card->id]['payables']); $this->assertSame(200.0,$expected[$sinpe->id]['payables']); $this->assertSame(1000.0,app(CashExpectedAmountService::class)->calculate($s));
    }

    public function test_company_and_branch_are_isolated(): void
    {
        [$c,$b,$u,$s,$a,$cash]=$this->context(); [$other,$otherBranch]=$this->context('Otra'); $this->expectException(ModelNotFoundException::class); $this->pay($a,$u,$other,$otherBranch,$s,$cash,100);
    }

    public function test_closing_snapshots_payment_as_accounts_payable(): void
    {
        [$c,$b,$u,$s,$a,,$card]=$this->context(); $this->pay($a,$u,$c,$b,$s,$card,250);
        $closing=app(CashClosingService::class); $closing->start($u,$c->id,$b->id,$s->id,(string)Str::uuid()); $closing->submit($u,$c->id,$b->id,$s->id,$this->closingPayload($c,$s,1000,[$card->id=>250]));
        $row=$s->paymentReconciliations()->where('payment_method_id',$card->id)->firstOrFail(); $this->assertSame('250.0000',$row->payables_amount); $this->assertSame('250.0000',$row->expected_amount); $this->assertSame('250.0000',$row->reported_amount);
    }

    public function test_payment_is_not_duplicated_as_cash_movement(): void
    {
        [$c,$b,$u,$s,$a,$cash]=$this->context(); $this->pay($a,$u,$c,$b,$s,$cash,200);
        $this->assertSame(1,$a->payments()->count()); $this->assertSame(0,$s->movements()->count()); $this->assertSame(800.0,app(CashExpectedAmountService::class)->calculate($s)); $this->assertSame(200.0,app(CashPaymentExpectedAmountService::class)->expectedAmounts($s)->get($cash->id));
    }

    private function pay(AccountPayable $account,User $user,Company $company,Branch $branch,CashSession $session,PaymentMethod $method,float $amount)
    {
        return app(AccountsPayableService::class)->pay($account,['amount'=>$amount,'payment_method_id'=>$method->id,'cash_session_id'=>$session->id,'reference'=>'REF','notes'=>'Prueba'],$user,$company->id,$branch->id);
    }

    private function context(string $name='Empresa'): array
    {
        $c=Company::create(['trade_name'=>$name.uniqid(),'currency'=>'CRC','timezone'=>'America/Costa_Rica','is_active'=>true]); $b=Branch::create(['company_id'=>$c->id,'name'=>'Principal','code'=>'P'.uniqid(),'is_active'=>true]);
        $u=User::factory()->create(); $r=Role::create(['company_id'=>$c->id,'name'=>'Caja '.uniqid(),'is_active'=>true]); foreach(['caja.cerrar','caja.ver'] as $n){$p=Permission::firstOrCreate(['name'=>$n],['label'=>$n,'module'=>'Caja','is_active'=>true]);$r->permissions()->attach($p);} $u->companies()->attach($c->id,['role_id'=>$r->id]);$u->branches()->attach($b->id);
        app(CompanyCashSettingsProvisioner::class)->provision($c); app(CashDenominationProvisioner::class)->provision($c);
        $cash=$this->method($c,'cash','Efectivo',PaymentMethod::TYPE_CASH,true); $card=$this->method($c,'card','Tarjeta',PaymentMethod::TYPE_CARD,false); $sinpe=$this->method($c,'sinpe','SINPE',PaymentMethod::TYPE_SINPE,false);
        $register=CashRegister::create(['company_id'=>$c->id,'branch_id'=>$b->id,'code'=>'C'.uniqid(),'name'=>'Caja','is_active'=>true]); $s=CashSession::create(['company_id'=>$c->id,'branch_id'=>$b->id,'cash_register_id'=>$register->id,'session_number'=>'CAJA-'.uniqid(),'opened_by'=>$u->id,'status'=>'open','open_guard'=>'OPEN','opening_amount'=>1000,'tolerance_snapshot'=>0,'blind_closing_snapshot'=>true,'opened_at'=>now()]);
        $supplier=Supplier::create(['company_id'=>$c->id,'supplier_type'=>'company','name'=>'Proveedor','is_active'=>true]); $purchase=Purchase::create(['company_id'=>$c->id,'branch_id'=>$b->id,'supplier_id'=>$supplier->id,'user_id'=>$u->id,'number'=>'CP-'.uniqid(),'purchase_date'=>today(),'payment_type'=>'credit','due_date'=>today()->addMonth(),'total'=>1000,'status'=>'posted']);
        $a=AccountPayable::create(['company_id'=>$c->id,'branch_id'=>$b->id,'supplier_id'=>$supplier->id,'purchase_id'=>$purchase->id,'original_amount'=>1000,'paid_amount'=>0,'balance_due'=>1000,'issue_date'=>today(),'due_date'=>today()->addMonth(),'status'=>'pending','created_by'=>$u->id]); return[$c,$b,$u,$s,$a,$cash,$card,$sinpe];
    }

    private function method(Company $company,string $code,string $name,string $type,bool $cash):PaymentMethod{return PaymentMethod::create(['company_id'=>$company->id,'code'=>$code,'name'=>$name,'type'=>$type,'is_active'=>true,'affects_cash'=>$cash,'sort_order'=>1]);}
    private function closingPayload(Company $company,CashSession $session,int $cashTotal,array $reports):array{$denominations=CashDenomination::forCompany($company->id)->forCurrency('CRC')->active()->orderByDesc('value')->get();$remaining=$cashTotal;$counts=[];foreach($denominations as $d){$counts[$d->id]=intdiv($remaining,(int)$d->value);$remaining%=(int)$d->value;}$methods=app(CashPaymentExpectedAmountService::class)->methods($session);return['request_token'=>(string)Str::uuid(),'denominations'=>$counts,'payments'=>$methods->mapWithKeys(fn($m)=>[$m->id=>['reported_amount'=>$reports[$m->id]??0,'reference'=>null,'notes'=>null]])->all(),'closing_notes'=>null];}
}
