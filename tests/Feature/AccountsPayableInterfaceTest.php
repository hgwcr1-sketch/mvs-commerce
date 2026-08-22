<?php

namespace Tests\Feature;

use App\Models\AccountPayable;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountsPayableInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_shows_required_account_data(): void
    {
        [$c,$b,$u,,$account]=$this->context();
        $this->actingAs($u)->withSession($this->ctx($c,$b))->get(route('cuentas-por-pagar.index'))->assertOk()->assertSee('Cuentas por pagar')->assertSee($account->supplier->name)->assertSee($account->purchase->number)->assertSee('₡1.000')->assertSee('Pendiente');
    }

    public function test_detail_shows_summary_and_payment_history(): void
    {
        [$c,$b,$u,,$account]=$this->context();
        $this->actingAs($u)->withSession($this->ctx($c,$b))->get(route('cuentas-por-pagar.show',$account))->assertOk()->assertSee('Monto original')->assertSee('Total abonado')->assertSee('Saldo')->assertSee('Historial de abonos')->assertSee('Registrar abono');
    }

    public function test_filters_status_supplier_dates_due_date_and_search(): void
    {
        [$c,$b,$u,,$account]=$this->context(); $account->update(['status'=>'partial','paid_amount'=>100,'balance_due'=>900]);
        $query=['status'=>'partial','supplier_id'=>$account->supplier_id,'search'=>$account->purchase->number,'issue_from'=>today()->subDay()->toDateString(),'issue_to'=>today()->addDay()->toDateString(),'due_from'=>today()->addDays(20)->toDateString(),'due_to'=>today()->addDays(40)->toDateString()];
        $this->actingAs($u)->withSession($this->ctx($c,$b))->get(route('cuentas-por-pagar.index',$query))->assertOk()->assertSee($account->purchase->number)->assertSee('Parcial');
        $this->actingAs($u)->withSession($this->ctx($c,$b))->get(route('cuentas-por-pagar.index',['search'=>'inexistente']))->assertOk()->assertDontSee($account->purchase->number);
    }

    public function test_payment_can_be_registered_from_interface(): void
    {
        [$c,$b,$u,$cash,$account,$cashSession]=$this->context();
        $this->actingAs($u)->withSession($this->ctx($c,$b))->post(route('cuentas-por-pagar.payments.store',$account),['amount'=>400,'payment_method_id'=>$cash->id,'cash_session_id'=>$cashSession->id,'reference'=>'EF-1','notes'=>'Pago parcial'])->assertRedirect();
        $this->assertSame('400.0000',$account->fresh()->paid_amount); $this->assertSame('600.0000',$account->fresh()->balance_due);
        $this->actingAs($u)->withSession($this->ctx($c,$b))->get(route('cuentas-por-pagar.show',$account))->assertSee('EF-1')->assertSee($cashSession->session_number)->assertSee($u->name)->assertSee('₡400');
    }

    public function test_paid_account_has_no_payment_form(): void
    {
        [$c,$b,$u,,$account]=$this->context(); $account->update(['status'=>'paid','paid_amount'=>1000,'balance_due'=>0]);
        $this->actingAs($u)->withSession($this->ctx($c,$b))->get(route('cuentas-por-pagar.show',$account))->assertOk()->assertSee('Pagada')->assertDontSee('Registrar abono');
    }

    public function test_permissions_protect_listing_and_payments(): void
    {
        [$c,$b,,$cash,$account,$cashSession]=$this->context(); $without=$this->user($c,$b,[]);
        $this->actingAs($without)->withSession($this->ctx($c,$b))->get(route('cuentas-por-pagar.index'))->assertForbidden();
        $viewer=$this->user($c,$b,['cuentas_pagar.ver']);
        $this->actingAs($viewer)->withSession($this->ctx($c,$b))->get(route('cuentas-por-pagar.show',$account))->assertOk()->assertDontSee('Registrar abono');
        $this->actingAs($viewer)->withSession($this->ctx($c,$b))->post(route('cuentas-por-pagar.payments.store',$account),['amount'=>1,'payment_method_id'=>$cash->id,'cash_session_id'=>$cashSession->id])->assertForbidden();
    }

    public function test_company_and_branch_isolation_returns_not_found(): void
    {
        [,,$otherUser]=$this->context('Otra'); [$c,$b,,,$account]=$this->context('Propietaria');
        $this->actingAs($otherUser)->withSession($this->ctx($c,$b))->get(route('cuentas-por-pagar.show',$account))->assertNotFound();
        [$otherCompany,$otherBranch,$authorizedOther]=$this->context('Aislada');
        $this->actingAs($authorizedOther)->withSession($this->ctx($otherCompany,$otherBranch))->get(route('cuentas-por-pagar.show',$account))->assertNotFound();
    }

    public function test_sidebar_shows_cxp_in_the_sales_group_and_in_the_required_order(): void
    {
        [$c,$b,,,$account]=$this->context();
        $user=$this->user($c,$b,['pos.acceder','ventas.ver','cotizaciones.ver','cuentas_cobrar.ver','cuentas_pagar.ver','devoluciones.ver']);

        $this->actingAs($user)->withSession($this->ctx($c,$b))->get(route('cuentas-por-pagar.show',$account))
            ->assertOk()
            ->assertSeeInOrder(['POS','Ventas','Cotizaciones','Cuentas por cobrar','Cuentas por pagar','Devoluciones']);
    }

    public function test_sidebar_requires_view_permission_for_cxp_navigation(): void
    {
        [$c,$b,$viewer]=$this->context();
        $this->actingAs($viewer)->withSession($this->ctx($c,$b))->get(route('cuentas-por-pagar.index'))
            ->assertSee(route('cuentas-por-pagar.index'));

        $without=$this->user($c,$b,['dashboard.ver']);
        $this->actingAs($without)->withSession($this->ctx($c,$b))->get(route('dashboard'))
            ->assertDontSee(route('cuentas-por-pagar.index'));
    }

    public function test_cxp_child_route_keeps_existing_alpine_sales_dropdown_open(): void
    {
        [$c,$b,$user,,$account]=$this->context();

        $this->actingAs($user)->withSession($this->ctx($c,$b))->get(route('cuentas-por-pagar.show',$account))
            ->assertOk()
            ->assertSee('x-data="{ open: true }"',false)
            ->assertSee('@click="open = !open"',false)
            ->assertSee('x-show="open"',false);
    }

    public function test_users_navigation_is_not_duplicated_outside_administration(): void
    {
        [$c,$b]=$this->context();
        $user=$this->user($c,$b,['usuarios.ver']);
        $response=$this->actingAs($user)->withSession($this->ctx($c,$b))->get(route('usuarios.index'))->assertOk();
        $html=$response->getContent();
        $sidebar=substr($html,strpos($html,'<aside'),strpos($html,'</aside>')-strpos($html,'<aside'));

        $this->assertSame(1,substr_count($sidebar,'Usuarios'));
        $this->assertStringContainsString('Administración',$sidebar);
    }

    private function context(string $name='Empresa'): array
    {
        $c=Company::create(['trade_name'=>$name.uniqid(),'currency'=>'CRC','timezone'=>'America/Costa_Rica','is_active'=>true]); $b=Branch::create(['company_id'=>$c->id,'name'=>'Principal','code'=>'P'.uniqid(),'is_active'=>true]); $u=$this->user($c,$b,['cuentas_pagar.ver','cuentas_pagar.pagar']);
        $cash=PaymentMethod::create(['company_id'=>$c->id,'code'=>'cash','name'=>'Efectivo','type'=>'cash','is_active'=>true,'affects_cash'=>true]); $register=CashRegister::create(['company_id'=>$c->id,'branch_id'=>$b->id,'code'=>'C'.uniqid(),'name'=>'Caja','is_active'=>true]); $session=CashSession::create(['company_id'=>$c->id,'branch_id'=>$b->id,'cash_register_id'=>$register->id,'session_number'=>'CAJA-'.uniqid(),'opened_by'=>$u->id,'status'=>'open','open_guard'=>'OPEN','opening_amount'=>1000,'opened_at'=>now()]);
        $supplier=Supplier::create(['company_id'=>$c->id,'supplier_type'=>'company','name'=>'Proveedor '.uniqid(),'is_active'=>true]); $purchase=Purchase::create(['company_id'=>$c->id,'branch_id'=>$b->id,'supplier_id'=>$supplier->id,'user_id'=>$u->id,'number'=>'CP-'.uniqid(),'purchase_date'=>today(),'payment_type'=>'credit','due_date'=>today()->addDays(30),'total'=>1000,'status'=>'posted']); $account=AccountPayable::create(['company_id'=>$c->id,'branch_id'=>$b->id,'supplier_id'=>$supplier->id,'purchase_id'=>$purchase->id,'original_amount'=>1000,'paid_amount'=>0,'balance_due'=>1000,'issue_date'=>today(),'due_date'=>today()->addDays(30),'status'=>'pending','created_by'=>$u->id]);
        return[$c,$b,$u,$cash,$account,$session];
    }
    private function user(Company $c,Branch $b,array $permissions):User{$u=User::factory()->create();$r=Role::create(['company_id'=>$c->id,'name'=>'R'.uniqid(),'is_active'=>true]);foreach($permissions as $name){$p=Permission::firstOrCreate(['name'=>$name],['label'=>$name,'module'=>'CxP','is_active'=>true]);$r->permissions()->attach($p);}$u->companies()->attach($c->id,['role_id'=>$r->id]);$u->branches()->attach($b->id);return$u;}
    private function ctx(Company $c,Branch $b):array{return['active_company_id'=>$c->id,'active_branch_id'=>$b->id];}
}
