<?php

namespace Tests\Feature;

use App\Models\{AccountReceivable,Branch,CashRegister,CashSession,Company,Customer,PaymentMethod,Permission,Product,ProductCategory,Role,Sale,Unit,User};
use App\Services\PaymentMethodProvisioner;
use App\Services\Sales\SaleVoidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreditV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_credit_rules_customer_limit_days_due_date_and_no_cash_payment(): void
    {
        [$company,$branch,$user,$session,$credit,$product] = $this->context();
        $customer = $this->customer($company,0,30);
        $this->checkout($user,$company,$branch,$session,$credit,$product,$customer)->assertUnprocessable()->assertJsonPath('message','Este cliente no tiene crédito autorizado.');
        $customer->update(['credit_limit'=>100000,'credit_days'=>0]);
        $this->checkout($user,$company,$branch,$session,$credit,$product,$customer)->assertUnprocessable()->assertJsonPath('message','El cliente no tiene un plazo de crédito configurado.');
        $customer->update(['credit_days'=>30]);
        $response=$this->checkout($user,$company,$branch,$session,$credit,$product,$customer)->assertOk();
        $sale=Sale::findOrFail($response->json('sale_id')); $account=$sale->accountReceivable;
        $this->assertSame(Sale::CONDITION_CREDIT,$sale->sale_condition); $this->assertSame('0.0000',$sale->paid_total); $this->assertSame('1000.0000',$sale->balance_due);
        $this->assertSame(today()->addDays(30)->toDateString(),$account->due_date->toDateString()); $this->assertSame(AccountReceivable::STATUS_PENDING,$account->status);
        $this->assertDatabaseCount('sale_payments',0); $this->assertSame(1,AccountReceivable::where('sale_id',$sale->id)->count());
    }

    public function test_credit_requires_real_customer_rejects_insufficient_and_mixed_credit(): void
    {
        [$c,$b,$u,$s,$credit,$p,$cash]=$this->context();
        $this->checkout($u,$c,$b,$s,$credit,$p,null)->assertUnprocessable()->assertJsonPath('message','Para vender a crédito debe seleccionar un cliente.');
        $customer=$this->customer($c,500,30);
        $this->checkout($u,$c,$b,$s,$credit,$p,$customer)->assertUnprocessable()->assertJsonPath('message','El cliente no tiene crédito disponible suficiente.');
        $customer->update(['credit_limit'=>5000]);
        $this->checkout($u,$c,$b,$s,$credit,$p,$customer,[["payment_method_id"=>$credit->id,"amount"=>500],["payment_method_id"=>$cash->id,"amount"=>500,"received_amount"=>500]])->assertUnprocessable();
        $this->assertDatabaseCount('sales',0); $this->assertDatabaseCount('accounts_receivable',0);
    }

    public function test_abonos_partial_total_overpayment_cash_session_and_paid_lock(): void
    {
        [$c,$b,$u,$s,$credit,$p,$cash]=$this->context(['pos.acceder','ventas.crear','cuentas_cobrar.ver','cuentas_cobrar.abonar']);
        $customer=$this->customer($c,5000,30); $saleId=$this->checkout($u,$c,$b,$s,$credit,$p,$customer)->json('sale_id'); $account=Sale::findOrFail($saleId)->accountReceivable;
        $s->update(['status'=>CashSession::STATUS_CLOSED,'open_guard'=>null]);
        $this->pay($u,$c,$b,$account,$cash,$s,100)->assertSessionHasErrors('cash_session_id'); $this->assertDatabaseCount('accounts_receivable_payments',0);
        $s->update(['status'=>CashSession::STATUS_OPEN,'open_guard'=>CashSession::OPEN_GUARD]);
        $this->pay($u,$c,$b,$account,$cash,$s,400)->assertRedirect(); $this->assertSame('600.0000',$account->fresh()->balance_due); $this->assertSame(AccountReceivable::STATUS_PARTIAL,$account->fresh()->status);
        $this->assertSame(400.0,app(\App\Services\Cash\CashExpectedAmountService::class)->calculate($s));
        $this->assertSame(400.0,app(\App\Services\Cash\CashPaymentExpectedAmountService::class)->expectedAmounts($s)->get($cash->id));
        $this->pay($u,$c,$b,$account,$cash,$s,700)->assertSessionHasErrors('amount');
        $this->pay($u,$c,$b,$account,$cash,$s,600)->assertRedirect(); $this->assertSame(AccountReceivable::STATUS_PAID,$account->fresh()->status);
        $this->pay($u,$c,$b,$account,$cash,$s,1)->assertSessionHasErrors('account');
        $this->assertDatabaseCount('accounts_receivable_payments',2); $this->assertDatabaseHas('accounts_receivable_payments',['cash_session_id'=>$s->id,'cash_effect_amount'=>400]);
    }

    public function test_company_branch_isolation_overdue_dashboard_and_safe_void(): void
    {
        [$c,$b,$u,$s,$credit,$p]=$this->context(['pos.acceder','ventas.crear','ventas.anular','cuentas_cobrar.ver']); $customer=$this->customer($c,5000,1);
        $sale=Sale::findOrFail($this->checkout($u,$c,$b,$s,$credit,$p,$customer)->json('sale_id')); $account=$sale->accountReceivable; $account->update(['due_date'=>today()->subDay(),'status'=>AccountReceivable::STATUS_PENDING]);
        $this->actingAs($u)->withSession($this->ctx($c,$b))->get(route('dashboard'))->assertOk()->assertSee('Créditos vencidos')->assertSee('1 cuentas');
        [$other,$otherBranch,$otherUser]=$this->context('Ajena'); $this->actingAs($otherUser)->withSession($this->ctx($other,$otherBranch))->get(route('cuentas-por-cobrar.show',$account))->assertNotFound();
        session($this->ctx($c,$b)); app(SaleVoidService::class)->void($sale,$u,'Error'); $this->assertSame(AccountReceivable::STATUS_CANCELLED,$account->fresh()->status); $this->assertSame('0.0000',$account->fresh()->balance_due);
    }

    private function context(array|string $permissions=['pos.acceder','ventas.crear']): array
    {
        $name=is_string($permissions)?$permissions:'Empresa'; if(is_string($permissions))$permissions=['pos.acceder','ventas.crear','cuentas_cobrar.ver'];
        $c=Company::create(['trade_name'=>$name.uniqid(),'currency'=>'CRC','timezone'=>'America/Costa_Rica','credit_alert_days'=>5,'is_active'=>true]); $b=Branch::create(['company_id'=>$c->id,'name'=>'Principal','code'=>'P'.uniqid(),'is_active'=>true]);
        $u=User::factory()->create();$r=Role::create(['company_id'=>$c->id,'name'=>'R'.uniqid(),'is_active'=>true]);foreach($permissions as $n){$perm=Permission::firstOrCreate(['name'=>$n],['label'=>$n,'module'=>'Test','is_active'=>true]);$r->permissions()->attach($perm);} $u->companies()->attach($c->id,['role_id'=>$r->id]);$u->branches()->attach($b->id);
        app(PaymentMethodProvisioner::class)->provision($c);$credit=PaymentMethod::forCompany($c->id)->where('type','credit')->firstOrFail();$cash=PaymentMethod::forCompany($c->id)->where('type','cash')->firstOrFail();
        $register=CashRegister::create(['company_id'=>$c->id,'branch_id'=>$b->id,'code'=>'C'.uniqid(),'name'=>'Caja','is_active'=>true]);$session=CashSession::create(['company_id'=>$c->id,'branch_id'=>$b->id,'cash_register_id'=>$register->id,'session_number'=>'CAJA-'.uniqid(),'opened_by'=>$u->id,'status'=>'open','open_guard'=>'OPEN','opening_amount'=>0,'opened_at'=>now()]);
        $id=uniqid();$cat=ProductCategory::create(['company_id'=>$c->id,'name'=>'C'.$id,'slug'=>'c'.$id,'is_active'=>true]);$unit=Unit::create(['company_id'=>$c->id,'name'=>'Unidad','abbreviation'=>'U','slug'=>'u'.$id,'is_active'=>true]);$product=Product::create(['company_id'=>$c->id,'category_id'=>$cat->id,'unit_id'=>$unit->id,'name'=>'Producto','internal_code'=>'P'.$id,'cost'=>500,'sale_price'=>1000,'tax_rate'=>0,'track_inventory'=>false,'is_active'=>true]);
        return[$c,$b,$u,$session,$credit,$product,$cash];
    }
    private function customer(Company $c,float $limit,int $days): Customer{return Customer::create(['company_id'=>$c->id,'name'=>'Cliente','customer_type'=>'individual','credit_limit'=>$limit,'credit_days'=>$days,'is_active'=>true]);}
    private function checkout($u,$c,$b,$s,$method,$p,$customer,?array $payments=null){return$this->actingAs($u)->withSession($this->ctx($c,$b))->postJson(route('pos.checkout'),['checkout_token'=>(string)Str::uuid(),'cash_session_id'=>$s->id,'customer_id'=>$customer?->id,'payments'=>$payments??[['payment_method_id'=>$method->id,'amount'=>1000]],'items'=>[['product_id'=>$p->id,'quantity'=>1]]]);}
    private function pay($u,$c,$b,$account,$method,$session,$amount){return$this->actingAs($u)->withSession($this->ctx($c,$b))->post(route('cuentas-por-cobrar.payments.store',$account),['amount'=>$amount,'payment_method_id'=>$method->id,'cash_session_id'=>$session->id]);}
    private function ctx($c,$b):array{return['active_company_id'=>$c->id,'active_branch_id'=>$b->id];}
}
