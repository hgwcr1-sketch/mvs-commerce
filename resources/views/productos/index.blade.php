@extends('layouts.app')

@section('title', 'Productos')

@section('description', 'Administración de productos')

@section('content')

<div class="space-y-6">

    <div class="flex justify-end">

    <a href="{{ route('productos.create') }}">

        <x-button>

            + Nuevo Producto

        </x-button>

    </a>

</div>

    {{-- Tarjetas --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <div class="bg-white rounded-xl border shadow-sm p-5">

            <p class="text-sm text-slate-500">
                Total Productos
            </p>

            <h2 class="mt-2 text-4xl font-bold text-slate-800">
    {{ $totalProducts }}
</h2>

        </div>

        <div class="bg-white rounded-xl border shadow-sm p-5">

            <p class="text-sm text-slate-500">
                Productos Activos
            </p>

            <h2 class="mt-2 text-4xl font-bold text-green-600">
    {{ $activeProducts }}
</h2>

        </div>

        <div class="bg-white rounded-xl border shadow-sm p-5">

            <p class="text-sm text-slate-500">
                Stock Bajo
            </p>

            <h2 class="mt-2 text-4xl font-bold text-amber-500">
                {{ $lowStockProducts }}
            </h2>

        </div>

        <div class="bg-white rounded-xl border shadow-sm p-5">

            <p class="text-sm text-slate-500">
                Sin Existencias
            </p>

            <h2 class="mt-2 text-4xl font-bold text-red-600">
                {{ $outOfStockProducts }}
            </h2>

        </div>

    </div>

    {{-- Buscador y filtros --}}
<form
    method="GET"
    action="{{ route('productos.index') }}"
    class="grid grid-cols-1 md:grid-cols-5 gap-3">

    <div class="relative md:col-span-2">

    <input
        id="product-search"
        type="text"
        name="search"
        value="{{ request('search') }}"
        placeholder="Buscar por nombre, código, barras o CABYS..."
        autocomplete="off"
        class="form-input w-full">

    <div
        id="product-search-results"
        class="absolute z-50 mt-1 hidden w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
    </div>

</div>

    <select
        name="category"
        class="form-select">

        <option value="">Todas las categorías</option>

        @foreach($categories as $category)
            <option
                value="{{ $category->id }}"
                @selected(request('category') == $category->id)>
                {{ $category->name }}
            </option>
        @endforeach

    </select>

    <select
        name="brand"
        class="form-select">

        <option value="">Todas las marcas</option>

        @foreach($brands as $brand)
            <option
                value="{{ $brand->id }}"
                @selected(request('brand') == $brand->id)>
                {{ $brand->name }}
            </option>
        @endforeach

    </select>

    <x-button type="submit">
        Buscar
    </x-button>

</form>

   {{-- Tabla --}}

<x-table>

    <x-table-header>

        <x-th>Imagen</x-th>
        <x-th>Código</x-th>
        <x-th>Producto</x-th>
        <x-th>Categoría</x-th>
        <x-th>Marca</x-th>
        <x-th>Costo</x-th>
        <x-th>Precio</x-th>
        <x-th>Stock</x-th>
        <x-th>Estado</x-th>
        <x-th>Acciones</x-th>

    </x-table-header>

    <x-table-body>

@if($products->count())

    @foreach($products as $product)

    <tr class="border-t hover:bg-slate-50">

        <td class="px-4 py-3">

            @if($product->image)

                <img
                    src="{{ asset('storage/'.$product->image) }}"
                    class="h-12 w-12 rounded-lg object-cover">

            @else

                <div class="h-12 w-12 rounded-lg bg-slate-200"></div>

            @endif

        </td>

        <td class="px-4 py-3">
            {{ $product->internal_code }}
        </td>

        <td class="px-4 py-3 font-medium">
            {{ $product->name }}
        </td>

        <td class="px-4 py-3">
            {{ $product->category->name ?? '-' }}
        </td>

        <td class="px-4 py-3">
            {{ $product->brand->name ?? '-' }}
        </td>

        <td class="px-4 py-3 text-right">
            ₡ {{ number_format($product->cost,2) }}
        </td>

        <td class="px-4 py-3 text-right">
            ₡ {{ number_format($product->sale_price,2) }}
        </td>

        <td class="px-4 py-3 text-center">
        {{ number_format($product->branch_stock, 2) }}
        </td>

        <td class="px-4 py-3 text-center">

            @if($product->is_active)

                <span class="rounded-full bg-green-100 px-3 py-1 text-xs text-green-700">
                    Activo
                </span>

            @else

                <span class="rounded-full bg-red-100 px-3 py-1 text-xs text-red-700">
                    Inactivo
                </span>

            @endif

        </td>

        <td class="px-4 py-3 text-center">

            <div class="flex justify-center gap-2">

                <a
                    href="{{ route('productos.proveedores.index', $product) }}"
                    class="rounded bg-sky-600 px-3 py-1 text-white">

                    Proveedores

                </a>

                <a
                    href="{{ route('productos.edit',$product) }}"
                    class="rounded bg-amber-500 px-3 py-1 text-white">

                    Editar

                </a>

            </div>

        </td>

    </tr>

    @endforeach

@else

<tr>

    <td colspan="10"
        class="py-10 text-center text-slate-500">

        No hay productos registrados.

    </td>

</tr>

@endif

</x-table-body>

</x-table>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('product-search');
    const results = document.getElementById('product-search-results');

    if (!searchInput || !results) {
        return;
    }

    let timer;

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }

    searchInput.addEventListener('input', function () {

        clearTimeout(timer);

        const search = this.value.trim();

        if (search.length < 1) {
            results.innerHTML = '';
            results.classList.add('hidden');
            return;
        }

        timer = setTimeout(async function () {

            try {

                const response = await fetch(
                    `{{ route('productos.search') }}?q=${encodeURIComponent(search)}`,
                    {
                        headers: {
                            'Accept': 'application/json'
                        }
                    }
                );

                if (!response.ok) {
                    throw new Error('Error al buscar');
                }

                const products = await response.json();

                results.innerHTML = '';

                if (products.length === 0) {

                    results.innerHTML = `
                        <div class="px-4 py-4 text-sm text-slate-500">
                            No se encontraron coincidencias.
                        </div>
                    `;

                    results.classList.remove('hidden');
                    return;
                }

                products.forEach(function (product) {

                    const item = document.createElement('button');

                    item.type = 'button';

                    item.className =
                        'block w-full border-b border-slate-100 px-4 py-3 text-left hover:bg-amber-50';

                    item.innerHTML = `
                        <div class="font-semibold text-slate-800">
                            ${escapeHtml(product.name)}
                        </div>

                        <div class="mt-1 text-xs text-slate-500">
                            ${escapeHtml(product.internal_code ?? '')}
                            ${product.barcode
                                ? ' · ' + escapeHtml(product.barcode)
                                : ''}
                        </div>
                    `;

                    item.addEventListener('click', function () {

                        window.location.href =
                            `{{ route('productos.index') }}?search=${encodeURIComponent(product.internal_code)}`;
                    });

                    results.appendChild(item);
                });

                results.classList.remove('hidden');

            } catch (error) {

                console.error(error);

                results.innerHTML = `
                    <div class="px-4 py-4 text-sm text-red-600">
                        Error al buscar productos.
                    </div>
                `;

                results.classList.remove('hidden');
            }

        }, 250);
    });

});
</script>

@endsection
