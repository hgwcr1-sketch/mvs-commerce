<?php

namespace Tests\Feature;

use App\Models\{Branch,CashRegister,CashSession,Company,CompanyCashSetting,PaymentMethod,Permission,Product,ProductCategory,Role,Sale,Unit,User};
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosCashSessionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_links_sale_and_all_payment_snapshots_to_own_session(): void
    {
        [$c,$b,$u]=$this->context(); $cash=$this->method($c,true,true); $card=$this->method($c,false,false,'card'); $session=$this->cashSession($c,$b,$this->register($c,$b),$u); $p=$this->product($c);
        $this->checkout($u,$c,$b,$p,[['payment_method_id'=>$card->id,'amount'=>400,'reference'=>'R'],['payment_method_id'=>$cash->id,'amount'=>600,'received_amount'=>1000]],$session)->assertOk();
        $sale=Sale::first(); $this->assertSame($session->id,$sale->cash_session_id); $this->assertEqualsCanonicalizing([$session->id,$session->id],$sale->payments->pluck('cash_session_id')->all());
        $this->assertDatabaseHas('sale_payments',['payment_method_id'=>$cash->id,'affects_cash_snapshot'=>true,'cash_effect_amount'=>600]);
        $this->assertDatabaseHas('sale_payments',['payment_method_id'=>$card->id,'affects_cash_snapshot'=>false,'cash_effect_amount'=>0]);
    }

    public function test_individual_rejects_another_users_session_but_shared_allows_it(): void
    {
        [$c,$b,$owner]=$this->context(); $cash=$this->method($c,true,true); $cashier=$this->user($c,$b); $session=$this->cashSession($c,$b,$this->register($c,$b),$owner); $p=$this->product($c);
        $this->checkout($cashier,$c,$b,$p,[['payment_method_id'=>$cash->id,'amount'=>1000,'received_amount'=>1000]],$session)->assertUnprocessable();
        $this->settings($c)->update(['session_mode'=>CompanyCashSetting::SESSION_MODE_SHARED]);
        $this->checkout($cashier,$c,$b,$p,[['payment_method_id'=>$cash->id,'amount'=>1000,'received_amount'=>1000]],$session)->assertOk();
    }

    public function test_shared_multiple_requires_explicit_valid_selection_and_pos_hides_foreign_sessions(): void
    {
        [$c,$b,$u]=$this->context(); $this->settings($c)->update(['session_mode'=>'shared']); $cash=$this->method($c,true,true); $this->cashSession($c,$b,$this->register($c,$b,'A'),$u); $other=$this->user($c,$b); $this->cashSession($c,$b,$this->register($c,$b,'B'),$other); $p=$this->product($c);
        $this->checkout($u,$c,$b,$p,[['payment_method_id'=>$cash->id,'amount'=>1000,'received_amount'=>1000]])->assertUnprocessable();
        [$foreign,$fb,$fu]=$this->context('Foreign'); $foreignSession=$this->cashSession($foreign,$fb,$this->register($foreign,$fb),$fu);
        $this->checkout($u,$c,$b,$p,[['payment_method_id'=>$cash->id,'amount'=>1000,'received_amount'=>1000]],$foreignSession)->assertUnprocessable();
        $this->actingAs($u)->withSession($this->ctx($c,$b))->get(route('pos.index'))->assertOk()->assertSee('Caja / Sesión para cobrar')->assertDontSee($foreignSession->session_number);
    }

    public function test_closed_inactive_and_other_branch_sessions_are_rejected(): void
    {
        [$c,$b,$u]=$this->context(); $cash=$this->method($c,true,true); $p=$this->product($c);
        $closed=$this->cashSession($c,$b,$this->register($c,$b,'C'),$u); $closed->update(['status'=>'closed','open_guard'=>null]);
        $inactiveRegister=$this->register($c,$b,'I'); $inactive=$this->cashSession($c,$b,$inactiveRegister,$u); $inactiveRegister->update(['is_active'=>false]);
        $otherBranch=Branch::create(['company_id'=>$c->id,'name'=>'Otra','code'=>'O','is_active'=>true]); $u->branches()->attach($otherBranch); $otherBranchSession=$this->cashSession($c,$otherBranch,$this->register($c,$otherBranch,'O'),$u);
        foreach([$closed,$inactive,$otherBranchSession] as $session) $this->checkout($u,$c,$b,$p,[['payment_method_id'=>$cash->id,'amount'=>1000,'received_amount'=>1000]],$session)->assertUnprocessable();
    }

    public function test_requirement_controls_sessionless_sales_and_configuration_requires_register_per_branch(): void
    {
        [$c,$b,$u]=$this->context(); $cash=$this->method($c,true,true); $p=$this->product($c); $settings=$this->settings($c);
        $this->checkout($u,$c,$b,$p,[['payment_method_id'=>$cash->id,'amount'=>1000,'received_amount'=>1000]])->assertOk(); $this->assertNull(Sale::first()->cash_session_id);
        $settings->update(['require_open_session'=>true]); $this->checkout($u,$c,$b,$p,[['payment_method_id'=>$cash->id,'amount'=>1000,'received_amount'=>1000]])->assertUnprocessable();
        $settings->update(['require_open_session'=>false]); $admin=$this->user($c,$b,['caja.administrar']); $payload=$this->settingsPayload(['require_open_session'=>'1']); $this->actingAs($admin)->withSession(['active_company_id'=>$c->id])->put(route('settings.cash.update'),$payload)->assertSessionHasErrors('require_open_session');
        $this->register($c,$b); $settings->update(['require_open_session'=>false]); $this->actingAs($admin)->withSession(['active_company_id'=>$c->id])->put(route('settings.cash.update'),$payload)->assertSessionHasNoErrors();
        $this->actingAs($admin)->withSession(['active_company_id'=>$c->id])->put(route('settings.cash.update'),$this->settingsPayload(['require_open_session'=>'0']))->assertSessionHasNoErrors();
    }

    public function test_custom_cash_effect_mixed_payment_change_and_historical_snapshot(): void
    {
        [$c,$b,$u]=$this->context(); $custom=$this->method($c,true,false,'other'); $nonCash=$this->method($c,false,false,'sinpe'); $session=$this->cashSession($c,$b,$this->register($c,$b),$u); $p=$this->product($c);
        $this->checkout($u,$c,$b,$p,[['payment_method_id'=>$nonCash->id,'amount'=>300,'reference'=>'S'],['payment_method_id'=>$custom->id,'amount'=>700,'received_amount'=>5000,'reference'=>'C']],$session)->assertOk();
        $customPayment=Sale::first()->payments()->where('payment_method_id',$custom->id)->first(); $this->assertSame('700.0000',$customPayment->cash_effect_amount); $this->assertTrue($customPayment->affects_cash_snapshot);
        $custom->update(['affects_cash'=>false]); $customPayment->refresh(); $this->assertTrue($customPayment->affects_cash_snapshot); $this->assertSame('700.0000',$customPayment->cash_effect_amount);
    }

    public function test_idempotency_same_session_does_not_duplicate_and_different_session_conflicts(): void
    {
        [$c,$b,$u]=$this->context(); $this->settings($c)->update(['session_mode'=>'shared']); $cash=$this->method($c,true,true); $s1=$this->cashSession($c,$b,$this->register($c,$b,'1'),$u); $s2=$this->cashSession($c,$b,$this->register($c,$b,'2'),$this->user($c,$b)); $p=$this->product($c); $token=(string)Str::uuid(); $payments=[['payment_method_id'=>$cash->id,'amount'=>1000,'received_amount'=>1000]];
        $this->checkout($u,$c,$b,$p,$payments,$s1,$token)->assertOk(); $this->checkout($u,$c,$b,$p,$payments,$s1,$token)->assertOk()->assertJsonPath('duplicate',true); $this->checkout($u,$c,$b,$p,$payments,$s2,$token)->assertConflict();
        $this->assertDatabaseCount('sales',1); $this->assertDatabaseCount('sale_payments',1);
    }

    public function test_receipt_session_and_sessionless_labels_and_permissions(): void
    {
        [$c,$b,$u]=$this->context(); $cash=$this->method($c,true,true); $p=$this->product($c); $session=$this->cashSession($c,$b,$this->register($c,$b,'Receipt'),$u);
        $saleId=$this->checkout($u,$c,$b,$p,[['payment_method_id'=>$cash->id,'amount'=>1000,'received_amount'=>1000]],$session)->json('sale_id');
        $this->actingAs($u)->withSession($this->ctx($c,$b))->get(route('pos.receipt',$saleId))->assertSee($session->session_number)->assertSee('Receipt');
        $session->update(['status'=>'closed','open_guard'=>null]); $without=$this->checkout($u,$c,$b,$p,[['payment_method_id'=>$cash->id,'amount'=>1000,'received_amount'=>1000]])->json('sale_id');
        $this->actingAs($u)->withSession($this->ctx($c,$b))->get(route('pos.receipt',$without))->assertSee('Sin sesión de caja');
        $unauthorized=$this->user($c,$b,[]); $this->actingAs($unauthorized)->withSession($this->ctx($c,$b))->postJson(route('pos.checkout'),[])->assertForbidden();
    }

    private function context(string $name='Empresa'): array{$c=Company::create(['trade_name'=>$name.uniqid(),'currency'=>'CRC','timezone'=>'America/Costa_Rica','is_active'=>true]);$b=Branch::create(['company_id'=>$c->id,'name'=>'Principal','code'=>'P'.$c->id,'is_active'=>true]);$u=$this->user($c,$b);$this->settings($c);return[$c,$b,$u];}
    private function settings(Company $c): CompanyCashSetting{return app(CompanyCashSettingsProvisioner::class)->provision($c);}
    private function user(Company $c,Branch $b,array $permissions=['pos.acceder','ventas.crear']): User{$u=User::factory()->create();$r=Role::create(['company_id'=>$c->id,'name'=>'R'.uniqid(),'is_active'=>true]);foreach($permissions as $n){$p=Permission::firstOrCreate(['name'=>$n],['label'=>$n,'module'=>'POS','is_active'=>true]);$r->permissions()->attach($p);}$u->companies()->attach($c->id,['role_id'=>$r->id]);$u->branches()->attach($b->id);return$u;}
    private function register(Company $c,Branch $b,string $name='Caja'): CashRegister{return CashRegister::create(['company_id'=>$c->id,'branch_id'=>$b->id,'code'=>$name.uniqid(),'name'=>$name,'is_active'=>true]);}
    private function cashSession(Company $c,Branch $b,CashRegister $r,User $u): CashSession{return CashSession::create(['company_id'=>$c->id,'branch_id'=>$b->id,'cash_register_id'=>$r->id,'session_number'=>'CAJA-'.uniqid(),'opened_by'=>$u->id,'status'=>'open','open_guard'=>'OPEN','opening_amount'=>0,'opened_at'=>now()]);}
    private function method(Company $c,bool $affects,bool $change,string $type='cash'): PaymentMethod{return PaymentMethod::create(['company_id'=>$c->id,'code'=>'M'.uniqid(),'name'=>ucfirst($type),'type'=>$type,'is_active'=>true,'affects_cash'=>$affects,'allows_change'=>$change,'requires_reference'=>!$change]);}
    private function product(Company $c): Product{$id=uniqid();$cat=ProductCategory::create(['company_id'=>$c->id,'name'=>'C'.$id,'slug'=>'c'.$id,'is_active'=>true]);$unit=Unit::create(['company_id'=>$c->id,'name'=>'Unidad','abbreviation'=>'U','slug'=>'u'.$id,'is_active'=>true]);return Product::create(['company_id'=>$c->id,'category_id'=>$cat->id,'unit_id'=>$unit->id,'name'=>'Producto','internal_code'=>'P'.$id,'cost'=>500,'sale_price'=>1000,'tax_rate'=>0,'track_inventory'=>false,'is_active'=>true]);}
    private function checkout(User $u,Company $c,Branch $b,Product $p,array $payments,?CashSession $s=null,?string $token=null){return $this->actingAs($u)->withSession($this->ctx($c,$b))->postJson(route('pos.checkout'),['checkout_token'=>$token??(string)Str::uuid(),'cash_session_id'=>$s?->id,'payments'=>$payments,'items'=>[['product_id'=>$p->id,'quantity'=>1]]]);}
    private function ctx(Company $c,Branch $b): array{return['active_company_id'=>$c->id,'active_branch_id'=>$b->id];}
    private function settingsPayload(array $extra=[]): array{return array_merge(['allow_multiple_registers'=>'0','session_mode'=>'individual','blind_closing'=>'1','accepts_usd'=>'0','usd_change_policy'=>'crc_only','difference_tolerance'=>0,'require_difference_authorization'=>'0','auto_print_closure'=>'0'], $extra);}
}
