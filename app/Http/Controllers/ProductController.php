<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
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
            ->with(['category', 'brand', 'unit'])
            ->with([
                'branches' => function ($query) use ($branchId) {
                    $query->where('branches.id', $branchId);
                }
            ]);

        /*
         * Buscador.
         */
        if ($search = request('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('internal_code', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('cabys_code', 'like', "%{$search}%");
            });
        }

        /*
         * Filtro por categoría.
         */
        if ($categoryId = request('category')) {
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
        }
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

        if (!$branch) {
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

        return view('productos.create', compact(
            'categories',
            'brands',
            'units'
        ));
    }

    /**
     * Guardar producto.
     */
    public function store(StoreProductRequest $request)
    {
        $data = $request->validated();
        $data['company_id'] = session('active_company_id');

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
    ], 201);
}

        return redirect()
            ->route('productos.index')
            ->with('success', 'Producto creado correctamente.');
    }

    /**
     * Mostrar producto.
     */
    public function show(Product $producto)
    {
        return view('productos.show', compact('producto'));
    }

    /**
     * Editar producto.
     */
    public function edit(Product $producto)
{
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

    return view('productos.edit', compact(
        'product',
        'categories',
        'brands',
        'units'
    ));
}

    /**
     * Actualizar producto.
     */
    public function update(UpdateProductRequest $request, Product $producto)
    {
        $data = $request->validated();
        $data['company_id'] = session('active_company_id');

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

$producto->update($data);

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

        return redirect()
            ->route('productos.index')
            ->with('success', 'Producto actualizado correctamente.');
    }

    /**
     * Eliminar producto.
     */
    public function destroy(Product $producto)
    {
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

        if (!$search || strlen($search) < 1) {
            return response()->json([]);
        }

        $companyId = session('active_company_id');

        $products = Product::where('company_id', $companyId)
            ->with('unit:id,allows_decimals')
            ->where('is_active', true)
            ->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('internal_code', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->limit(10)
            ->get([
                'id',
                'name',
                'internal_code',
                'barcode',
                'unit_id',
            ]);

        return response()->json($products->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'internal_code' => $product->internal_code,
            'barcode' => $product->barcode,
            'allows_decimals' => (bool) $product->unit?->allows_decimals,
        ]));
    }

    public function createProduct(Request $request)
{

    $companyId = session('active_company_id');


    return view('compras.product-create-import', [

        'code' => $request->code,

        'name' => $request->name,

        'cost' => $request->cost,


        'categories' => \App\Models\ProductCategory::where(
            'company_id',
            $companyId
        )
        ->where('is_active', true)
        ->orderBy('name')
        ->get(),

    ]);

}

}
