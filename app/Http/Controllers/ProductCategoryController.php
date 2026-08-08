<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductCategoryRequest;
use App\Http\Requests\UpdateProductCategoryRequest;
use App\Models\ProductCategory;

class ProductCategoryController extends Controller
{
    /**
     * Mostrar listado de categorías.
     */
    public function index()
{
    $companyId = session('active_company_id');
    $search = request('search');

    $categories = ProductCategory::where('company_id', $companyId)
        ->with('parent')
        ->when($search, function ($query) use ($search) {
            $query->where('name', 'like', "%{$search}%");
        })
        ->orderBy('sort_order')
        ->orderBy('name')
        ->paginate(10)
        ->withQueryString();

    return view('categorias.index', compact(
        'categories',
        'search'
    ));
}

    /**
     * Mostrar formulario para crear.
     */
    public function create()
{
    $companyId = session('active_company_id');

    $categoriasPadre = ProductCategory::where('company_id', $companyId)
        ->whereNull('parent_id')
        ->where('is_active', true)
        ->orderBy('name')
        ->get();

    return view('categorias.create', compact('categoriasPadre'));
}

/**
 * Guardar categoría.
 */
public function store(StoreProductCategoryRequest $request)
{
    $data = $request->validated();
    $data['company_id'] = session('active_company_id');

    ProductCategory::create($data);

    return redirect()
        ->route('categorias.index')
        ->with('success', 'Categoría creada correctamente.');
}

    /**
     * Mostrar formulario para editar.
     */

   public function edit(ProductCategory $categoria)
{
    $companyId = session('active_company_id');

    abort_unless($categoria->company_id == $companyId, 404);

    $categoriasPadre = ProductCategory::where('company_id', $companyId)
        ->whereNull('parent_id')
        ->where('id', '!=', $categoria->id)
        ->where('is_active', true)
        ->orderBy('name')
        ->get();

    return view('categorias.edit', compact(
        'categoria',
        'categoriasPadre'
    ));
}
    /**
     * Actualizar categoría.
     */
    public function update(UpdateProductCategoryRequest $request, ProductCategory $categoria)
{
    $companyId = session('active_company_id');

    abort_unless($categoria->company_id == $companyId, 404);

    $categoria->update($request->validated());

    return redirect()
        ->route('categorias.index')
        ->with('success', 'Categoría actualizada correctamente.');
}

    /**
     * Eliminar categoría.
     */
    public function destroy(ProductCategory $categoria)
{
    $companyId = session('active_company_id');

    abort_unless($categoria->company_id == $companyId, 404);

    $categoria->delete();

    return redirect()
        ->route('categorias.index')
        ->with('success', 'Categoría eliminada correctamente.');
}

}