<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Branch;
use App\Models\BranchLabelSetting;
use App\Models\Color;
use App\Models\Company;
use App\Models\CompanyLicense;
use App\Models\Customer;
use App\Models\LoyaltyPortalPost;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Size;
use App\Models\Style;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoCompanyProvisioner
{
    private const DEMO_PRODUCT_IMAGES = [
        'CAM-CL-001',
        'CAM-CA-002',
        'CAM-FM-003',
        'PAN-SL-001',
        'PAN-CA-002',
    ];

    private const DEMO_LOYALTY_POSTS = [
        [
            'title' => 'Doble Puntaje',
            'file' => 'loyalty-double-points.png',
            'message' => 'Gana el doble de puntos en todas tus compras este mes.',
            'cta_type' => 'more',
        ],
        [
            'title' => 'Canje de Puntos',
            'file' => 'loyalty-redeem-points.png',
            'message' => 'Canjea tus puntos por productos exclusivos.',
            'cta_type' => 'more',
        ],
        [
            'title' => 'Promoción Especial',
            'file' => 'promotion-special.png',
            'message' => 'Ofertas imperdibles para nuestros miembros.',
            'cta_type' => 'more',
        ],
        [
            'title' => 'Bienvenida al Programa',
            'file' => 'loyalty-welcome.png',
            'message' => 'Únete al programa de fidelidad y empieza a ganar puntos.',
            'cta_type' => 'more',
        ],
    ];

    private ?Company $demoCompany = null;

    public function __construct(
        private readonly CompanyProvisioner $companyProvisioner,
    ) {}

    public function isDemoCompany(Company $company): bool
    {
        return $company->trade_name === config('demo.company_name');
    }

    public function findDemoCompany(): ?Company
    {
        return Company::query()
            ->where('trade_name', config('demo.company_name'))
            ->first();
    }

    public function demoCompany(): Company
    {
        if ($this->demoCompany === null) {
            $this->demoCompany = $this->findDemoCompany();
        }

        return $this->demoCompany;
    }

    public function demoCompanyId(): ?int
    {
        return $this->demoCompany()?->id;
    }

    public function create(): Company
    {
        $existing = $this->findDemoCompany();
        if ($existing) {
            return $existing;
        }

        return \Illuminate\Support\Facades\DB::transaction(function () {
            $company = $this->companyProvisioner->onboard(
                [
                    'name' => config('demo.owner_name'),
                    'email' => config('demo.owner_email'),
                    'password' => config('demo.owner_password'),
                ],
                [
                    'trade_name' => config('demo.company_name'),
                    'currency' => 'CRC',
                    'timezone' => 'America/Costa_Rica',
                    'is_active' => true,
                    'default_phone_country_code' => '+506',
                ],
                array_map(fn ($b) => [
                    'name' => $b['name'],
                    'code' => $b['code'],
                ], config('demo.branches')),
                array_keys(\App\Services\Modules\ModuleRegistry::MODULES),
            );

            $this->seedRolesAndUsers($company);
            $this->seedCatalogs($company);
            $this->seedProducts($company);
            $this->syncProductImages($company);
            $this->seedInventory($company);
            $this->seedCustomers($company);
            $this->seedSuppliers($company);
            $this->seedLoyalty($company);
            $this->seedLoyaltyPosts($company);
            $this->seedLabels($company);
            $this->seedCashRegisters($company);
            $this->ensurePermanentLicense($company);
            $this->syncDemoAssets($company);
            $this->syncLoyaltyAssets($company);

            return $company;
        });
    }

    public function reset(): Company
    {
        $company = $this->findDemoCompany();
        if (! $company) {
            return $this->create();
        }

        $this->validateOwnership($company);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($company) {
            $this->clearTransactionals($company);
            $this->clearCatalogs($company);
            $this->clearUsers($company);

            $company->update(['is_active' => true]);

            $owner = User::query()->where('email', config('demo.owner_email'))->first();
            if ($owner) {
                $owner->update(['is_active' => true, 'password' => Hash::make(config('demo.owner_password'))]);
            }

            $this->seedRolesAndUsers($company);
            $this->seedCatalogs($company);
            $this->seedProducts($company);
            $this->syncProductImages($company);
            $this->seedInventory($company);
            $this->seedCustomers($company);
            $this->seedSuppliers($company);
            $this->seedLoyalty($company);
            $this->seedLoyaltyPosts($company);
            $this->seedLabels($company);
            $this->seedCashRegisters($company);
            $this->ensurePermanentLicense($company);
            $this->syncDemoAssets($company);
            $this->syncLoyaltyAssets($company);

            return $company;
        });
    }

    private function validateOwnership(Company $company): void
    {
        abort_unless(
            $company->trade_name === config('demo.company_name') && $company->owner_user_id !== null,
            403,
            'Solo se puede resetear la empresa demo exacta.',
        );
    }

    private function ensurePermanentLicense(Company $company): void
    {
        $license = CompanyLicense::query()
            ->where('company_id', $company->id)
            ->first();

        if (! $license) {
            return;
        }

        $license->update([
            'status' => 'active',
            'expires_at' => null,
            'grace_until' => null,
            'notes' => 'Licencia Demo permanente. No vence.',
        ]);
    }

    private function clearTransactionals(Company $company): void
    {
        $company->cashSessions()->delete();
        $company->cashDenominations()->delete();
        $company->cashRegisters()->delete();
        $company->cashSetting()->delete();
        $company->branches()->each(function (Branch $branch) {
            $branch->products()->detach();
        });
        Product::query()->where('company_id', $company->id)->forceDelete();
        Customer::query()->where('company_id', $company->id)->forceDelete();
        Supplier::query()->where('company_id', $company->id)->forceDelete();
    }

    private function clearCatalogs(Company $company): void
    {
        BranchLabelSetting::query()->where('company_id', $company->id)->delete();
        LoyaltySetting::query()->where('company_id', $company->id)->delete();
        Style::query()->where('company_id', $company->id)->forceDelete();
        Size::query()->where('company_id', $company->id)->forceDelete();
        Color::query()->where('company_id', $company->id)->forceDelete();
        ProductCategory::query()->where('company_id', $company->id)->whereNotNull('parent_id')->forceDelete();
        ProductCategory::query()->where('company_id', $company->id)->whereNull('parent_id')->forceDelete();
        Brand::query()->where('company_id', $company->id)->forceDelete();
        Unit::query()->where('company_id', $company->id)->forceDelete();
    }

    private function clearUsers(Company $company): void
    {
        $ownerEmail = config('demo.owner_email');
        $demoEmails = collect(config('demo.users'))->pluck('email')->all();

        $company->users()->detach();

        User::query()
            ->whereIn('email', $demoEmails)
            ->where('email', '!=', $ownerEmail)
            ->delete();
    }

    private function seedRolesAndUsers(Company $company): void
    {
        $allPermissionIds = Permission::query()->where('is_active', true)->pluck('id')->all();

        foreach (config('demo.users') as $userData) {
            $roleName = $userData['role'];
            $permissionNames = $userData['permissions'] === 'all' ? null : $userData['permissions'];

            $role = \App\Models\Role::firstOrCreate(
                ['company_id' => $company->id, 'name' => $roleName],
                ['description' => "Rol {$roleName} de la empresa Demo.", 'is_active' => true],
            );

            if ($permissionNames === null) {
                $role->permissions()->syncWithoutDetaching($allPermissionIds);
            } else {
                $permIds = Permission::query()
                    ->where('is_active', true)
                    ->whereIn('name', $permissionNames)
                    ->pluck('id')
                    ->all();
                $role->permissions()->syncWithoutDetaching($permIds);
            }

            $user = User::firstOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => Hash::make($userData['password']),
                    'is_active' => true,
                ],
            );

            $company->users()->syncWithoutDetaching([
                $user->id => ['role_id' => $role->id],
            ]);

            $companyBranchIds = $company->branches()->pluck('branches.id')->all();
            $user->branches()->syncWithoutDetaching($companyBranchIds);
        }
    }

    private function slugify(string $text, int $companyId): string
    {
        return Str::slug(Str::limit($text, 80)) . '-' . $companyId;
    }

    private function seedCatalogs(Company $company): void
    {
        $cid = $company->id;

        $units = [
            ['name' => 'Unidad', 'abbreviation' => 'UN', 'decimals' => false],
            ['name' => 'Par', 'abbreviation' => 'PAR', 'decimals' => false],
            ['name' => 'Docena', 'abbreviation' => 'DOC', 'decimals' => false],
            ['name' => 'Kilogramo', 'abbreviation' => 'KG', 'decimals' => true],
            ['name' => 'Metro', 'abbreviation' => 'M', 'decimals' => true],
            ['name' => 'Litro', 'abbreviation' => 'L', 'decimals' => true],
        ];
        foreach ($units as $i => $u) {
            Unit::firstOrCreate(
                ['company_id' => $cid, 'name' => $u['name']],
                ['abbreviation' => $u['abbreviation'], 'slug' => $this->slugify($u['name'], $cid), 'allows_decimals' => $u['decimals'], 'sort_order' => ($i + 1) * 10, 'is_active' => true],
            );
        }

        $brands = [
            ['name' => 'Artisan Wear', 'description' => 'Ropa artesanal costarricense.'],
            ['name' => 'EcoTextil CR', 'description' => 'Textiles ecológicos y sostenibles.'],
            ['name' => 'Moda Tica', 'description' => 'Moda contemporánea costarricense.'],
            ['name' => 'Pure Nature', 'description' => 'Productos naturales y orgánicos.'],
        ];
        foreach ($brands as $b) {
            Brand::firstOrCreate(
                ['company_id' => $cid, 'name' => $b['name']],
                ['description' => $b['description'], 'is_active' => true],
            );
        }

        $catData = [
            ['name' => 'Ropa', 'children' => ['Camisas', 'Pantalones', 'Faldas', 'Vestidos']],
            ['name' => 'Calzado', 'children' => ['Deportivo', 'Formal', 'Casual']],
            ['name' => 'Accesorios', 'children' => ['Bolsos', 'Sombreros', 'Cinturones', 'Joyas']],
            ['name' => 'Hogar', 'children' => ['Textiles', 'Decoración', 'Cocina']],
        ];
        foreach ($catData as $catInfo) {
            $parent = ProductCategory::firstOrCreate(
                ['company_id' => $cid, 'name' => $catInfo['name']],
                ['slug' => $this->slugify($catInfo['name'], $cid), 'sort_order' => 0, 'is_active' => true],
            );
            foreach ($catInfo['children'] as $i => $childName) {
                ProductCategory::firstOrCreate(
                    ['company_id' => $cid, 'parent_id' => $parent->id, 'name' => $childName],
                    ['slug' => $this->slugify($childName, $cid), 'sort_order' => ($i + 1) * 10, 'is_active' => true],
                );
            }
        }

        $styles = ['Clásico', 'Deportivo', 'Casual', 'Formal', 'Bohemio'];
        foreach ($styles as $i => $s) {
            Style::firstOrCreate(
                ['company_id' => $cid, 'name' => $s],
                ['slug' => $this->slugify($s, $cid), 'is_active' => true],
            );
        }

        $sizes = [
            ['name' => 'XS', 'abbreviation' => 'XS', 'sort_order' => 1],
            ['name' => 'S', 'abbreviation' => 'S', 'sort_order' => 2],
            ['name' => 'M', 'abbreviation' => 'M', 'sort_order' => 3],
            ['name' => 'L', 'abbreviation' => 'L', 'sort_order' => 4],
            ['name' => 'XL', 'abbreviation' => 'XL', 'sort_order' => 5],
            ['name' => 'XXL', 'abbreviation' => 'XXL', 'sort_order' => 6],
        ];
        foreach ($sizes as $sz) {
            Size::firstOrCreate(
                ['company_id' => $cid, 'name' => $sz['name']],
                ['abbreviation' => $sz['abbreviation'], 'slug' => $this->slugify($sz['name'], $cid), 'sort_order' => $sz['sort_order'], 'is_active' => true],
            );
        }

        $colors = [
            ['name' => 'Negro', 'hex' => '#000000'],
            ['name' => 'Blanco', 'hex' => '#FFFFFF'],
            ['name' => 'Azul', 'hex' => '#0000FF'],
            ['name' => 'Rojo', 'hex' => '#FF0000'],
            ['name' => 'Verde', 'hex' => '#008000'],
            ['name' => 'Gris', 'hex' => '#808080'],
            ['name' => 'Beige', 'hex' => '#F5F5DC'],
            ['name' => 'Rosa', 'hex' => '#FFC0CB'],
        ];
        foreach ($colors as $c) {
            Color::firstOrCreate(
                ['company_id' => $cid, 'name' => $c['name']],
                ['hex_code' => $c['hex'], 'slug' => $this->slugify($c['name'], $cid), 'is_active' => true],
            );
        }
    }

    private function seedProducts(Company $company): void
    {
        $cid = $company->id;
        $unit = Unit::where('company_id', $cid)->where('name', 'Unidad')->first();

        $camisasCat = ProductCategory::where('company_id', $cid)->where('name', 'Camisas')->first();
        $pantalonesCat = ProductCategory::where('company_id', $cid)->where('name', 'Pantalones')->first();
        $calzadoDepCat = ProductCategory::where('company_id', $cid)->where('name', 'Deportivo')->first();
        $accesoriosCat = ProductCategory::where('company_id', $cid)->where('name', 'Bolsos')->first();
        $hogarCat = ProductCategory::where('company_id', $cid)->where('name', 'Textiles')->first();

        $brand1 = Brand::where('company_id', $cid)->where('name', 'Artisan Wear')->first();
        $brand2 = Brand::where('company_id', $cid)->where('name', 'EcoTextil CR')->first();
        $brand3 = Brand::where('company_id', $cid)->where('name', 'Moda Tica')->first();
        $brand4 = Brand::where('company_id', $cid)->where('name', 'Pure Nature')->first();

        $allStyles = Style::where('company_id', $cid)->get();
        $allSizes = Size::where('company_id', $cid)->get();
        $allColors = Color::where('company_id', $cid)->get();

        $styleByName = $allStyles->keyBy('name');
        $sizeByName = $allSizes->keyBy('name');
        $colorByName = $allColors->keyBy('name');

        $products = [
            ['name' => 'Camisa Clásica Algodón', 'cat' => $camisasCat, 'brand' => $brand1, 'cost' => 8500, 'sale' => 14900, 'wholesale' => 12500, 'code' => 'CAM-CL-001', 'style' => 'Clásico', 'size' => 'M', 'color' => 'Negro', 'image' => 'products/CAM-CL-001.png'],
            ['name' => 'Camisa Casual Linen', 'cat' => $camisasCat, 'brand' => $brand1, 'cost' => 12000, 'sale' => 21900, 'wholesale' => 18900, 'code' => 'CAM-CA-002', 'style' => 'Casual', 'size' => 'S', 'color' => 'Blanco', 'image' => 'products/CAM-CA-002.png'],
            ['name' => 'Camisa Formal Premium', 'cat' => $camisasCat, 'brand' => $brand3, 'cost' => 15000, 'sale' => 28900, 'wholesale' => 24900, 'code' => 'CAM-FM-003', 'style' => 'Formal', 'size' => 'L', 'color' => 'Azul', 'image' => 'products/CAM-FM-003.png'],
            ['name' => 'Pantalón Slim Fit', 'cat' => $pantalonesCat, 'brand' => $brand3, 'cost' => 11000, 'sale' => 19900, 'wholesale' => 16900, 'code' => 'PAN-SL-001', 'style' => 'Formal', 'size' => 'M', 'color' => 'Gris', 'image' => 'products/PAN-SL-001.png'],
            ['name' => 'Pantalón Clásico Cargo', 'cat' => $pantalonesCat, 'brand' => $brand1, 'cost' => 13000, 'sale' => 23900, 'wholesale' => 19900, 'code' => 'PAN-CA-002', 'style' => 'Clásico', 'size' => 'L', 'color' => 'Verde', 'image' => 'products/PAN-CA-002.png'],
            ['name' => 'Pantalón Jogger Eco', 'cat' => $pantalonesCat, 'brand' => $brand2, 'cost' => 9000, 'sale' => 16900, 'wholesale' => 14500, 'code' => 'PAN-JG-003', 'style' => 'Deportivo', 'size' => 'S', 'color' => 'Negro'],
            ['name' => 'Zapatilla Runner Pro', 'cat' => $calzadoDepCat, 'brand' => $brand3, 'cost' => 22000, 'sale' => 39900, 'wholesale' => 34900, 'code' => 'ZAP-RN-001', 'style' => 'Deportivo', 'size' => 'XL', 'color' => 'Azul'],
            ['name' => 'Zapatilla Urban Style', 'cat' => $calzadoDepCat, 'brand' => $brand3, 'cost' => 18000, 'sale' => 32900, 'wholesale' => 28900, 'code' => 'ZAP-UR-002', 'style' => 'Casual', 'size' => 'L', 'color' => 'Gris'],
            ['name' => 'Bolso Eco Canvas', 'cat' => $accesoriosCat, 'brand' => $brand2, 'cost' => 7000, 'sale' => 13900, 'wholesale' => 11900, 'code' => 'BOL-EC-001', 'style' => 'Casual', 'size' => 'M', 'color' => 'Beige'],
            ['name' => 'Bolso Artisan Leather', 'cat' => $accesoriosCat, 'brand' => $brand1, 'cost' => 19000, 'sale' => 35900, 'wholesale' => 30900, 'code' => 'BOL-AL-002', 'style' => 'Clásico', 'size' => 'L', 'color' => 'Negro'],
            ['name' => 'Manta Eco Tejida', 'cat' => $hogarCat, 'brand' => $brand2, 'cost' => 14000, 'sale' => 25900, 'wholesale' => 22900, 'code' => 'MAN-EC-001', 'style' => 'Bohemio', 'size' => 'XL', 'color' => 'Blanco'],
            ['name' => 'Cojín Artesanal CR', 'cat' => $hogarCat, 'brand' => $brand1, 'cost' => 5500, 'sale' => 9900, 'wholesale' => 8500, 'code' => 'COJ-AR-001', 'style' => 'Bohemio', 'size' => 'M', 'color' => 'Rojo'],
            ['name' => 'Camiseta Básica Unisex', 'cat' => $camisasCat, 'brand' => $brand4, 'cost' => 4500, 'sale' => 8900, 'wholesale' => 7500, 'code' => 'CAM-BA-004', 'style' => 'Casual', 'size' => 'S', 'color' => 'Blanco'],
            ['name' => 'Falda Midi Elegante', 'cat' => $pantalonesCat, 'brand' => $brand3, 'cost' => 10000, 'sale' => 18900, 'wholesale' => 15900, 'code' => 'FAL-ME-001', 'style' => 'Formal', 'size' => 'M', 'color' => 'Rosa'],
            ['name' => 'Short Deportivo Flex', 'cat' => $pantalonesCat, 'brand' => $brand4, 'cost' => 6000, 'sale' => 11900, 'wholesale' => 9900, 'code' => 'SHO-DF-001', 'style' => 'Deportivo', 'size' => 'S', 'color' => 'Negro'],
            ['name' => 'Chaqueta Impermeable', 'cat' => $camisasCat, 'brand' => $brand3, 'cost' => 25000, 'sale' => 45900, 'wholesale' => 39900, 'code' => 'CHA-IM-001', 'style' => 'Deportivo', 'size' => 'XL', 'color' => 'Azul'],
            ['name' => 'Vestido Casual Floral', 'cat' => $camisasCat, 'brand' => $brand1, 'cost' => 14000, 'sale' => 26900, 'wholesale' => 22900, 'code' => 'VES-CF-001', 'style' => 'Casual', 'size' => 'S', 'color' => 'Rosa'],
            ['name' => 'Sandalia Playa', 'cat' => $calzadoDepCat, 'brand' => $brand2, 'cost' => 5000, 'sale' => 9900, 'wholesale' => 8500, 'code' => 'SAN-PL-001', 'style' => 'Casual', 'size' => 'M', 'color' => 'Beige'],
            ['name' => 'Cinturón Cuero Artesanal', 'cat' => $accesoriosCat, 'brand' => $brand1, 'cost' => 8000, 'sale' => 15900, 'wholesale' => 13500, 'code' => 'CIN-CA-001', 'style' => 'Clásico', 'size' => 'L', 'color' => 'Negro'],
            ['name' => 'Sombrero Caño Tejido', 'cat' => $accesoriosCat, 'brand' => $brand2, 'cost' => 4000, 'sale' => 7900, 'wholesale' => 6500, 'code' => 'SOM-CT-001', 'style' => 'Bohemio', 'size' => 'M', 'color' => 'Gris'],
            ['name' => 'Toalla Premium Algodón', 'cat' => $hogarCat, 'brand' => $brand4, 'cost' => 8500, 'sale' => 15900, 'wholesale' => 13500, 'code' => 'TOA-PA-001', 'style' => 'Clásico', 'size' => 'L', 'color' => 'Blanco'],
            ['name' => 'Servilletas Tejidas Pack 6', 'cat' => $hogarCat, 'brand' => $brand1, 'cost' => 3500, 'sale' => 6900, 'wholesale' => 5900, 'code' => 'SER-TJ-001', 'style' => 'Bohemio', 'size' => 'S', 'color' => 'Beige'],
            ['name' => 'Polo Deportivo DryFit', 'cat' => $camisasCat, 'brand' => $brand4, 'cost' => 7000, 'sale' => 13900, 'wholesale' => 11900, 'code' => 'POL-DF-001', 'style' => 'Deportivo', 'size' => 'M', 'color' => 'Verde'],
            ['name' => 'Jeans Classic Fit', 'cat' => $pantalonesCat, 'brand' => $brand3, 'cost' => 12000, 'sale' => 22900, 'wholesale' => 19900, 'code' => 'JEA-CF-001', 'style' => 'Casual', 'size' => 'L', 'color' => 'Azul'],
            ['name' => 'Abrigo Ligero Cromático', 'cat' => $camisasCat, 'brand' => $brand1, 'cost' => 20000, 'sale' => 37900, 'wholesale' => 32900, 'code' => 'ABR-LC-001', 'style' => 'Formal', 'size' => 'XL', 'color' => 'Gris'],
            ['name' => 'Cartera Mini Cuero', 'cat' => $accesoriosCat, 'brand' => $brand1, 'cost' => 9000, 'sale' => 17900, 'wholesale' => 15500, 'code' => 'CAR-MC-001', 'style' => 'Clásico', 'size' => 'S', 'color' => 'Negro'],
            ['name' => 'Bermuda Casual', 'cat' => $pantalonesCat, 'brand' => $brand2, 'cost' => 7500, 'sale' => 13900, 'wholesale' => 11900, 'code' => 'BER-CA-001', 'style' => 'Casual', 'size' => 'M', 'color' => 'Beige'],
            ['name' => 'Camisa Polo Clásica', 'cat' => $camisasCat, 'brand' => $brand3, 'cost' => 9500, 'sale' => 17900, 'wholesale' => 15500, 'code' => 'CAM-PC-005', 'style' => 'Clásico', 'size' => 'L', 'color' => 'Rojo'],
            ['name' => 'Calcetines Pack 3 Pares', 'cat' => $calzadoDepCat, 'brand' => $brand4, 'cost' => 2000, 'sale' => 3900, 'wholesale' => 3200, 'code' => 'CAL-PK-001', 'style' => 'Deportivo', 'size' => 'XS', 'color' => 'Negro'],
            ['name' => 'Bufanda Artesanal CR', 'cat' => $accesoriosCat, 'brand' => $brand1, 'cost' => 6000, 'sale' => 11900, 'wholesale' => 9900, 'code' => 'BUF-AR-001', 'style' => 'Bohemio', 'size' => 'M', 'color' => 'Rojo'],
        ];

        foreach ($products as $p) {
            Product::firstOrCreate(
                ['company_id' => $cid, 'internal_code' => $p['code']],
                [
                    'category_id' => $p['cat']->id,
                    'brand_id' => $p['brand']->id,
                    'unit_id' => $unit->id,
                    'style_id' => $styleByName[$p['style']]->id,
                    'size_id' => $sizeByName[$p['size']]->id,
                    'color_id' => $colorByName[$p['color']]->id,
                    'name' => $p['name'],
                    'product_type' => 'product',
                    'cost' => $p['cost'],
                    'sale_price' => $p['sale'],
                    'wholesale_price' => $p['wholesale'],
                    'tax_rate' => 13,
                    'track_inventory' => true,
                    'allow_negative_stock' => false,
                    'is_active' => true,
                    'prints_label' => true,
                    'image' => $p['image'] ?? null,
                ],
            );
        }
    }

    private function seedInventory(Company $company): void
    {
        $branches = $company->branches()->get();
        $products = Product::where('company_id', $company->id)->get();

        foreach ($products as $product) {
            foreach ($branches as $branch) {
                $branch->products()->syncWithoutDetaching([
                    $product->id => [
                        'stock' => rand(5, 50),
                        'minimum_stock' => rand(3, 10),
                        'maximum_stock' => rand(40, 100),
                    ],
                ]);
            }
        }
    }

    private function seedCustomers(Company $company): void
    {
        $customers = [
            ['name' => 'María Fernández López', 'identification' => '123456789', 'phone' => '8888-1111', 'email' => 'maria.fernandez@test.com'],
            ['name' => 'Carlos Ramírez Vargas', 'identification' => '234567890', 'phone' => '8888-2222', 'email' => 'carlos.ramirez@test.com'],
            ['name' => 'Ana Montero Cruz', 'identification' => '345678901', 'phone' => '8888-3333', 'email' => 'ana.montero@test.com'],
            ['name' => 'Pedro Alvarado Solís', 'identification' => '456789012', 'phone' => '8888-4444', 'email' => 'pedro.alvarado@test.com'],
            ['name' => 'Laura Rojas Quirós', 'identification' => '567890123', 'phone' => '8888-5555', 'email' => 'laura.rojas@test.com'],
            ['name' => 'Jorge Méndez Chaves', 'identification' => '678901234', 'phone' => '8888-6666', 'email' => 'jorge.mendez@test.com'],
            ['name' => 'Sofía Castillo Mora', 'identification' => '789012345', 'phone' => '8888-7777', 'email' => 'sofia.castillo@test.com'],
            ['name' => 'Diego Arroyo Jiménez', 'identification' => '890123456', 'phone' => '8888-8888', 'email' => 'diego.arroyo@test.com'],
            ['name' => 'Valentina Herrera Barrantes', 'identification' => '901234567', 'phone' => '8888-9999', 'email' => 'valentina.herrera@test.com'],
            ['name' => 'Andrés Campos Umaña', 'identification' => '012345678', 'phone' => '8888-0000', 'email' => 'andres.campos@test.com'],
            ['name' => 'Isabella Vargas Mena', 'identification' => '111222333', 'phone' => '8777-1111', 'email' => 'isabella.vargas@test.com'],
            ['name' => 'Felipe Rojas Badilla', 'identification' => '222333444', 'phone' => '8777-2222', 'email' => 'felipe.rojas@test.com'],
            ['name' => 'Gabriela Solano Retana', 'identification' => '333444555', 'phone' => '8777-3333', 'email' => 'gabriela.solano@test.com'],
        ];

        foreach ($customers as $c) {
            Customer::firstOrCreate(
                ['company_id' => $company->id, 'identification' => $c['identification']],
                [
                    'customer_type' => 'individual',
                    'identification_type' => '01',
                    'name' => $c['name'],
                    'phone' => $c['phone'],
                    'phone_country_code' => '+506',
                    'email' => $c['email'],
                    'is_active' => true,
                    'accepts_email_invoice' => true,
                ],
            );
        }
    }

    private function seedSuppliers(Company $company): void
    {
        $suppliers = [
            ['name' => 'Textiles del Valle S.A.', 'identification' => '3101234567', 'contact_name' => 'Roberto Jiménez', 'phone' => '2222-1111', 'email' => 'ventas@textilesdelvalle.com'],
            ['name' => 'Importadora Centroamericana', 'identification' => '3102345678', 'contact_name' => 'Patricia Campos', 'phone' => '2222-2222', 'email' => 'pedidos@importadora-ca.com'],
            ['name' => 'Distribuidora Tropical', 'identification' => '3103456789', 'contact_name' => 'Luis Mena', 'phone' => '2222-3333', 'email' => 'info@distribidoratropical.com'],
        ];

        foreach ($suppliers as $s) {
            Supplier::firstOrCreate(
                ['company_id' => $company->id, 'identification' => $s['identification']],
                [
                    'supplier_type' => 'company',
                    'identification_type' => '02',
                    'name' => $s['name'],
                    'contact_name' => $s['contact_name'],
                    'phone' => $s['phone'],
                    'email' => $s['email'],
                    'credit_days' => 30,
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedLoyalty(Company $company): void
    {
        LoyaltySetting::firstOrCreate(
            ['company_id' => $company->id],
            [
                'is_active' => true,
                'earning_percentage' => 5.0000,
                'point_value' => 1.0000,
                'minimum_redemption_points' => 100,
                'redemption_minimum_enabled' => true,
                'redemption_minimum_amount' => 1000,
                'maximum_redemption_percent' => 30,
                'earn_on_offers' => false,
                'birthday_enabled' => true,
                'birthday_points' => 50,
                'returning_customer_enabled' => true,
                'returning_customer_days' => 90,
                'returning_customer_points' => 25,
                'redeem_on_offers' => false,
                'expiration_enabled' => true,
                'expiration_months' => 12,
            ],
        );
    }

    private function seedLabels(Company $company): void
    {
        $branches = $company->branches()->get();
        foreach ($branches as $branch) {
            BranchLabelSetting::firstOrCreate(
                ['company_id' => $company->id, 'branch_id' => $branch->id],
                [
                    'print_destinations' => ['thermal'],
                    'default_template' => 'name_price_barcode',
                    'default_size' => '50x30',
                    'default_print_mode' => 'thermal',
                    'use_custom_size' => false,
                    'custom_width' => 50,
                    'custom_height' => 30,
                ],
            );
        }
    }

    private function seedCashRegisters(Company $company): void
    {
        $branches = $company->branches()->get();
        foreach ($branches as $branch) {
            \App\Models\CashRegister::firstOrCreate(
                ['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CAJA-' . $branch->code],
                ['name' => 'Caja Principal ' . $branch->code, 'is_active' => true, 'is_default' => true],
            );
        }
    }

    private function syncProductImages(Company $company): void
    {
        $source = config('demo.assets_source') . '/products';
        $dest = storage_path('app/public/products');
        @mkdir($dest, 0755, true);

        foreach (self::DEMO_PRODUCT_IMAGES as $code) {
            $srcFile = $source . '/' . $code . '.png';
            $destFile = $dest . '/' . $code . '.png';

            if (file_exists($destFile)) {
                @unlink($destFile);
            }

            if (file_exists($srcFile)) {
                @copy($srcFile, $destFile);
            }
        }
    }

    private function seedLoyaltyPosts(Company $company): void
    {
        foreach (self::DEMO_LOYALTY_POSTS as $post) {
            LoyaltyPortalPost::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'title' => $post['title'],
                ],
                [
                    'type' => 'promotion',
                    'message' => $post['message'],
                    'cta_type' => $post['cta_type'],
                    'image' => 'demo/' . $company->id . '/loyalty/' . $post['file'],
                    'is_active' => true,
                    'is_featured' => true,
                    'sort_order' => 0,
                ],
            );
        }
    }

    private function syncLoyaltyAssets(Company $company): void
    {
        $source = config('demo.assets_source') . '/loyalty';
        $runtimeBase = config('demo.assets_runtime');
        $dest = $runtimeBase . '/' . $company->id . '/loyalty';

        if (is_dir($dest)) {
            $this->removeDirectory($dest);
        }

        @mkdir($dest, 0755, true);

        foreach (self::DEMO_LOYALTY_POSTS as $post) {
            $srcFile = $source . '/' . $post['file'];
            if (file_exists($srcFile)) {
                @copy($srcFile, $dest . '/' . $post['file']);
            }
        }

        @file_put_contents($dest . '/.gitkeep', '');
    }

    private function syncDemoAssets(Company $company): void
    {
        if (! $this->isDemoCompany($company)) {
            return;
        }

        $source = config('demo.assets_source');
        $runtimeBase = config('demo.assets_runtime');
        $runtimeDir = $runtimeBase . '/' . $company->id;

        if (is_dir($runtimeDir)) {
            $this->removeDirectory($runtimeDir);
        }

        if (is_dir($source)) {
            $this->copyDirectory($source, $runtimeDir);
        } else {
            @mkdir($runtimeDir, 0755, true);
        }

        $dirs = ['products', 'loyalty', 'promotions'];
        foreach ($dirs as $dir) {
            $path = $runtimeDir . '/' . $dir;
            if (! is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            $placeholder = $path . '/.gitkeep';
            if (! file_exists($placeholder)) {
                @file_put_contents($placeholder, '');
            }
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }

        @rmdir($path);
    }

    private function copyDirectory(string $source, string $destination): void
    {
        @mkdir($destination, 0755, true);

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($items as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $targetPath = $destination . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                @mkdir($targetPath, 0755, true);
            } else {
                @mkdir(dirname($targetPath), 0755, true);
                @copy($item->getRealPath(), $targetPath);
            }
        }
    }
}
