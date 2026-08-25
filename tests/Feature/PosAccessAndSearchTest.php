<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PosAccessAndSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_permission_and_active_context_can_open_pos(): void
    {
        [$company, $branch, $user] = $this->context(true);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee($company->trade_name)
            ->assertSee($branch->name)
            ->assertSee($user->name);
    }

    public function test_user_without_permission_receives_forbidden_for_pos_and_search(): void
    {
        [$company, $branch, $user] = $this->context(false);
        $session = $this->activeSession($company, $branch);

        $this->actingAs($user)->withSession($session)->get(route('pos.index'))->assertForbidden();
        $this->actingAs($user)->withSession($session)->getJson(route('pos.products.search', ['q' => 'a']))->assertForbidden();
    }

    public function test_sidebar_shows_pos_with_permission_and_hides_it_without_permission(): void
    {
        [$company, $branch, $allowedUser] = $this->context(true);
        $this->actingAs($allowedUser)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertSee('href="'.route('pos.index').'"', false);

        [$otherCompany, $otherBranch, $deniedUser] = $this->context(false);
        $this->actingAs($deniedUser)->withSession($this->activeSession($otherCompany, $otherBranch))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('href="'.route('pos.index').'"', false);
    }

    public function test_search_finds_by_name_and_returns_minimal_payload(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $product = $this->product($company, ['name' => 'Champú hidratante']);

        $response = $this->search($user, $company, $branch, 'hidratante')->assertOk();

        $response->assertJsonCount(1)->assertJsonPath('0.id', $product->id);
        $this->assertEqualsCanonicalizing([
            'id', 'name', 'internal_code', 'matched_barcode', 'sale_price', 'tax_rate',
            'controls_inventory', 'available_stock',
            'can_add_to_cart',
            'has_image', 'image_url',
        ], array_keys($response->json('0')));
    }

    public function test_search_finds_by_internal_code(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $product = $this->product($company, ['internal_code' => 'INT-900']);

        $this->search($user, $company, $branch, 'INT-900')
            ->assertJsonPath('0.id', $product->id);
    }

    public function test_search_finds_primary_and_additional_barcodes(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $primary = $this->product($company, ['barcode' => '744100000001']);
        $additional = $this->product($company);
        ProductBarcode::create([
            'product_id' => $additional->id,
            'barcode' => '744100000002',
            'barcode_type' => 'EAN13',
            'is_primary' => false,
            'is_active' => true,
        ]);

        $this->search($user, $company, $branch, '744100000001')
            ->assertJsonPath('0.id', $primary->id)
            ->assertJsonPath('0.matched_barcode', '744100000001');
        $this->search($user, $company, $branch, '744100000002')
            ->assertJsonPath('0.id', $additional->id)
            ->assertJsonPath('0.matched_barcode', '744100000002');
    }

    public function test_search_excludes_inactive_deleted_and_other_company_products(): void
    {
        [$company, $branch, $user] = $this->context(true);
        [$otherCompany] = $this->context(false);
        $visible = $this->product($company, ['name' => 'Coincidencia visible']);
        $this->product($company, ['name' => 'Coincidencia inactiva', 'is_active' => false]);
        $deleted = $this->product($company, ['name' => 'Coincidencia eliminada']);
        $deleted->delete();
        $this->product($otherCompany, ['name' => 'Coincidencia ajena']);

        $response = $this->search($user, $company, $branch, 'Coincidencia')->assertJsonCount(1);
        $response->assertJsonPath('0.id', $visible->id);
    }

    public function test_search_returns_stock_only_from_active_branch(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $otherBranch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Secundaria',
            'code' => 'S-'.$company->id,
            'is_active' => true,
        ]);
        $product = $this->product($company, ['name' => 'Producto stock']);
        $product->branches()->attach($branch->id, ['stock' => 4]);
        $product->branches()->attach($otherBranch->id, ['stock' => 99]);

        $this->search($user, $company, $branch, 'Producto stock')
            ->assertJsonPath('0.available_stock', 4);
    }

    public function test_pos_lists_only_active_payment_methods_from_active_company_including_paypal(): void
    {
        [$company, $branch, $user] = $this->context(true);
        [$otherCompany] = $this->context(false);
        $this->paymentMethod($company, 'PayPal', 'paypal', true);
        $this->paymentMethod($company, 'Método inactivo', 'inactive', false);
        $this->paymentMethod($otherCompany, 'Método ajeno', 'foreign', true);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('PayPal')
            ->assertDontSee('Método inactivo')
            ->assertDontSee('Método ajeno');
    }

    public function test_checkout_modal_has_responsive_permanent_summary_and_dynamic_direct_payment_flow(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $this->paymentMethod($company, 'PayPal', 'paypal-visual', true);
        $this->paymentMethod($company, 'Oculto inactivo', 'inactive-visual', false);
        $credit = $this->paymentMethod($company, 'Crédito futuro', 'credit-visual', true);
        $credit->update(['type' => PaymentMethod::TYPE_CREDIT]);
        $points = $this->paymentMethod($company, 'Puntos futuros', 'points-visual', true);
        $points->update(['type' => PaymentMethod::TYPE_LOYALTY_POINTS]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->get(route('pos.index'))
            ->assertOk()->assertSee('Cobrar venta')->assertSee('Resumen permanente')
            ->assertSee('Total aplicado')->assertSee('Saldo pendiente')->assertSee('Vuelto total')
            ->assertSee('Monto a aplicar')->assertSee('Usar saldo pendiente')
            ->assertSee('Seleccione una forma de pago para comenzar')->assertSee('Pago mixto')
            ->assertSee('Referencia *')->assertSee('Monto recibido')->assertSee('Vuelto')
            ->assertSee('Limpiar pagos')->assertSee('Confirmar cobro —')
            ->assertSee('PayPal')->assertSee('Crédito futuro')->assertSee('Puntos futuros')->assertSee('Próximamente')
            ->assertDontSee('Oculto inactivo')->assertSee('x-for="method in paymentMethods"', false)
            ->assertSee('lg:grid-cols-[0.9fr_1.1fr]', false)->assertSee('max-w-[1120px]', false)
            ->assertSee('selectPaymentMethod(method)', false)->assertSee('method.requires_reference', false)
            ->assertSee('method.allows_change', false)->assertSee('handleCheckoutEnter($event)', false)
            ->assertSee('this.checkout.payments = []', false)
            ->assertSee("this.checkout.draft.amount = String(this.pendingBalance)", false)
            ->assertSee("['credit', 'loyalty_points'].includes(method.type)", false)
            ->assertSee("Number(this.checkout.draft.receivedAmount) < amount", false)
            ->assertSee("amount > this.pendingBalance", false)
            ->assertSee("change_amount: method.allows_change ? received - amount : 0", false)
            ->assertSee("received_amount: received", false)
            ->assertDontSee("Number(this.checkout.draft.receivedAmount) === amount || amount === this.pendingBalance", false)
            ->assertSee("border-amber-500 bg-amber-500 text-black hover:bg-amber-600", false)
            ->assertSee('bg-amber-500 px-6 py-3 text-lg font-normal text-black hover:bg-amber-600', false)
            ->assertSee('bg-amber-500 px-5 py-3 font-normal text-black hover:bg-amber-600">Nueva venta', false)
            ->assertSee('bg-[#111111] px-5 py-3 font-normal text-white">Imprimir comprobante', false)
            ->assertSee('rounded-full bg-amber-500 text-3xl font-black text-[#111111]">✓', false)
            ->assertSee("disabled:bg-slate-100 disabled:text-slate-500", false)
            ->assertSee("text-xl font-extrabold sm:text-2xl", false)
            ->assertSee("text-2xl font-extrabold text-emerald-400 sm:text-3xl", false);
    }

    public function test_exact_barcode_match_has_priority(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $partial = $this->product($company, ['name' => 'A primero', 'barcode' => 'XX-ABC-123-YY']);
        $exact = $this->product($company, ['name' => 'Z último', 'barcode' => 'ABC-123']);

        $response = $this->search($user, $company, $branch, 'ABC-123');

        $response->assertJsonPath('0.id', $exact->id);
        $this->assertSame($partial->id, $response->json('1.id'));
    }

    public function test_local_stock_and_inventory_control_determine_if_product_can_be_added(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $available = $this->product($company, ['name' => 'Disponibilidad disponible']);
        $available->branches()->attach($branch->id, ['stock' => 3]);
        $service = $this->product($company, [
            'name' => 'Disponibilidad servicio',
            'track_inventory' => false,
        ]);
        $empty = $this->product($company, ['name' => 'Disponibilidad agotado']);

        $response = $this->search($user, $company, $branch, 'Disponibilidad')->assertJsonCount(3);
        $products = collect($response->json())->keyBy('id');

        $this->assertTrue($products[$available->id]['can_add_to_cart']);
        $this->assertTrue($products[$service->id]['can_add_to_cart']);
        $this->assertFalse($products[$empty->id]['can_add_to_cart']);
        $this->assertSame($empty->id, $response->json('2.id'));
    }

    public function test_other_branch_stock_is_returned_only_with_permission_and_never_changes_local_availability(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $this->grantPermission($user, $company, 'inventario.ver_otras_sucursales');
        $liberia = Branch::create([
            'company_id' => $company->id,
            'name' => 'Liberia',
            'code' => 'LIB-'.$company->id,
            'is_active' => true,
        ]);
        $inactive = Branch::create([
            'company_id' => $company->id,
            'name' => 'Inactiva',
            'code' => 'INA-'.$company->id,
            'is_active' => false,
        ]);
        [$otherCompany, $otherCompanyBranch] = $this->context(false);
        $product = $this->product($company, ['name' => 'Producto externo']);
        $product->branches()->attach($branch->id, ['stock' => 0]);
        $product->branches()->attach($liberia->id, ['stock' => 5]);
        $product->branches()->attach($inactive->id, ['stock' => 8]);
        DB::table('branch_product')->insert([
            'branch_id' => $otherCompanyBranch->id,
            'product_id' => $product->id,
            'stock' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->search($user, $company, $branch, 'Producto externo');

        $response->assertJsonPath('0.can_add_to_cart', false)
            ->assertJsonCount(1, '0.other_branch_stock')
            ->assertJsonPath('0.other_branch_stock.0.branch_id', $liberia->id)
            ->assertJsonPath('0.other_branch_stock.0.branch_name', 'Liberia')
            ->assertJsonPath('0.other_branch_stock.0.available_stock', 5);
        $this->assertNotSame($branch->id, $response->json('0.other_branch_stock.0.branch_id'));
        $this->assertNotSame($otherCompany->id, $company->id);
    }

    public function test_other_branch_stock_is_not_returned_or_exposed_without_permission(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $otherBranch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Nicoya',
            'code' => 'NIC-'.$company->id,
            'is_active' => true,
        ]);
        $product = $this->product($company, ['name' => 'Producto privado']);
        $product->branches()->attach($otherBranch->id, ['stock' => 10]);

        $response = $this->search($user, $company, $branch, 'Producto privado');

        $this->assertArrayNotHasKey('other_branch_stock', $response->json('0'));
        $response->assertJsonPath('0.can_add_to_cart', false);
    }

    public function test_permission_seeder_assigns_other_branch_inventory_permission_only_to_administrator(): void
    {
        $company = Company::create(['trade_name' => 'Empresa permisos', 'is_active' => true]);
        $administrator = Role::create([
            'company_id' => $company->id,
            'name' => 'Administrador',
            'is_active' => true,
        ]);
        $otherRole = Role::create([
            'company_id' => $company->id,
            'name' => 'Supervisor',
            'is_active' => true,
        ]);

        $this->seed(PermissionSeeder::class);

        $this->assertTrue($administrator->permissions()->where('name', 'inventario.ver_otras_sucursales')->exists());
        $this->assertFalse($otherRole->permissions()->where('name', 'inventario.ver_otras_sucursales')->exists());
    }

    public function test_product_with_real_public_image_returns_safe_url(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/pos-test.jpg', 'image-content');
        [$company, $branch, $user] = $this->context(true);
        $product = $this->product($company, [
            'name' => 'Producto con imagen',
            'image' => 'products/pos-test.jpg',
        ]);

        $response = $this->search($user, $company, $branch, 'Producto con imagen');

        $response->assertJsonPath('0.id', $product->id)
            ->assertJsonPath('0.has_image', true)
            ->assertJsonPath('0.image_url', asset('storage/products/pos-test.jpg'));
        $this->assertStringNotContainsString(storage_path(), $response->json('0.image_url'));
        $this->assertStringNotContainsString('C:\\', $response->json('0.image_url'));
    }

    public function test_product_without_valid_image_returns_safe_empty_image_output(): void
    {
        Storage::fake('public');
        [$company, $branch, $user] = $this->context(true);
        $this->product($company, [
            'name' => 'Producto sin imagen',
            'image' => 'products/missing.jpg',
        ]);

        $this->search($user, $company, $branch, 'Producto sin imagen')
            ->assertJsonPath('0.has_image', false)
            ->assertJsonPath('0.image_url', null);
    }

    public function test_pos_view_contains_image_modal_and_all_close_controls(): void
    {
        [$company, $branch, $user] = $this->context(true);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('role="dialog"', false)
            ->assertSee('@click.self="closeImage"', false)
            ->assertSee('@keydown.escape.window="closeImage"', false)
            ->assertSee('aria-label="Cerrar imagen"', false);
    }

    public function test_user_without_pos_permission_cannot_obtain_product_image_url(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/private.jpg', 'image-content');
        [$company, $branch, $user] = $this->context(false);
        $this->product($company, ['name' => 'Imagen privada', 'image' => 'products/private.jpg']);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('pos.products.search', ['q' => 'Imagen privada']))
            ->assertForbidden()
            ->assertDontSee('/storage/products/private.jpg');
    }

    public function test_pos_defaults_to_final_consumer_and_contains_customer_selector_without_losing_existing_features(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $this->paymentMethod($company, 'PayPal', 'paypal', true);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Consumidor Final')
            ->assertSee('Buscar cliente')
            ->assertSee('Quitar cliente')
            ->assertSee('name="customer_id"', false)
            ->assertSee('customerId: null', false)
            ->assertSee('Carrito temporal')
            ->assertSee('PayPal')
            ->assertSee('role="dialog"', false);
    }

    public function test_customer_search_finds_name_identification_phone_mobile_and_email(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $customer = $this->customer($company, [
            'name' => 'María Selector',
            'identification' => '1-1111-1111',
            'phone' => '2222-3333',
            'mobile' => '8888-9999',
            'email' => 'maria.selector@example.test',
        ]);

        foreach (['María Selector', '1-1111-1111', '2222-3333', '8888-9999', 'maria.selector@example.test'] as $term) {
            $this->searchCustomers($user, $company, $branch, $term)
                ->assertOk()
                ->assertJsonPath('0.id', $customer->id);
        }
    }

    public function test_exact_customer_identification_has_priority(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $partial = $this->customer($company, ['name' => 'A primero', 'identification' => 'XX-123-YY']);
        $exact = $this->customer($company, ['name' => 'Z último', 'identification' => '123']);

        $response = $this->searchCustomers($user, $company, $branch, '123');

        $response->assertJsonPath('0.id', $exact->id);
        $this->assertSame($partial->id, $response->json('1.id'));
    }

    public function test_customer_search_excludes_other_company_inactive_and_deleted_customers(): void
    {
        [$company, $branch, $user] = $this->context(true);
        [$otherCompany] = $this->context(false);
        $visible = $this->customer($company, ['name' => 'Cliente coincidencia visible']);
        $this->customer($company, ['name' => 'Cliente coincidencia inactivo', 'is_active' => false]);
        $deleted = $this->customer($company, ['name' => 'Cliente coincidencia eliminado']);
        $deleted->delete();
        $this->customer($otherCompany, ['name' => 'Cliente coincidencia ajeno']);

        $response = $this->searchCustomers($user, $company, $branch, 'Cliente coincidencia');

        $response->assertJsonCount(1)->assertJsonPath('0.id', $visible->id);
    }

    public function test_customer_search_returns_only_the_authorized_minimal_fields(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $this->customer($company, ['name' => 'Cliente JSON']);

        $response = $this->searchCustomers($user, $company, $branch, 'Cliente JSON');

        $this->assertEqualsCanonicalizing([
            'id', 'name', 'identification', 'phone', 'mobile', 'email',
            'customer_type', 'credit_limit', 'credit_days',
        ], array_keys($response->json('0')));
        $this->assertArrayNotHasKey('notes', $response->json('0'));
        $this->assertArrayNotHasKey('address', $response->json('0'));
        $this->assertArrayNotHasKey('current_balance', $response->json('0'));
    }

    public function test_customer_search_route_requires_pos_permission(): void
    {
        [$company, $branch, $user] = $this->context(false);
        $this->customer($company, ['name' => 'Cliente privado']);

        $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('pos.customers.search', ['q' => 'Cliente privado']))
            ->assertForbidden()
            ->assertDontSee('Cliente privado');
    }

    public function test_user_with_both_permissions_can_create_quick_customer_with_safe_defaults(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $this->grantPermission($user, $company, 'clientes.crear');

        $response = $this->quickStoreCustomer($user, $company, $branch, [
            'name' => 'Cliente rápido',
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => 'RAP-001',
            'phone' => '2222-0000',
            'mobile' => '8888-0000',
            'email' => 'RAPIDO@EXAMPLE.TEST',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('customer.name', 'Cliente rápido')
            ->assertJsonPath('customer.email', 'rapido@example.test');
        $customer = Customer::where('identification', 'RAP-001')->firstOrFail();
        $this->assertSame($company->id, $customer->company_id);
        $this->assertTrue($customer->is_active);
        $this->assertSame(0, $customer->points);
        $this->assertSame('0.00', $customer->credit_limit);
        $this->assertSame(0, $customer->credit_days);
    }

    public function test_quick_customer_rejects_manipulated_administrative_fields(): void
    {
        [$company, $branch, $user] = $this->context(true);
        [$otherCompany] = $this->context(false);
        $this->grantPermission($user, $company, 'clientes.crear');

        $response = $this->quickStoreCustomer($user, $company, $branch, [
            'name' => 'Cliente manipulado',
            'company_id' => $otherCompany->id,
            'is_active' => false,
            'points' => 999,
            'credit_limit' => 500000,
            'credit_days' => 90,
        ]);
        $this->assertSame(422, $response->status(), $response->getContent());
        $response->assertJsonValidationErrors(['company_id', 'is_active', 'points', 'credit_limit', 'credit_days']);

        $this->assertDatabaseMissing('customers', ['name' => 'Cliente manipulado']);
    }

    public function test_quick_customer_identification_is_unique_per_company_but_reusable_in_another_company(): void
    {
        [$company, $branch, $user] = $this->context(true);
        [$otherCompany, $otherBranch, $otherUser] = $this->context(true);
        $this->grantPermission($user, $company, 'clientes.crear');
        $this->grantPermission($otherUser, $otherCompany, 'clientes.crear');
        $this->customer($company, ['identification' => 'SHARED-001']);

        $duplicateResponse = $this->quickStoreCustomer($user, $company, $branch, [
            'name' => 'Duplicado local',
            'identification' => 'SHARED-001',
        ]);
        $this->assertSame(422, $duplicateResponse->status(), $duplicateResponse->getContent());
        $duplicateResponse->assertJsonValidationErrors('identification');

        $this->quickStoreCustomer($otherUser, $otherCompany, $otherBranch, [
            'name' => 'Permitido externo',
            'identification' => 'SHARED-001',
        ])->assertCreated();

        $this->assertSame(2, Customer::where('identification', 'SHARED-001')->count());
    }

    public function test_quick_customer_requires_both_pos_and_customer_create_permissions(): void
    {
        [$company, $branch, $posOnlyUser] = $this->context(true);
        $this->quickStoreCustomer($posOnlyUser, $company, $branch, ['name' => 'Sin crear'])
            ->assertForbidden();

        [$otherCompany, $otherBranch, $createOnlyUser] = $this->context(false);
        $this->grantPermission($createOnlyUser, $otherCompany, 'clientes.crear');
        $this->quickStoreCustomer($createOnlyUser, $otherCompany, $otherBranch, ['name' => 'Sin POS'])
            ->assertForbidden();
    }

    public function test_invalid_quick_customer_returns_json_validation_errors(): void
    {
        [$company, $branch, $user] = $this->context(true);
        $this->grantPermission($user, $company, 'clientes.crear');

        $response = $this->quickStoreCustomer($user, $company, $branch, [
            'name' => '',
            'customer_type' => 'invalid',
            'email' => 'correo-invalido',
        ]);
        $this->assertSame(422, $response->status(), $response->getContent());
        $response->assertJsonValidationErrors(['name', 'customer_type', 'email']);
    }

    public function test_created_quick_customer_is_available_in_pos_search_without_modifying_other_companies(): void
    {
        [$company, $branch, $user] = $this->context(true);
        [$otherCompany] = $this->context(false);
        $this->grantPermission($user, $company, 'clientes.crear');
        $otherCustomer = $this->customer($otherCompany, ['name' => 'Cliente ajeno intacto']);

        $created = $this->quickStoreCustomer($user, $company, $branch, [
            'name' => 'Cliente buscable POS',
            'identification' => 'SEARCH-001',
        ])->json('customer');

        $this->searchCustomers($user, $company, $branch, 'SEARCH-001')
            ->assertJsonPath('0.id', $created['id']);
        $this->assertSame('Cliente ajeno intacto', $otherCustomer->fresh()->name);
        $this->assertSame($otherCompany->id, $otherCustomer->company_id);
    }

    public function test_quick_customer_button_and_modal_are_visible_only_with_customer_create_permission(): void
    {
        [$company, $branch, $allowedUser] = $this->context(true);
        $this->grantPermission($allowedUser, $company, 'clientes.crear');

        $this->actingAs($allowedUser)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('+ Nuevo cliente')
            ->assertSee('aria-label="Crear cliente rápido"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('selectCustomer(payload.customer)', false);

        [$otherCompany, $otherBranch, $deniedUser] = $this->context(true);
        $this->actingAs($deniedUser)->withSession($this->activeSession($otherCompany, $otherBranch))
            ->get(route('pos.index'))
            ->assertOk()
            ->assertDontSee('+ Nuevo cliente')
            ->assertDontSee('aria-label="Crear cliente rápido"', false);
    }

    private function context(bool $withPermission): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'is_active' => true]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal '.$company->id,
            'code' => 'P-'.$company->id,
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Rol '.uniqid(),
            'is_active' => true,
        ]);
        if ($withPermission) {
            $permission = Permission::firstOrCreate(
                ['name' => 'pos.acceder'],
                ['label' => 'Acceder al POS', 'module' => 'POS', 'is_active' => true],
            );
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function search(User $user, Company $company, Branch $branch, string $query)
    {
        return $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('pos.products.search', ['q' => $query]));
    }

    private function searchCustomers(User $user, Company $company, Branch $branch, string $query)
    {
        return $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('pos.customers.search', ['q' => $query]));
    }

    private function quickStoreCustomer(User $user, Company $company, Branch $branch, array $payload)
    {
        return $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.customers.quick-store'), $payload);
    }

    private function product(Company $company, array $attributes = []): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create([
            'company_id' => $company->id,
            'name' => 'Categoría '.$suffix,
            'slug' => 'category-'.$suffix,
            'is_active' => true,
        ]);
        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Unidad '.$suffix,
            'abbreviation' => 'U',
            'slug' => 'unit-'.$suffix,
            'is_active' => true,
        ]);

        return Product::create(array_merge([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix,
            'internal_code' => 'CODE-'.$suffix,
            'sale_price' => 1000,
            'tax_rate' => 13,
            'track_inventory' => true,
            'is_active' => true,
        ], $attributes));
    }

    private function paymentMethod(Company $company, string $name, string $code, bool $active): PaymentMethod
    {
        return PaymentMethod::create([
            'company_id' => $company->id,
            'name' => $name,
            'code' => $code,
            'type' => PaymentMethod::TYPE_OTHER,
            'is_system' => false,
            'is_active' => $active,
            'affects_cash' => false,
            'requires_reference' => false,
            'allows_change' => false,
            'sort_order' => 10,
        ]);
    }

    private function grantPermission(User $user, Company $company, string $name): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => $name],
            ['label' => $name, 'module' => 'Inventario', 'is_active' => true],
        );
        $user->roleInCompany($company)->permissions()->syncWithoutDetaching($permission);
    }

    private function customer(Company $company, array $attributes = []): Customer
    {
        $suffix = uniqid();

        return Customer::create(array_merge([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification_type' => 'physical',
            'identification' => 'ID-'.$suffix,
            'name' => 'Cliente '.$suffix,
            'phone' => null,
            'mobile' => null,
            'email' => null,
            'credit_limit' => 0,
            'credit_days' => 0,
            'is_active' => true,
        ], $attributes));
    }
}
