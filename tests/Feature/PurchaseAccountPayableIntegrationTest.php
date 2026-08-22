<?php

namespace Tests\Feature;

use App\Data\Purchases\PurchaseData;
use App\Data\Purchases\PurchaseLineData;
use App\Models\AccountPayable;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchases\CompanyPurchaseSettingsResolver;
use App\Services\Purchases\PurchaseAccountPayableService;
use App\Services\Purchases\PurchaseProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseAccountPayableIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_purchase_creates_account_payable(): void
    {
        [$company,$branch,$user,$supplier,$product]=$this->context();
        $purchase=$this->process($company,$branch,$user,$supplier,$product,'credit');

        $this->assertNotNull($purchase->accountPayable);
        $this->assertSame(1,AccountPayable::where('purchase_id',$purchase->id)->count());
    }

    public function test_cash_purchase_does_not_create_account_payable(): void
    {
        [$company,$branch,$user,$supplier,$product]=$this->context();
        $purchase=$this->process($company,$branch,$user,$supplier,$product,'cash');

        $this->assertNull($purchase->accountPayable);
        $this->assertDatabaseCount('accounts_payable',0);
    }

    public function test_account_payable_creation_is_idempotent_for_same_purchase(): void
    {
        [$company,$branch,$user,$supplier,$product]=$this->context();
        $purchase=$this->process($company,$branch,$user,$supplier,$product,'credit');
        $service=app(PurchaseAccountPayableService::class);

        $first=$service->createFor($purchase); $second=$service->createFor($purchase);
        $this->assertTrue($first->is($second));
        $this->assertSame(1,AccountPayable::where('purchase_id',$purchase->id)->count());
    }

    public function test_initial_data_and_balance_match_purchase(): void
    {
        [$company,$branch,$user,$supplier,$product]=$this->context();
        $purchase=$this->process($company,$branch,$user,$supplier,$product,'credit');
        $account=$purchase->accountPayable;

        $this->assertSame($company->id,$account->company_id); $this->assertSame($branch->id,$account->branch_id); $this->assertSame($supplier->id,$account->supplier_id);
        $this->assertSame($purchase->id,$account->purchase_id); $this->assertSame((float)$purchase->total,(float)$account->original_amount); $this->assertSame('0.0000',$account->paid_amount); $this->assertSame((float)$purchase->total,(float)$account->balance_due);
        $this->assertSame($purchase->purchase_date->toDateString(),$account->issue_date->toDateString()); $this->assertSame($purchase->due_date->toDateString(),$account->due_date->toDateString()); $this->assertSame(AccountPayable::STATUS_PENDING,$account->status);
    }

    public function test_accounts_are_isolated_by_company_and_branch(): void
    {
        [$company,$branch,$user,$supplier,$product]=$this->context('Uno'); $first=$this->process($company,$branch,$user,$supplier,$product,'credit')->accountPayable;
        [$other,$otherBranch,$otherUser,$otherSupplier,$otherProduct]=$this->context('Dos'); $this->process($other,$otherBranch,$otherUser,$otherSupplier,$otherProduct,'credit');

        $this->assertSame([$first->id],AccountPayable::forCompany($company->id)->forBranch($branch->id)->pluck('id')->all());
    }

    public function test_purchase_cancellation_cancels_its_account_payable(): void
    {
        [$company,$branch,$user,$supplier,$product]=$this->context();
        $purchase=$this->process($company,$branch,$user,$supplier,$product,'credit');

        $this->actingAs($user)->withSession(['active_company_id'=>$company->id,'active_branch_id'=>$branch->id])
            ->delete(route('compras.destroy',$purchase))->assertRedirect(route('compras.index'));

        $account=$purchase->accountPayable->fresh();
        $this->assertSame('cancelled',$purchase->fresh()->status); $this->assertSame(AccountPayable::STATUS_CANCELLED,$account->status); $this->assertSame('0.0000',$account->balance_due); $this->assertSame($user->id,$account->cancelled_by); $this->assertNotNull($account->cancelled_at);
    }

    private function process(Company $company,Branch $branch,User $user,Supplier $supplier,Product $product,string $paymentType): Purchase
    {
        return app(PurchaseProcessor::class)->process(new PurchaseData(company_id:$company->id,branch_id:$branch->id,supplier_id:$supplier->id,user_id:$user->id,purchase_date:today()->toDateString(),payment_type:$paymentType,due_date:$paymentType==='credit'?today()->addDays(30)->toDateString():null,notes:'Compra de prueba',lines:[new PurchaseLineData(product_id:$product->id,quantity:2,unit_cost:500,tax_rate:0)]));
    }

    private function context(string $name='Empresa'): array
    {
        $company=Company::create(['trade_name'=>$name.uniqid(),'currency'=>'CRC','timezone'=>'America/Costa_Rica','is_active'=>true]);
        $branch=Branch::create(['company_id'=>$company->id,'name'=>'Principal','code'=>'P'.uniqid(),'is_active'=>true]);
        $user=User::factory()->create(); $role=Role::create(['company_id'=>$company->id,'name'=>'Compras '.uniqid(),'is_active'=>true]);
        $permission=Permission::firstOrCreate(['name'=>'compras.anular'],['label'=>'Anular compras','module'=>'Compras','is_active'=>true]); $role->permissions()->attach($permission); $user->companies()->attach($company->id,['role_id'=>$role->id]); $user->branches()->attach($branch->id);
        app(CompanyPurchaseSettingsResolver::class)->forCompany($company);
        $supplier=Supplier::create(['company_id'=>$company->id,'supplier_type'=>'company','name'=>'Proveedor '.uniqid(),'credit_days'=>30,'is_active'=>true]);
        $id=Str::lower(Str::random(8)); $category=ProductCategory::create(['company_id'=>$company->id,'name'=>'Categoría '.$id,'slug'=>'cat-'.$id,'is_active'=>true]); $unit=Unit::create(['company_id'=>$company->id,'name'=>'Unidad','abbreviation'=>'U','slug'=>'u-'.$id,'is_active'=>true]);
        $product=Product::create(['company_id'=>$company->id,'category_id'=>$category->id,'unit_id'=>$unit->id,'name'=>'Producto '.$id,'internal_code'=>'P-'.$id,'cost'=>400,'sale_price'=>800,'tax_rate'=>0,'track_inventory'=>true,'is_active'=>true]);
        return[$company,$branch,$user,$supplier,$product];
    }
}
