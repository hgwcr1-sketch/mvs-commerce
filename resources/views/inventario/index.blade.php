@extends('layouts.app')

@section('title', 'Inventario')

@section('description', 'Inventario de la sucursal activa')

@section('content')

<div class="space-y-6">

    {{-- ENCABEZADO --}}
<div class="flex items-center justify-between gap-4">

    <div>
        <h1 class="text-2xl font-bold text-slate-800">
            Inventario
        </h1>

        <p class="mt-1 text-sm text-slate-500">
            Existencias correspondientes a la sucursal activa.
        </p>
    </div>

    <a
        href="{{ route('importaciones.inventario') }}"
        class="shrink-0 rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
        ← Volver
    </a>

</div>  

    {{-- BUSCADOR DINÁMICO --}}
<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">

    <div class="relative">

        <input
            type="text"
            id="product_search"
            autocomplete="off"
            value="{{ request('search') }}"
            placeholder="Escriba producto, código o escanee código de barras..."
            class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">

        <div
            id="product_results"
            class="absolute left-0 right-0 z-50 mt-1 hidden max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
        </div>

    </div>

    @if(request('search'))
        <div class="mt-3">
            <a
                href="{{ route('inventario.index') }}"
                class="text-sm font-semibold text-amber-600 hover:text-amber-700">
                Limpiar búsqueda
            </a>
        </div>
    @endif

</div>

    {{-- TABLA --}}
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

        <div class="overflow-x-auto">

            <table class="min-w-full">

                <thead class="bg-slate-50">

                    <tr>

                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">
                            Código
                        </th>

                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">
                            Producto
                        </th>

                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">
                            Categoría
                        </th>

                        <th class="px-5 py-4 text-center text-sm font-semibold text-slate-600">
                            Stock
                        </th>

                        <th class="px-5 py-4 text-center text-sm font-semibold text-slate-600">
                            Mínimo
                        </th>

                        <th class="px-5 py-4 text-center text-sm font-semibold text-slate-600">
                            Máximo
                        </th>

                        <th class="px-5 py-4 text-center text-sm font-semibold text-slate-600">
                            Estado
                        </th>

                    </tr>

                </thead>

                <tbody class="divide-y divide-slate-200">

                    @forelse($products as $product)

                        <tr class="hover:bg-slate-50">

                            <td class="px-5 py-4 text-sm text-slate-600">
                                {{ $product->internal_code }}
                            </td>

                            <td class="px-5 py-4 font-semibold text-slate-800">
                                {{ $product->name }}
                            </td>

                            <td class="px-5 py-4 text-sm text-slate-600">
                                {{ $product->category->name ?? '-' }}
                            </td>

                            <td class="px-5 py-4 text-center text-lg font-bold text-slate-800">
                                {{ number_format($product->branch_stock, 2) }}
                            </td>

                            <td class="px-5 py-4 text-center text-slate-600">
    {{ number_format($product->branch_minimum_stock, 2) }}
</td>

<td class="px-5 py-4 text-center text-slate-600">
    {{ number_format($product->branch_maximum_stock, 2) }}
</td>

                            <td class="px-5 py-4 text-center">

                                @if($product->branch_stock <= 0)

                                    <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">
                                        Sin existencias
                                    </span>

                                @elseif(
                                    $product->branch_minimum_stock > 0 &&
                                    $product->branch_stock <= $product->branch_minimum_stock
                                )

                                    <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">
                                        Stock bajo
                                    </span>

                                @else

                                    <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700">
                                        Disponible
                                    </span>

                                @endif

                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td colspan="7"
                                class="px-6 py-12 text-center text-slate-400">

                                No hay productos registrados.

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

    </div>

    {{ $products->links() }}

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('product_search');
    const results = document.getElementById('product_results');

    let timer;

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
                            `{{ route('inventario.index') }}?search=${encodeURIComponent(product.internal_code)}`;
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

    document.addEventListener('click', function (event) {

        if (
            event.target !== searchInput &&
            !results.contains(event.target)
        ) {
            results.classList.add('hidden');
        }

    });

    function escapeHtml(value) {

        const div = document.createElement('div');
        div.textContent = value ?? '';

        return div.innerHTML;
    }

});
</script>

@endsection