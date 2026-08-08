@extends('layouts.app')

@section('title', 'Kardex')

@section('content')

<div class="space-y-6">

    <div class="flex items-center justify-between gap-4">

    <div>
        <h1 class="text-2xl font-bold text-slate-800">
            Kardex de Inventario
        </h1>

        <p class="mt-1 text-sm text-slate-500">
            Historial de movimientos de la sucursal activa.
        </p>
    </div>

    <a
        href="{{ route('inventario.index') }}"
        class="shrink-0 rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
        ← Volver
    </a>

</div>
    {{-- FILTROS --}}
<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">

    <form
        id="kardex-filter-form"
        method="GET"
        action="{{ route('kardex.index') }}"
        class="grid grid-cols-1 items-end gap-4 lg:grid-cols-12">

        {{-- BUSCAR PRODUCTO --}}
        <div class="relative lg:col-span-4">

            <label
                for="product_search"
                class="mb-2 block text-sm font-semibold text-slate-600">
                Buscar producto
            </label>

            <input
                type="text"
                id="product_search"
                autocomplete="off"
                placeholder="Escriba producto, código o escanee código de barras..."
                class="h-12 w-full rounded-xl border border-slate-300 px-4 text-sm">

            <input
                type="hidden"
                name="product_id"
                id="product_id"
                value="{{ request('product_id') }}">

            <div
                id="product_results"
                class="absolute left-0 right-0 z-50 mt-1 hidden max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
            </div>

        </div>

        {{-- DESDE --}}
        <div class="lg:col-span-2">

            <label
                for="date_from"
                class="mb-2 block text-sm font-semibold text-slate-600">
                Desde
            </label>

            <input
                type="date"
                id="date_from"
                name="date_from"
                value="{{ request('date_from') }}"
                class="h-12 w-full rounded-xl border border-slate-300 px-4 text-sm">

        </div>

        {{-- HASTA --}}
        <div class="lg:col-span-2">

            <label
                for="date_to"
                class="mb-2 block text-sm font-semibold text-slate-600">
                Hasta
            </label>

            <input
                type="date"
                id="date_to"
                name="date_to"
                value="{{ request('date_to') }}"
                class="h-12 w-full rounded-xl border border-slate-300 px-4 text-sm">

        </div>

        {{-- TIPO --}}
        <div class="lg:col-span-2">

            <label
                for="movement_type"
                class="mb-2 block text-sm font-semibold text-slate-600">
                Tipo de movimiento
            </label>

            <select
                id="movement_type"
                name="type"
                class="h-12 w-full rounded-xl border border-slate-300 px-3 text-sm">

                <option value="">
                    Todos
                </option>

                <option value="entry" @selected(request('type') === 'entry')>
                    Entradas
                </option>

                <option value="exit" @selected(request('type') === 'exit')>
                    Salidas
                </option>

                <option value="transfer_in" @selected(request('type') === 'transfer_in')>
                    Transferencias recibidas
                </option>

                <option value="transfer_out" @selected(request('type') === 'transfer_out')>
                    Transferencias enviadas
                </option>

            </select>

        </div>

        {{-- LIMPIAR --}}
        <div class="lg:col-span-2">

            <a
                href="{{ route('kardex.index') }}"
                class="flex h-12 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                Limpiar
            </a>

        </div>

    </form>

</div>

    {{-- TABLA --}}
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

        <div class="overflow-x-auto">

            <table class="min-w-full table-fixed">

                <thead class="bg-amber-50">

    <tr>

        <th class="w-32 px-4 py-4 text-left text-sm font-semibold text-slate-600">
            Fecha
        </th>

       <th class="w-72 px-4 py-4 text-left text-sm font-semibold text-slate-600">
    Producto
</th>

        <th class="w-40 px-4 py-4 text-center text-sm font-semibold text-slate-600">
            Tipo
        </th>

        <th class="w-24 px-4 py-4 text-right text-sm font-semibold text-slate-600">
            Cantidad
        </th>

        <th class="w-24 px-4 py-4 text-right text-sm font-semibold text-slate-600">
            Anterior
        </th>

        <th class="w-24 px-4 py-4 text-right text-sm font-semibold text-slate-600">
            Nuevo
        </th>

        <th class="w-60 px-4 py-4 text-left text-sm font-semibold text-slate-600">
    Motivo
</th>

        <th class="w-36 px-4 py-4 text-left text-sm font-semibold text-slate-600">
            Usuario
        </th>

    </tr>

</thead>

                <tbody class="divide-y divide-slate-200">

                    @forelse($movements as $movement)

                        <tr class="transition hover:bg-amber-50/50">

                            <td class="whitespace-nowrap px-4 py-4 text-sm text-slate-600">
                                {{ $movement->created_at->format('d/m/Y H:i') }}
                            </td>

                            <td class="px-4 py-4">

                               <div class="whitespace-nowrap font-semibold text-slate-800">
                                    {{ $movement->product->name ?? 'Producto eliminado' }}
                                </div>

                                <div class="text-xs text-slate-500">
                                    {{ $movement->product->internal_code ?? '-' }}
                                </div>

                            </td>

                            <td class="px-4 py-4 text-center align-middle">

    <div class="flex justify-center">

        @if($movement->type === 'entry')

            <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700">
                Entrada
            </span>

        @elseif($movement->type === 'exit')

            <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">
                Salida
            </span>

        @elseif($movement->type === 'transfer_in')

            <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700">
                Transferencia recibida
            </span>

        @elseif($movement->type === 'transfer_out')

            <span class="whitespace-nowrap rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">
    Transferencia enviada
</span>

        @elseif($movement->type === 'purchase')

            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">
                Compra
            </span>

        @elseif($movement->type === 'purchase_cancel')

            <span class="whitespace-nowrap rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">
        Compra anulada
            </span>

        @else

            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                {{ $movement->type }}
            </span>

        @endif

    </div>

</td>

                            <td class="px-4 py-4 text-right text-slate-600">
                                {{ number_format($movement->previous_stock, 2) }}
                            </td>

                            <td class="px-4 py-4 text-right font-bold text-slate-800">
                                {{ number_format($movement->new_stock, 2) }}
                            </td>

                            <td class="px-4 py-4 text-sm text-slate-600">
                                {{ $movement->reason }}
                            </td>

                            <td class="px-4 py-4 text-sm text-slate-600">
                                {{ $movement->user->name ?? 'Sistema' }}
                            </td>

                        </tr>

                    @empty

                        <tr>
                            <td colspan="8"
                                class="px-6 py-12 text-center text-slate-400">

                                No existen movimientos de inventario para esta sucursal.

                            </td>
                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

    </div>

    {{ $movements->links() }}

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('product_search');
    const productId = document.getElementById('product_id');
    const results = document.getElementById('product_results');
    const form = document.getElementById('kardex-filter-form');
    const typeSelect = form.querySelector('[name="type"]');
    const dateFrom = form.querySelector('[name="date_from"]');
const dateTo = form.querySelector('[name="date_to"]');

    let timer;

    searchInput.addEventListener('input', function () {

        clearTimeout(timer);

        const search = this.value.trim();

        productId.value = '';

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
                    throw new Error('Error al buscar productos');
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
                            ${product.barcode ? ' · ' + escapeHtml(product.barcode) : ''}
                        </div>
                    `;

                    item.addEventListener('click', function () {

                        productId.value = product.id;
                        searchInput.value = product.name;

                        results.classList.add('hidden');

                        form.submit();
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

    /*
     * Al cambiar el tipo, filtra automáticamente.
     */
    typeSelect.addEventListener('change', function () {
        form.submit();
    });

    dateFrom.addEventListener('change', function () {
    form.submit();
});

dateTo.addEventListener('change', function () {
    form.submit();
});

    /*
     * Cerrar coincidencias al hacer clic afuera.
     */
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