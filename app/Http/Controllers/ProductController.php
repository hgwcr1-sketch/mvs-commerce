<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Size;
use App\Models\Style;
use App\Models\Unit;
use App\Services\Cabys\ProductCabysService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * Mostrar listado.
     */
    public function index()
    {
        $branchId = session('active_branch_id');
        $companyId = session('active_company_id');

        $query = Product::where('company_id', $companyId)
            ->with(['category', 'brand', 'unit', 'style', 'size', 'color'])
            ->with([
                'branches' => function ($query) use ($branchId) {
                    $query->where('branches.id', $branchId);
                },
                'productSuppliers' => function ($query) {
                    $query->where('is_primary', true)
                        ->where('is_active', true)
                        ->with('supplier');
                },
            ]);

        /*
         * Buscador.
         */
        if ($search = request('search')) {
            $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($q) use ($search, $likeOperator) {
                $q->where('name', $likeOperator, "%{$search}%")
                    ->orWhere('internal_code', $likeOperator, "%{$search}%")
                    ->orWhere('barcode', $likeOperator, "%{$search}%")
                    ->orWhere('cabys_code', $likeOperator, "%{$search}%");
            });
        }

        /*
         * Filtro por categoría.
         */
        $categoryId = request()->integer('category');
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }

        /*
         * Filtro por marca.
         */
        if ($brandId = request('brand')) {
            $query->where('brand_id', $brandId);
        }

        $products = $query
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        $products->getCollection()->each(function ($product) {
            $branch = $product->branches->first();

            $product->branch_stock = $branch
                ? $branch->pivot->stock
                : 0;

            $product->branch_minimum_stock = $branch
                ? $branch->pivot->minimum_stock
                : 0;

            $product->branch_maximum_stock = $branch
                ? $branch->pivot->maximum_stock
                : 0;
        });

        $categories = ProductCategory::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $brands = Brand::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $statsProducts = Product::where('company_id', $companyId)
            ->with([
                'branches' => function ($query) use ($branchId) {
                    $query->where('branches.id', $branchId);
                },
            ])
            ->get();

        $totalProducts = $statsProducts->count();

        $activeProducts = $statsProducts
            ->where('is_active', true)
            ->count();

        $outOfStockProducts = $statsProducts
            ->filter(function ($product) {

                $branch = $product->branches->first();

                $stock = $branch
                    ? (float) $branch->pivot->stock
                    : 0;

                return $stock <= 0;
            })
            ->count();

        $lowStockProducts = $statsProducts
            ->filter(function ($product) {

                $branch = $product->branches->first();

                if (! $branch) {
                    return false;
                }

                $stock = (float) $branch->pivot->stock;
                $minimum = $branch->pivot->minimum_stock;

                if ($minimum === null) {
                    return false;
                }

                return $stock > 0
                    && $stock <= (float) $minimum;
            })
            ->count();

        return view('productos.index', compact(
            'products',
            'categories',
            'brands',
            'totalProducts',
            'activeProducts',
            'lowStockProducts',
            'outOfStockProducts'
        ));

    }

    /**
     * Mostrar formulario.
     */
    public function create()
    {
        $companyId = session('active_company_id');

        $categories = ProductCategory::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $brands = Brand::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $units = Unit::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $styles = Style::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $sizes = Size::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $colors = Color::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        // Alta: todavía no hay asignación CABYS que mostrar.
        $cabysState = null;
        $fiscalRegimeInfo = ['officialPct' => null, 'officialRaw' => null, 'regime' => null, 'warning' => null];

        $company = Company::query()->find((int) session('active_company_id'));

        if ($company !== null) {
            $fiscalConfig = $company->fiscalConfig()->first();

            if ($fiscalConfig !== null) {
                $regime = $fiscalConfig->tax_regime;

                switch ($regime) {
                    case 'general':
                        $fiscalRegimeInfo = [
                            'officialPct' => null,
                            'officialRaw' => null,
                            'regime' => 'general',
                            'warning' => null,
                        ];
                        break;

                    case 'simplified':
                        $fiscalRegimeInfo = [
                            'officialPct' => null,
                            'officialRaw' => null,
                            'regime' => 'simplified',
                            'warning' => 'Tarifa oficial CABYS conservada, impuesto de venta sin auto-aplicar',
                        ];
                        break;

                    case 'unknown':
                        $fiscalRegimeInfo = [
                            'officialPct' => null,
                            'officialRaw' => null,
                            'regime' => 'unknown',
                            'warning' => 'Tarifa oficial CABYS conservada, no auto-aplicar impuesto; mostrar advertencia',
                        ];
                        break;

                    case 'manual':
                        $fiscalRegimeInfo = [
                            'officialPct' => null,
                            'officialRaw' => null,
                            'regime' => 'manual',
                            'warning' => 'No sobrescribir tarifa manual existente; mantener discrepancy',
                        ];
                        break;

                    default:
                        $fiscalRegimeInfo = [
                            'officialPct' => null,
                            'officialRaw' => null,
                            'regime' => 'unknown',
                            'warning' => 'Régimen no reconocido',
                        ];
                        break;
                }
            }
        }

        return view('productos.create', compact(
            'categories',
            'brands',
            'units',
            'styles',
            'sizes',
            'colors',
            'cabysState',
            'fiscalRegimeInfo'
        ));
    }

    /**
     * Guardar producto.
     */
    public function store(StoreProductRequest $request)
    {
        $data = $request->validated();
        $data['company_id'] = session('active_company_id');

        /*
         * MF04: la selección CABYS viene del buscador del catálogo local, no
         * es texto libre ni columna de products: se resuelve dentro de la
         * misma transacción que crea el producto.
         */
        $proposedCabysCode = trim((string) ($data['cabys_proposed_code'] ?? ''));
        unset($data['cabys_proposed_code'], $data['cabys_code']);

        if ($request->has('subcategory_id') && $request->subcategory_id) {
            $data['category_id'] = $request->subcategory_id;
        }
        unset($data['subcategory_id']);

        if ($request->hasFile('image')) {
            $data['image'] = $request
                ->file('image')
                ->store('products', 'public');
        }

        $data['track_inventory'] = $request->boolean('track_inventory');
        $data['allow_negative_stock'] = $request->boolean('allow_negative_stock');
        $data['is_active'] = $request->boolean('is_active');

        /*
         * Guardamos temporalmente los valores de inventario
         * para la sucursal activa.
         */
        $initialStock = $data['stock'] ?? 0;
        $minimumStock = $data['minimum_stock'] ?? null;
        $maximumStock = $data['maximum_stock'] ?? null;

        $cabysState = null;
        $fiscalRegime = null;

        $company = Company::query()->find((int) session('active_company_id'));

        if ($company !== null) {
            $fiscalConfig = $company->fiscalConfig()->first();

            if ($fiscalConfig !== null) {
                $fiscalRegime = $fiscalConfig->tax_regime;
            }
        }

        $product = DB::transaction(function () use ($data, $initialStock, $minimumStock, $maximumStock, $proposedCabysCode, $request, &$cabysState, $fiscalRegime) {
            $product = Product::create($data);

            /*
             * Asociar producto con la sucursal activa.
             */
            $branchId = session('active_branch_id');

            if ($branchId) {
                $product->branches()->attach($branchId, [
                    'stock' => $initialStock,
                    'minimum_stock' => $minimumStock,
                    'maximum_stock' => $maximumStock,
                ]);
            }

            /*
             * Producto + asignación CABYS en UNA operación: si el código sigue
             * válido contra la versión vigente se confirma aquí mismo; si es
             * ambiguo, inválido o no hay catálogo, el producto se guarda igual
             * y la asignación queda pendiente. Nada del alta depende de que la
             * asignación se confirme.
             */
            if ($proposedCabysCode !== '') {
                $cabysState = $this->registerCabysSelection(
                    $product,
                    $proposedCabysCode,
                    (int) $request->user()?->id
                );

                /*
                 * Aplicar impuesto según régimen fiscal de la empresa.
                 * - general: auto-aplicar tarifa CABYS al impuesto del producto
                 * - simplified: conservar tarifa oficial, NO auto-aplicar impuesto de venta
                 * - unknown: conservar tarifa oficial, NO auto-aplicar, mostrar advertencia
                 * - manual: no sobrescribir tarifa manual existente
                 */
                if ($cabysState['status'] === 'confirmed' && $proposedCabysCode !== '') {
                    $this->applyTaxAccordingToFiscalRegime($product, $fiscalRegime);
                }
            }

            return $product;
        });

        if ($request->expectsJson()) {
            $product->load(['brand', 'category', 'unit']);

            return response()->json([
                'id' => $product->id,
                'name' => $product->name,
                'internal_code' => $product->internal_code,
                'barcode' => $product->barcode,
                'barcodes' => [],
                'brand' => $product->brand?->name,
                'category' => $product->category?->name,
                'unit' => $product->unit?->name,
                'cost' => (float) $product->cost,
                'sale_price' => (float) $product->sale_price,
                'tax_rate' => (float) $product->tax_rate,
                'track_inventory' => (bool) $product->track_inventory,
                'stock' => (float) $initialStock,
                'cabys' => $cabysState,
            ], 201);
        }

        return redirect()
            ->route('productos.index')
            ->with('success', 'Producto creado correctamente.'.$this->cabysSuffix($cabysState));
    }

    /**
     * Mostrar producto.
     */
    public function show(Product $producto)
    {
        $this->scoped($producto);

        return view('productos.show', compact('producto'));
    }

    /**
     * Editar producto.
     */
    public function edit(Product $producto)
    {
        $this->scoped($producto);
        $companyId = session('active_company_id');
        $branchId = session('active_branch_id');

        $categories = ProductCategory::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $brands = Brand::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $units = Unit::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $styles = Style::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $sizes = Size::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $colors = Color::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $branch = $producto->branches()
            ->where('branches.id', $branchId)
            ->first();

        $product = $producto;

        $product->branch_stock = $branch
            ? $branch->pivot->stock
            : 0;

        $product->branch_minimum_stock = $branch
            ? $branch->pivot->minimum_stock
            : null;

        $product->branch_maximum_stock = $branch
            ? $branch->pivot->maximum_stock
            : null;

        $company = Company::query()->find((int) $companyId);

        $cabysState = $company !== null
            ? app(ProductCabysService::class)->stateFor($company, $producto)
            : null;

        $fiscalRegimeInfo = ['officialPct' => null, 'officialRaw' => null, 'regime' => null, 'warning' => null];

        if ($company !== null) {
            $fiscalConfig = $company->fiscalConfig()->first();

            if ($fiscalConfig !== null) {
                $regime = $fiscalConfig->tax_regime;

                switch ($regime) {
                    case 'general':
                        $fiscalRegimeInfo = [
                            'officialPct' => null,
                            'officialRaw' => null,
                            'regime' => 'general',
                            'warning' => null,
                        ];
                        break;

                    case 'simplified':
                        $fiscalRegimeInfo = [
                            'officialPct' => null,
                            'officialRaw' => null,
                            'regime' => 'simplified',
                            'warning' => 'Tarifa oficial CABYS conservada, impuesto de venta sin auto-aplicar',
                        ];
                        break;

                    case 'unknown':
                        $fiscalRegimeInfo = [
                            'officialPct' => null,
                            'officialRaw' => null,
                            'regime' => 'unknown',
                            'warning' => 'Tarifa oficial CABYS conservada, no auto-aplicar impuesto; mostrar advertencia',
                        ];
                        break;

                    case 'manual':
                        $fiscalRegimeInfo = [
                            'officialPct' => null,
                            'officialRaw' => null,
                            'regime' => 'manual',
                            'warning' => 'No sobrescribir tarifa manual existente; mantener discrepancy',
                        ];
                        break;

                    default:
                        $fiscalRegimeInfo = [
                            'officialPct' => null,
                            'officialRaw' => null,
                            'regime' => 'unknown',
                            'warning' => 'Régimen no reconocido',
                        ];
                        break;
                }
            }
        }

        return view('productos.edit', compact(
            'product',
            'categories',
            'brands',
            'units',
            'styles',
            'sizes',
            'colors',
            'cabysState'
        ));
    }

    /**
     * Actualizar producto.
     */
    public function update(UpdateProductRequest $request, Product $producto)
    {
        $this->scoped($producto);
        $data = $request->validated();

        /*
         * MF04: igual que en el alta, la selección CABYS viaja desde el
         * buscador del catálogo local y no es columna de products.
         */
        $proposedCabysCode = trim((string) ($data['cabys_proposed_code'] ?? ''));
        unset($data['cabys_proposed_code'], $data['cabys_code']);

        if ($request->has('subcategory_id') && $request->subcategory_id) {
            $data['category_id'] = $request->subcategory_id;
        }
        unset($data['subcategory_id']);

        if ($request->hasFile('image')) {
            if ($producto->image) {
                Storage::disk('public')->delete($producto->image);
            }

            $data['image'] = $request
                ->file('image')
                ->store('products', 'public');
        }

        $data['track_inventory'] = $request->boolean('track_inventory');
        $data['allow_negative_stock'] = $request->boolean('allow_negative_stock');
        $data['is_active'] = $request->boolean('is_active');

        /*
         * Stock mínimo y máximo pertenecen a la sucursal activa.
         * Nunca modificamos aquí el stock actual.
         */
        $minimumStock = $data['minimum_stock'] ?? null;
        $maximumStock = $data['maximum_stock'] ?? null;

        /*
         * Evitar guardar estos valores como inventario global del producto.
         */
        unset(
            $data['stock'],
            $data['minimum_stock'],
            $data['maximum_stock']
        );

        /*
         * T2: si el usuario cambia el impuesto en el formulario, el valor vigente
         * vuelve a ser MANUAL. Así ninguna sincronización futura de CABYS lo
         * sobrescribe en silencio y una tarifa manual que difiera queda respetada.
         */
        $taxChangedManually = array_key_exists('tax_rate', $data)
            && abs((float) $data['tax_rate'] - (float) $producto->tax_rate) > 0.0001;

        $producto->update($data);

        if ($taxChangedManually) {
            $company = Company::query()->find((int) session('active_company_id'));

            if ($company !== null) {
                app(ProductCabysService::class)->markManualTaxRate($company, $producto);
            }
        }

        /*
         * Actualizar configuración de inventario
         * de la sucursal activa.
         */
        $branchId = session('active_branch_id');

        if ($branchId) {
            $producto->branches()->updateExistingPivot($branchId, [
                'minimum_stock' => $minimumStock,
                'maximum_stock' => $maximumStock,
            ]);
        }

        $cabysState = null;

        if ($proposedCabysCode !== '') {
            $cabysState = $this->registerCabysSelection(
                $producto->fresh(),
                $proposedCabysCode,
                (int) $request->user()?->id
            );

            /*
             * Aplicar impuesto según régimen fiscal de la empresa
             * en edición igual que en alta.
             */
            if ($cabysState['status'] === 'confirmed' && $proposedCabysCode !== '') {
                $company = Company::query()->find((int) session('active_company_id'));

                if ($company !== null) {
                    $fiscalConfig = $company->fiscalConfig()->first();

                    if ($fiscalConfig !== null) {
                        $this->applyTaxAccordingToFiscalRegime($producto, $fiscalConfig->tax_regime);
                    }
                }
            }
        }

        return redirect()
            ->route('productos.index')
            ->with('success', 'Producto actualizado correctamente.'.$this->cabysSuffix($cabysState));
    }

    /**
     * Registra la selección CABYS hecha en el formulario del producto.
     *
     * El código viene del buscador del catálogo local (no es texto libre).
     * Aquí se registra como propuesta y, si sigue existiendo en la versión
     * vigente, se confirma en la misma operación. Un fallo del motor (sin
     * catálogo, código inexistente) NO revierte el guardado: queda pendiente.
     *
     * @return array{status: string, code: string, message: string, tax: array<string, mixed>|null}
     */
    private function registerCabysSelection(Product $product, string $code, int $userId): array
    {
        if (preg_match('/^\d{13}$/', $code) !== 1) {
            return [
                'status' => 'pending',
                'code' => $code,
                'message' => 'El código CABYS debe tener 13 dígitos y salir del buscador: no se registró.',
                'tax' => null,
            ];
        }

        $company = Company::query()->find((int) session('active_company_id'));

        if ($company === null) {
            return [
                'status' => 'pending',
                'code' => $code,
                'message' => 'Sin empresa activa: el código quedó sin registrar.',
                'tax' => null,
            ];
        }

        try {
            app(ProductCabysService::class)->suggest($company, $product, $code);
        } catch (\Throwable $exception) {
            Log::warning('cabys.proposal_failed', [
                'product_id' => $product->getKey(),
                'code' => $code,
            ]);

            return [
                'status' => 'pending',
                'code' => $code,
                'message' => 'No se pudo registrar la propuesta CABYS; podrá asignarla en la edición.',
                'tax' => null,
            ];
        }

        try {
            $result = app(ProductCabysService::class)
                ->confirmWithProposal($company, $product->fresh(), $userId);

            return [
                'status' => 'confirmed',
                'code' => $code,
                'message' => 'Código CABYS confirmado. '.$result['proposal']['message'],
                'tax' => $result['proposal'],
            ];
        } catch (\RuntimeException $exception) {
            /* Fallo cerrado esperado: sin catálogo activo o código que ya no
             * está en la versión vigente. La propuesta queda pendiente. */
            return [
                'status' => 'pending',
                'code' => $code,
                'message' => 'El código quedó pendiente: '.$exception->getMessage(),
                'tax' => null,
            ];
        }
    }

    /**
     * Sufijo visible en el listado tras guardar: el estado CABYS del guardado.
     *
     * @param  array{status: string, code: string, message: string}|null  $state
     */
    private function cabysSuffix(?array $state): string
    {
        return $state === null ? '' : ' '.$state['message'];
    }

    /**
     * Aplica el impuesto según el régimen fiscal de la empresa.
     *
     * Reglas:
     * - general: auto-aplicar la tarifa oficial CABYS al tax_rate del producto
     * - simplified: conservar tax_rate_official_pct/tax_rate_official_raw,
     *   NO tocar tax_rate (impuesto de venta)
     * - unknown: igual que simplified, pero con advertencia
     * - manual: si hay tarifa manual explícita distinta, NO sobrescribirla
     *
     * @param  \App\Models\Product  $product
     * @param  string|null  $fiscalRegime
     */
    private function applyTaxAccordingToFiscalRegime(Product $product, ?string $fiscalRegime): void
    {
        $company = Company::query()->find((int) session('active_company_id'));

        if ($company === null) {
            return;
        }

        $cabysService = app(ProductCabysService::class);
        $assignment = $cabysService->assignmentFor($company, $product);

        if ($assignment === null) {
            return;
        }

        $official = $cabysService->officialRateFor($company, $product);

        if ($official['official_pct'] === null) {
            return;
        }

        $officialPct = $official['official_pct'];
        $officialRaw = $official['official_raw'];

        switch ($fiscalRegime) {
            case 'general':
                $product->tax_rate = $officialPct;
                $product->tax_rate_source = 'cabys_confirmed';
                $product->tax_rate_official_pct = $officialPct;
                $product->tax_rate_official_raw = $officialRaw;
                $product->save();
                break;

            case 'simplified':
                $product->tax_rate_official_pct = $officialPct;
                $product->tax_rate_official_raw = $officialRaw;
                $product->save();
                break;

            case 'unknown':
                $product->tax_rate_official_pct = $officialPct;
                $product->tax_rate_official_raw = $officialRaw;
                $product->save();
                break;

            case 'manual':
                $existingTaxRate = (float) ($product->tax_rate ?? 0);
                $existingSource = $product->tax_rate_source ?? 'manual';

                if ($existingSource === 'manual' && $existingTaxRate !== $officialPct) {
                    /* No sobrescribir tarifa manual; mantener discrepancy */
                    return;
                }

                $product->tax_rate = $officialPct;
                $product->tax_rate_source = 'cabys_confirmed';
                $product->tax_rate_official_pct = $officialPct;
                $product->tax_rate_official_raw = $officialRaw;
                $product->save();
                break;

            default:
                /* Unknown or unrecognized: conservar, no auto-aplicar */
                $product->tax_rate_official_pct = $officialPct;
                $product->tax_rate_official_raw = $officialRaw;
                $product->save();
                break;
        }
    }

    /**
     * Eliminar producto.
     */
    public function destroy(Product $producto)
    {
        $this->scoped($producto);

        if ($producto->image) {
            Storage::disk('public')->delete($producto->image);
        }

        $producto->delete();

        return redirect()
            ->route('productos.index')
            ->with('success', 'Producto eliminado correctamente.');
    }

    /**
     * Buscar productos dinámicamente.
     */
    public function search()
    {
        $search = request('q');

        if (! $search || strlen($search) < 1) {
            return response()->json([]);
        }

        $companyId = session('active_company_id');
        $branchId = request('branch_id') ?: session('active_branch_id');

        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $products = Product::where('company_id', $companyId)
            ->with(['unit:id,allows_decimals'])
            ->with([
                'branches' => function ($query) use ($branchId) {
                    $query->where('branches.id', $branchId);
                },
            ])
            ->where('is_active', true)
            ->where(function ($query) use ($search, $likeOperator) {
                $query->where('name', $likeOperator, "%{$search}%")
                    ->orWhere('internal_code', $likeOperator, "%{$search}%")
                    ->orWhere('barcode', $likeOperator, "%{$search}%")
                    ->orWhereHas('barcodes', function ($q) use ($search, $likeOperator) {
                        $q->where('is_active', true)
                            ->where('barcode', $likeOperator, "%{$search}%");
                    });
            })
            ->orderBy('name')
            ->limit(10)
            ->get([
                'id',
                'name',
                'internal_code',
                'barcode',
                'unit_id',
                'sale_price',
                'cost',
                'tax_rate',
                'track_inventory',
            ]);

        return response()->json($products->map(function (Product $product) {
            $branch = $product->branches->first();

            return [
                'id' => $product->id,
                'name' => $product->name,
                'internal_code' => $product->internal_code,
                'barcode' => $product->barcode,
                'allows_decimals' => (bool) $product->unit?->allows_decimals,
                'sale_price' => (float) $product->sale_price,
                'cost' => (float) $product->cost,
                'tax_rate' => (float) $product->tax_rate,
                'track_inventory' => (bool) $product->track_inventory,
                'branch_stock' => $branch ? (float) $branch->pivot->stock : null,
            ];
        }));
    }

    public function createProduct(Request $request)
    {

        $companyId = session('active_company_id');

        return view('compras.product-create-import', [

            'code' => $request->code,

            'name' => $request->name,

            'cost' => $request->cost,

            'categories' => ProductCategory::where(
                'company_id',
                $companyId
            )
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),

        ]);

    }

    private function scoped(Product $producto): Product
    {
        abort_unless(
            (int) $producto->company_id === (int) session('active_company_id'),
            404
        );

        return $producto;
    }
}
