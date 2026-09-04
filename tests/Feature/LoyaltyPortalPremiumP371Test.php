<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyPortalPost;
use App\Models\LoyaltyPortalSetting;
use App\Models\LoyaltyReward;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Loyalty\LoyaltyAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LoyaltyPortalPremiumP371Test extends TestCase
{
    use RefreshDatabase;

    public function test_ctas_use_an_optional_product_from_the_same_company_and_render_only_commercial_data(): void
    {
        Storage::fake('public');
        [$company, $branch, $user] = $this->context(['fidelidad.portal.contenido', 'fidelidad.portal.ver']);
        [$other] = $this->context(['fidelidad.portal.contenido']);
        $product = $this->product($company, 'Café premium', 'CAFE', '100.00', '80.00');
        $foreignProduct = $this->product($other, 'Producto ajeno', 'AJENO', '999.00');
        Storage::disk('public')->put("products/{$company->id}/cafe.jpg", 'image');
        $product->update(['image' => "products/{$company->id}/cafe.jpg"]);
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => '7.4321', 'created_at' => now(), 'updated_at' => now()]);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => '5.0000', 'point_value' => '1.0000', 'earn_on_offers' => true]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch));
        $this->post(route('loyalty.portal-management.posts.store'), [
            'type' => 'promotion', 'title' => 'CTA incompleta', 'cta_type' => 'buy', 'is_active' => 1,
        ])->assertSessionHasErrors('cta_url');
        $this->post(route('loyalty.portal-management.posts.store'), [
            'type' => 'promotion', 'title' => 'CTA insegura', 'cta_type' => 'external', 'cta_url' => 'javascript:alert(1)', 'is_active' => 1,
        ])->assertSessionHasErrors('cta_url');

        foreach (LoyaltyPortalPost::CTA_LABELS as $type => $label) {
            $url = $type === 'whatsapp' ? 'https://wa.me/50688887777' : "https://comercio.example/{$type}";
            $this->post(route('loyalty.portal-management.posts.store'), [
                'type' => 'promotion',
                'product_id' => $product->id,
                'title' => "CTA {$label}",
                'cta_type' => $type,
                'cta_url' => $url,
                'is_active' => 1,
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseHas('loyalty_portal_posts', ['company_id' => $company->id, 'cta_type' => $type, 'cta_url' => $url]);
        }

        $this->post(route('loyalty.portal-management.posts.store'), [
            'type' => 'offer', 'product_id' => $foreignProduct->id, 'title' => 'Cruce', 'is_active' => 1,
        ])->assertSessionHasErrors('product_id');

        $customer = $this->customer($company, 'Cliente comercial');
        $response = $this->get(route('loyalty.portal-management.preview', $customer))->assertOk();
        $response->assertSee('Café premium')
            ->assertSee('₡100,00')
            ->assertSee('₡80,00')
            ->assertSee('5% en puntos')
            ->assertSee('Disponible')
            ->assertSee('https://comercio.example/buy', false)
            ->assertDontSee('7,4321')
            ->assertDontSee('Producto ajeno');
    }

    public function test_social_urls_are_validated_and_branding_is_stored_and_rendered_per_company(): void
    {
        Storage::fake('public');
        [$company, $branch, $user] = $this->context(['fidelidad.portal.configurar', 'fidelidad.portal.contenido', 'fidelidad.portal.ver']);
        [$other] = $this->context(['fidelidad.portal.configurar']);
        LoyaltyPortalSetting::create(['company_id' => $other->id, 'portal_name' => 'Portal ajeno', 'instagram_url' => 'https://instagram.com/ajeno']);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch));
        $this->put(route('loyalty.portal-management.settings.update'), [
            'instagram_url' => 'https://example.com/no-es-instagram',
            'facebook_url' => 'texto-invalido',
            'is_active' => 1,
        ])->assertSessionHasErrors(['instagram_url', 'facebook_url']);

        $this->put(route('loyalty.portal-management.settings.update'), [
            'portal_name' => 'Club Café MVS',
            'welcome_message' => 'Bienvenido a beneficios exclusivos.',
            'portal_logo' => UploadedFile::fake()->image('portal-logo.png', 600, 300),
            'portal_icon' => UploadedFile::fake()->image('portal-icon.png', 128, 128),
            'brand_primary_color' => '#123456',
            'brand_accent_color' => '#FEDCBA',
            'instagram_url' => 'https://www.instagram.com/clubcafe/p/demo',
            'facebook_url' => 'https://facebook.com/clubcafe',
            'tiktok_url' => 'https://www.tiktok.com/@clubcafe',
            'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $setting = LoyaltyPortalSetting::query()->where('company_id', $company->id)->sole();
        Storage::disk('public')->assertExists($setting->portal_logo);
        Storage::disk('public')->assertExists($setting->portal_icon);
        $this->assertStringStartsWith("loyalty-portal/{$company->id}/branding/", $setting->portal_logo);
        $this->assertStringStartsWith("loyalty-portal/{$company->id}/branding/", $setting->portal_icon);
        $this->assertSame('Portal ajeno', LoyaltyPortalSetting::query()->where('company_id', $other->id)->value('portal_name'));

        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true]);
        LoyaltyPortalPost::create(['company_id' => $company->id, 'type' => 'notice', 'title' => 'Contenido destacado propio', 'is_active' => true, 'is_featured' => true]);
        $customer = $this->customer($company, 'Cliente branding');
        $response = $this->get(route('loyalty.portal-management.preview', $customer))->assertOk();
        $response->assertSee('Club Café MVS')
            ->assertSee('Bienvenido a beneficios exclusivos.')
            ->assertSee('Contenido destacado propio')
            ->assertSee('ring-2 ring-amber-400', false)
            ->assertSee('https://www.instagram.com/clubcafe/p/demo', false)
            ->assertSee('https://facebook.com/clubcafe', false)
            ->assertSee('https://www.tiktok.com/@clubcafe', false)
            ->assertSee('#123456', false)
            ->assertSee($setting->logoUrl($company), false)
            ->assertSee($setting->iconUrl(), false)
            ->assertSee('Hecho con MVS Commerce')
            ->assertDontSee('Portal ajeno');

        $this->get(route('loyalty.customer.login', $company))->assertOk()->assertSee('Club Café MVS')->assertSee($setting->logoUrl($company), false);
    }

    public function test_real_expiration_and_next_reward_progress_are_visible_without_cross_company_data(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03 10:00:00', 'America/Costa_Rica'));

        try {
            [$company, $branch, $user] = $this->context(['fidelidad.portal.ver']);
            [$other] = $this->context(['fidelidad.portal.ver']);
            LoyaltySetting::create([
                'company_id' => $company->id,
                'is_active' => true,
                'expiration_enabled' => true,
                'expiration_months' => 6,
                'point_value' => '1.0000',
            ]);
            $customer = $this->customer($company, 'Cliente progreso');
            $account = app(LoyaltyAccountService::class)->getOrCreateAccount($customer, $company);
            $lastPurchase = CarbonImmutable::parse('2026-03-15 09:00:00', 'America/Costa_Rica');
            app(LoyaltyAccountService::class)->addPoints($account, '1250.0000', LoyaltyMovement::TYPE_PURCHASE, [
                'branch' => $branch,
                'description' => 'Compra calificadora real',
                'effective_at' => $lastPurchase,
                'qualifying_purchase_at' => $lastPurchase,
            ]);
            LoyaltyReward::create(['company_id' => $company->id, 'name' => 'Premio inicial', 'type' => 'gift', 'availability_mode' => 'unlimited', 'points_cost' => '1000.0000', 'is_active' => true]);
            LoyaltyReward::create(['company_id' => $company->id, 'name' => 'Premio siguiente real', 'type' => 'gift', 'availability_mode' => 'unlimited', 'points_cost' => '1570.0000', 'is_active' => true]);
            LoyaltyReward::create(['company_id' => $other->id, 'name' => 'Premio secreto ajeno', 'type' => 'gift', 'availability_mode' => 'unlimited', 'points_cost' => '1300.0000', 'is_active' => true]);

            $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->get(route('loyalty.portal-management.preview', $customer))->assertOk();
            $response->assertSee('1.250 puntos vencen en 12 días')
                ->assertSee('15/09/2026')
                ->assertSee('Premio siguiente real')
                ->assertSee('Te faltan 320 puntos para tu próximo premio')
                ->assertSee('role="progressbar"', false)
                ->assertSee('width:79.6%', false)
                ->assertDontSee('Premio secreto ajeno');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_legacy_posts_without_commercial_fields_still_render_in_mobile_first_portal(): void
    {
        [$company, $branch, $user] = $this->context(['fidelidad.portal.ver']);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true]);
        LoyaltyPortalPost::create(['company_id' => $company->id, 'type' => 'notice', 'title' => 'Publicación heredada', 'is_active' => true]);
        $customer = $this->customer($company, 'Cliente heredado');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('loyalty.portal-management.preview', $customer))->assertOk()
            ->assertSee('Publicación heredada')
            ->assertSee('grid grid-cols-1', false)
            ->assertSee('width=device-width, initial-scale=1.0', false)
            ->assertDontSee('Comprar');
    }

    private function context(array $permissions): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'legal_name' => 'Empresa', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Fidelidad', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function customer(Company $company, string $name): Customer
    {
        return Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => $name, 'is_active' => true]);
    }

    private function product(Company $company, string $name, string $code, string $price, ?string $special = null): Product
    {
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Categoría '.$code, 'slug' => strtolower($code).'-'.uniqid(), 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$code, 'abbreviation' => 'ud', 'slug' => 'ud-'.strtolower($code).'-'.uniqid(), 'allows_decimals' => false, 'is_active' => true]);

        return Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => $name,
            'internal_code' => $code.'-'.uniqid(),
            'product_type' => 'product',
            'cost' => '10.0000',
            'sale_price' => $price,
            'special_price' => $special,
            'track_inventory' => true,
            'allow_negative_stock' => false,
            'is_active' => true,
        ]);
    }
}
