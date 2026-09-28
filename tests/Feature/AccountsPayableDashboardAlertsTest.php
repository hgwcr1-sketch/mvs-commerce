<?php

namespace Tests\Feature;

use App\Models\AccountPayable;
use App\Models\AccountPayableAlert;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\AccountPayableDueNotification;
use App\Services\Purchases\AccountPayableAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountsPayableDashboardAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_pending_count_and_amount(): void
    {
        [$c,$b,$u]=$this->context(); $this->account($c,$b,$u,1000,today()->addDays(20)); $this->account($c,$b,$u,500,today()->addDays(30));
        $this->dashboard($u,$c,$b)->assertOk()->assertSee('CxP pendientes')->assertSee('₡1.500')->assertSee('2 cuentas');
    }

    public function test_dashboard_shows_overdue_count_and_amount(): void
    {
        [$c,$b,$u]=$this->context(); $this->account($c,$b,$u,700,today()->subDay());
        $this->dashboard($u,$c,$b)->assertSee('CxP vencidas')->assertSee('₡700')->assertSee('1 cuentas');
    }

    public function test_dashboard_shows_upcoming_using_company_days(): void
    {
        [$c,$b,$u]=$this->context(); $c->update(['payable_alert_days'=>5]); $this->account($c,$b,$u,300,today()->addDays(3)); $this->account($c,$b,$u,900,today()->addDays(8));
        $this->dashboard($u,$c,$b)->assertSee('CxP próximas a vencer')->assertSee('₡300')->assertSee('Próximos 5 días');
    }

    public function test_dashboard_is_isolated_by_branch(): void
    {
        [$c,$b,$u]=$this->context(); $other=Branch::create(['company_id'=>$c->id,'name'=>'Otra','code'=>'O'.uniqid(),'is_active'=>true]); $this->account($c,$b,$u,200,today()->addDays(2)); $this->account($c,$other,$u,9900,today()->subDay());
        $this->dashboard($u,$c,$b)->assertSee('₡200')->assertDontSee('₡9.900');
    }

    public function test_dashboard_section_requires_permission(): void
    {
        [$c,$b]=$this->context(); $viewer=$this->user($c,$b,['dashboard.ver']);
        $this->dashboard($viewer,$c,$b)->assertOk()->assertDontSee('CxP pendientes')->assertDontSee('CxP próximas a vencer');
    }

    public function test_alerts_detect_upcoming_and_overdue_and_send_mail(): void
    {
        Notification::fake(); [$c,$b,$u]=$this->context(); $c->update(['payable_alert_days'=>5]); $upcoming=$this->account($c,$b,$u,300,today()->addDays(3)); $overdue=$this->account($c,$b,$u,700,today()->subDay()); $this->account($c,$b,$u,900,today()->addDays(10));
        $this->assertSame(2,app(AccountPayableAlertService::class)->process());
        $this->assertDatabaseHas('account_payable_alerts',['account_payable_id'=>$upcoming->id,'type'=>'upcoming']); $this->assertDatabaseHas('account_payable_alerts',['account_payable_id'=>$overdue->id,'type'=>'overdue']);
        Notification::assertSentToTimes($u,AccountPayableDueNotification::class,2);
    }

    public function test_alerts_are_not_duplicated_and_respect_branch_access(): void
    {
        Notification::fake(); [$c,$b,$u]=$this->context(); $other=Branch::create(['company_id'=>$c->id,'name'=>'Otra','code'=>'O'.uniqid(),'is_active'=>true]); $this->account($c,$b,$u,300,today()->addDay()); $this->account($c,$other,$u,400,today()->subDay());
        $service=app(AccountPayableAlertService::class); $this->assertSame(2,$service->process()); $this->assertSame(0,$service->process()); $this->assertSame(2,AccountPayableAlert::count());
        Notification::assertSentToTimes($u,AccountPayableDueNotification::class,1);
    }

    private function dashboard(User $u,Company $c,Branch $b){return$this->actingAs($u)->withSession(['active_company_id'=>$c->id,'active_branch_id'=>$b->id])->get(route('dashboard'));}
    private function context():array{$c=Company::create(['trade_name'=>'Empresa '.uniqid(),'currency'=>'CRC','timezone'=>'America/Costa_Rica','payable_alert_days'=>5,'is_active'=>true]);$b=Branch::create(['company_id'=>$c->id,'name'=>'Principal','code'=>'P'.uniqid(),'is_active'=>true]);$u=$this->user($c,$b,['dashboard.ver','cuentas_pagar.ver']);return[$c,$b,$u];}
    private function user(Company $c,Branch $b,array $permissions):User{$u=User::factory()->create();$r=Role::create(['company_id'=>$c->id,'name'=>'R'.uniqid(),'is_active'=>true]);foreach($permissions as $name){$p=Permission::firstOrCreate(['name'=>$name],['label'=>$name,'module'=>'CxP','is_active'=>true]);$r->permissions()->attach($p);}$u->companies()->attach($c->id,['role_id'=>$r->id]);$u->branches()->attach($b->id);return$u;}
    private function account(Company $c,Branch $b,User $u,float $amount,$due):AccountPayable{$supplier=Supplier::create(['company_id'=>$c->id,'supplier_type'=>'company','name'=>'Proveedor '.uniqid(),'is_active'=>true]);$purchase=Purchase::create(['company_id'=>$c->id,'branch_id'=>$b->id,'supplier_id'=>$supplier->id,'user_id'=>$u->id,'number'=>'CP-'.uniqid(),'purchase_date'=>today(),'payment_type'=>'credit','due_date'=>$due,'total'=>$amount,'status'=>'posted']);return AccountPayable::create(['company_id'=>$c->id,'branch_id'=>$b->id,'supplier_id'=>$supplier->id,'purchase_id'=>$purchase->id,'original_amount'=>$amount,'paid_amount'=>0,'balance_due'=>$amount,'issue_date'=>today(),'due_date'=>$due,'status'=>'pending','created_by'=>$u->id]);}
}
