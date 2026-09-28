<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchLabelSetting;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseVerification;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_assigns_only_an_authorized_user_from_same_company_and_branch(): void
    {
        [$company, $branch] = $this->context();
        $assigner = $this->user($company, $branch, ['compras.recepcion.asignar']);
        $reviewer = $this->user($company, $branch, ['compras.recepcion.verificar']);
        $unauthorized = $this->user($company, $branch, []);
        [$otherCompany, $otherBranch] = $this->context('Otra');
        $foreign = $this->user($otherCompany, $otherBranch, ['compras.recepcion.verificar']);
        $purchase = $this->purchase($company, $branch, $assigner, [[$this->product($company), 3]]);

        $this->asContext($assigner, $company, $branch)->get(route('purchase-verifications.assignable', $purchase))
            ->assertOk()->assertJsonFragment(['id' => $reviewer->id])->assertJsonMissing(['id' => $unauthorized->id])->assertJsonMissing(['id' => $foreign->id]);
        $this->asContext($assigner, $company, $branch)->post(route('purchase-verifications.store', $purchase), ['assigned_to' => $unauthorized->id])->assertSessionHasErrors('assigned_to');
        $this->asContext($assigner, $company, $branch)->post(route('purchase-verifications.store', $purchase), ['assigned_to' => $reviewer->id])->assertRedirect();
        $this->assertDatabaseHas('purchase_verifications', ['purchase_id' => $purchase->id, 'created_by' => $assigner->id, 'assigned_by' => $assigner->id, 'assigned_to' => $reviewer->id, 'status' => 'pending']);
    }

    public function test_persistent_badge_survives_logout_and_login_until_task_is_closed(): void
    {
        [$company, $branch] = $this->context();
        $assigner = $this->user($company, $branch, ['compras.recepcion.asignar']);
        $reviewer = $this->user($company, $branch, ['compras.recepcion.verificar']);
        $purchase = $this->purchase($company, $branch, $assigner, [[$this->product($company), 2]]);
        $this->asContext($assigner, $company, $branch)->post(route('purchase-verifications.store', $purchase), ['assigned_to' => $reviewer->id]);

        $this->asContext($reviewer, $company, $branch)->get(route('purchase-verifications.index'))->assertOk()->assertSee('Verificaciones pendientes: 1');
        $this->post(route('logout'))->assertRedirect();
        $this->asContext($reviewer, $company, $branch)->get(route('purchase-verifications.index'))->assertOk()->assertSee('Verificaciones pendientes: 1');
        $this->assertDatabaseHas('purchase_verifications', ['purchase_id' => $purchase->id, 'status' => 'pending']);
    }

    public function test_reviewer_records_conform_receipt_with_full_traceability(): void
    {
        [$company, $branch] = $this->context();
        $assigner = $this->user($company, $branch, ['compras.recepcion.asignar']);
        $reviewer = $this->user($company, $branch, ['compras.recepcion.verificar']);
        $product = $this->product($company);
        $verification = $this->assigned($company, $branch, $assigner, $reviewer, [[$product, 4]]);
        $line = $verification->items->sole();

        $this->asContext($reviewer, $company, $branch)->post(route('purchase-verifications.start', $verification))->assertRedirect();
        $this->asContext($reviewer, $company, $branch)->put(route('purchase-verifications.verify', $verification), ['lines' => [$line->id => ['received_quantity' => 4, 'confirmed' => 1, 'observation' => 'Cajas selladas']]])->assertRedirect();
        $verification->refresh();
        $this->assertSame('conform', $verification->status);
        $this->assertSame($reviewer->id, $verification->verified_by);
        $this->assertNotNull($verification->started_at);
        $this->assertNotNull($verification->verified_at);
        $this->assertDatabaseHas('purchase_verification_items', ['id' => $line->id, 'difference' => 0, 'is_checked' => true, 'observation' => 'Cajas selladas']);
    }

    public function test_shortage_and_surplus_are_recorded_and_never_change_inventory_stock(): void
    {
        [$company, $branch] = $this->context();
        $assigner = $this->user($company, $branch, ['compras.recepcion.asignar']);
        $reviewer = $this->user($company, $branch, ['compras.recepcion.verificar']);
        $first = $this->product($company); $second = $this->product($company);
        $first->branches()->attach($branch, ['stock' => 10]); $second->branches()->attach($branch, ['stock' => 20]);
        $verification = $this->assigned($company, $branch, $assigner, $reviewer, [[$first, 5], [$second, 6]]);
        $lines = $verification->items->keyBy('product_id');
        $before = $this->stocks($branch, [$first, $second]);

        $this->asContext($reviewer, $company, $branch)->put(route('purchase-verifications.verify', $verification), ['lines' => [
            $lines[$first->id]->id => ['received_quantity' => 3, 'confirmed' => 1, 'observation' => 'Faltaron dos'],
            $lines[$second->id]->id => ['received_quantity' => 8, 'confirmed' => 1, 'observation' => 'Sobran dos'],
        ]])->assertRedirect();

        $this->assertSame('differences', $verification->fresh()->status);
        $this->assertDatabaseHas('purchase_verification_items', ['product_id' => $first->id, 'difference' => -2]);
        $this->assertDatabaseHas('purchase_verification_items', ['product_id' => $second->id, 'difference' => 2]);
        $this->assertSame($before, $this->stocks($branch, [$first, $second]));
    }

    public function test_permissions_company_and_branch_isolation_protect_every_mutation(): void
    {
        [$company, $branch] = $this->context();
        $otherBranch = Branch::create(['company_id' => $company->id, 'name' => 'Otra sucursal', 'code' => 'O'.uniqid(), 'is_active' => true]);
        $assigner = $this->user($company, $branch, ['compras.recepcion.asignar']);
        $reviewer = $this->user($company, $branch, ['compras.recepcion.verificar']);
        $intruder = $this->user($company, $branch, ['compras.recepcion.verificar']);
        $reviewer->branches()->attach($otherBranch);
        $verification = $this->assigned($company, $branch, $assigner, $reviewer, [[$this->product($company), 1]]);
        $line = $verification->items->sole();

        $this->asContext($intruder, $company, $branch)->get(route('purchase-verifications.show', $verification))->assertForbidden();
        $this->asContext($reviewer, $company, $otherBranch)->put(route('purchase-verifications.verify', $verification), ['lines' => [$line->id => ['received_quantity' => 1, 'confirmed' => 1]]])->assertNotFound();
        $this->asContext($reviewer, $company, $branch)->put(route('purchase-verifications.verify', $verification), ['lines' => [999999 => ['received_quantity' => 1, 'confirmed' => 1]]])->assertSessionHasErrors('lines');
    }

    public function test_only_resolver_closes_differences_with_notes_and_badge_disappears(): void
    {
        [$company, $branch] = $this->context();
        $assigner = $this->user($company, $branch, ['compras.recepcion.asignar']);
        $reviewer = $this->user($company, $branch, ['compras.recepcion.verificar']);
        $resolver = $this->user($company, $branch, ['compras.recepcion.resolver']);
        $verification = $this->assigned($company, $branch, $assigner, $reviewer, [[$this->product($company), 2]]);
        $line = $verification->items->sole();
        $this->asContext($reviewer, $company, $branch)->put(route('purchase-verifications.verify', $verification), ['lines' => [$line->id => ['received_quantity' => 1, 'confirmed' => 1, 'observation' => 'Faltante']]]);
        $this->asContext($reviewer, $company, $branch)->post(route('purchase-verifications.close', $verification), ['resolution_notes' => 'Resuelto'])->assertForbidden();
        $this->asContext($resolver, $company, $branch)->post(route('purchase-verifications.close', $verification), [])->assertSessionHasErrors('resolution_notes');
        $this->asContext($resolver, $company, $branch)->post(route('purchase-verifications.close', $verification), ['resolution_notes' => 'Proveedor emitirá nota de crédito'])->assertRedirect();
        $verification->refresh();
        $this->assertSame('closed', $verification->status); $this->assertSame($resolver->id, $verification->resolved_by); $this->assertNotNull($verification->resolved_at);
        $this->asContext($reviewer, $company, $branch)->get(route('purchase-verifications.index'))->assertDontSee('Verificaciones pendientes: 1');
    }

    public function test_closed_conform_receipt_prepares_only_enabled_labels_using_received_quantities(): void
    {
        [$company, $branch] = $this->context();
        $assigner = $this->user($company, $branch, ['compras.recepcion.asignar']);
        $reviewer = $this->user($company, $branch, ['compras.recepcion.verificar', 'productos.etiquetas.imprimir']);
        $enabled = $this->product($company, ['name' => 'Etiquetable', 'barcode' => 'ABC-1', 'prints_label' => true]);
        $disabled = $this->product($company, ['name' => 'Sin etiqueta', 'prints_label' => false]);
        BranchLabelSetting::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'print_destinations' => ['cashier'], 'default_template' => 'name_price_barcode', 'default_size' => '50x30']);
        $verification = $this->assigned($company, $branch, $assigner, $reviewer, [[$enabled, 2], [$disabled, 4]]);
        $lines = $verification->items->keyBy('product_id');
        $this->asContext($reviewer, $company, $branch)->put(route('purchase-verifications.verify', $verification), ['lines' => [
            $lines[$enabled->id]->id => ['received_quantity' => 2, 'confirmed' => 1], $lines[$disabled->id]->id => ['received_quantity' => 4, 'confirmed' => 1],
        ]]);
        $this->asContext($reviewer, $company, $branch)->post(route('purchase-verifications.labels', $verification))
            ->assertOk()->assertSee('2 etiquetas')->assertSee('Etiquetable')->assertDontSee('Sin etiqueta')->assertSee('Destino: Cajero');
    }

    public function test_mobile_review_exposes_expected_received_observation_and_responsive_contract(): void
    {
        [$company, $branch] = $this->context();
        $assigner = $this->user($company, $branch, ['compras.recepcion.asignar']);
        $reviewer = $this->user($company, $branch, ['compras.recepcion.verificar']);
        $verification = $this->assigned($company, $branch, $assigner, $reviewer, [[$this->product($company, ['name' => 'Producto móvil']), 3]]);
        $this->asContext($reviewer, $company, $branch)->get(route('purchase-verifications.show', $verification))->assertOk()
            ->assertSee('Producto móvil')->assertSee('Cantidad recibida')->assertSee('Observación')->assertSee('data-responsive="360 768 1280"', false);
    }

    private function assigned(Company $company, Branch $branch, User $assigner, User $reviewer, array $lines): PurchaseVerification
    {
        $purchase = $this->purchase($company, $branch, $assigner, $lines);
        $this->asContext($assigner, $company, $branch)->post(route('purchase-verifications.store', $purchase), ['assigned_to' => $reviewer->id]);
        return PurchaseVerification::with('items')->where('purchase_id', $purchase->id)->firstOrFail();
    }

    private function purchase(Company $company, Branch $branch, User $user, array $lines): Purchase
    {
        $supplier = Supplier::create(['company_id' => $company->id, 'supplier_type' => 'company', 'name' => 'Proveedor', 'is_active' => true]);
        $purchase = Purchase::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'user_id' => $user->id, 'number' => 'C-'.uniqid(), 'purchase_date' => now(), 'payment_type' => 'cash', 'subtotal' => 100, 'discount' => 0, 'tax' => 0, 'total' => 100, 'status' => 'posted']);
        foreach ($lines as [$product, $quantity]) {
            PurchaseItem::create(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => 10, 'subtotal' => 10 * $quantity, 'discount' => 0, 'tax_rate' => 0, 'tax' => 0, 'total' => 10 * $quantity]);
        }
        return $purchase;
    }

    private function context(string $name = 'Empresa'): array { $company=Company::create(['trade_name'=>$name.' '.uniqid(),'currency'=>'CRC','timezone'=>'America/Costa_Rica','is_active'=>true]); $branch=Branch::create(['company_id'=>$company->id,'name'=>'Principal','code'=>'P'.uniqid(),'is_active'=>true]); return [$company,$branch]; }
    private function user(Company $company, Branch $branch, array $permissions): User { $user=User::factory()->create(['is_active'=>true]); $role=Role::create(['company_id'=>$company->id,'name'=>'Rol '.uniqid(),'is_active'=>true]); foreach($permissions as $name){$role->permissions()->attach(Permission::firstOrCreate(['name'=>$name],['label'=>$name,'module'=>'Compras','is_active'=>true]));} $user->companies()->attach($company,['role_id'=>$role->id]); $user->branches()->attach($branch); return $user; }
    private function product(Company $company, array $attributes=[]): Product { $id=uniqid(); $category=ProductCategory::create(['company_id'=>$company->id,'name'=>'Cat '.$id,'slug'=>'cat-'.$id,'is_active'=>true]); $unit=Unit::create(['company_id'=>$company->id,'name'=>'Unidad '.$id,'abbreviation'=>'U','slug'=>'u-'.$id,'is_active'=>true]); return Product::create(array_merge(['company_id'=>$company->id,'category_id'=>$category->id,'unit_id'=>$unit->id,'name'=>'Producto '.$id,'internal_code'=>'P-'.$id,'cost'=>10,'sale_price'=>20,'tax_rate'=>13,'is_active'=>true],$attributes)); }
    private function asContext(User $user, Company $company, Branch $branch) { return $this->actingAs($user)->withSession(['active_company_id'=>$company->id,'active_branch_id'=>$branch->id]); }
    private function stocks(Branch $branch, array $products): array { return collect($products)->mapWithKeys(fn($product)=>[$product->id=>(string)$branch->products()->where('products.id',$product->id)->first()->pivot->stock])->all(); }
}
