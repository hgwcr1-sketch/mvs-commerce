@extends('layouts.app')

@section('title', 'Ajuste de Inventario')

@section('content')

<div class="mx-auto max-w-4xl">

    <div class="mb-6 flex items-start justify-between gap-4">

    <div>
        <h1 class="text-2xl font-bold text-slate-800">
            Nuevo Ajuste de Inventario
        </h1>

        <p class="mt-1 text-sm text-slate-500">
            El movimiento se aplicará únicamente a la sucursal activa.
        </p>
    </div>

    <a
        href="{{ route('inventario.index') }}"
        class="shrink-0 rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
        ← Volver
    </a>

</div>

    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">

        <form
            method="POST"
            action="{{ route('ajustes-inventario.store') }}">

            @csrf

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">

                {{-- Producto --}}
<div class="md:col-span-2 relative">

    <label class="mb-2 block text-sm font-semibold text-slate-700">
        Buscar producto *
    </label>

    <input
        type="text"
        id="product_search"
        autocomplete="off"
        placeholder="Escriba nombre, código interno o escanee código de barras..."
        class="w-full rounded-xl border border-slate-300 px-4 py-3">

    {{-- Este es el ID real que se enviará al controlador --}}
    <input
        type="hidden"
        name="product_id"
        id="product_id"
        value="{{ old('product_id') }}">

    {{-- Resultados --}}
    <div
        id="product_results"
        class="absolute left-0 right-0 z-50 mt-1 hidden max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
    </div>

    {{-- Producto seleccionado --}}
    <div
        id="selected_product"
        class="mt-3 hidden rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">

        <p class="text-xs font-semibold uppercase text-amber-600">
            Producto seleccionado
        </p>

        <p
            id="selected_product_name"
            class="mt-1 font-semibold text-slate-800">
        </p>

    </div>

    @error('product_id')
        <p class="mt-1 text-sm text-red-600">
            {{ $message }}
        </p>
    @enderror

</div>

                {{-- Tipo --}}
                <div>

                    <label class="mb-2 block text-sm font-semibold text-slate-700">
                        Tipo de ajuste *
                    </label>

                    <select
                        name="adjustment_type"
                        required
                        class="w-full rounded-xl border border-slate-300 px-4 py-3">

                        <option value="">Seleccione...</option>

                        <option value="entry"
                            @selected(old('adjustment_type') === 'entry')>
                            Entrada (+)
                        </option>

                        <option value="exit"
                            @selected(old('adjustment_type') === 'exit')>
                            Salida (-)
                        </option>

                    </select>

                </div>

                {{-- Cantidad --}}
                <div>

                    <label class="mb-2 block text-sm font-semibold text-slate-700">
                        Cantidad *
                    </label>

                    <input
                        type="number"
                        name="quantity"
                        id="quantity"
                        value="{{ old('quantity') }}"
                        min="1"
                        step="1"
                        required
                        class="w-full rounded-xl border border-slate-300 px-4 py-3">

                    @error('quantity')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror

                </div>

                {{-- Motivo --}}
                <div class="md:col-span-2">

                    <label class="mb-2 block text-sm font-semibold text-slate-700">
                        Motivo *
                    </label>

                    <input
                        type="text"
                        name="reason"
                        value="{{ old('reason') }}"
                        placeholder="Ej: Inventario inicial, corrección de conteo..."
                        required
                        class="w-full rounded-xl border border-slate-300 px-4 py-3">

                </div>

                {{-- Notas --}}
                <div class="md:col-span-2">

                    <label class="mb-2 block text-sm font-semibold text-slate-700">
                        Notas
                    </label>

                    <textarea
                        name="notes"
                        rows="3"
                        class="w-full rounded-xl border border-slate-300 px-4 py-3">{{ old('notes') }}</textarea>

                </div>

            </div>

            <div class="mt-8 flex justify-end gap-3">

                <a
                    href="{{ route('inventario.index') }}"
                    class="rounded-xl border border-slate-300 px-6 py-3 font-semibold text-slate-700 hover:bg-slate-50">

                    Cancelar

                </a>

                <button
                    type="submit"
                    class="rounded-xl bg-amber-500 px-6 py-3 font-semibold text-white hover:bg-amber-600">

                    Aplicar Ajuste

                </button>

            </div>

        </form>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('product_search');
    const productId = document.getElementById('product_id');
    const results = document.getElementById('product_results');
    const selectedBox = document.getElementById('selected_product');
    const selectedName = document.getElementById('selected_product_name');
    const quantityInput = document.getElementById('quantity');

    let timer;

    searchInput.addEventListener('input', function () {

        clearTimeout(timer);

        const search = this.value.trim();

        // Si cambia la búsqueda, quitamos la selección anterior.
        productId.value = '';
        selectedBox.classList.add('hidden');

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
                            No se encontraron productos.
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

                    const barcode = product.barcode
                        ? ` · ${product.barcode}`
                        : '';

                    item.innerHTML = `
                        <div class="font-semibold text-slate-800">
                            ${escapeHtml(product.name)}
                        </div>

                        <div class="mt-1 text-xs text-slate-500">
                            ${escapeHtml(product.internal_code ?? '')}${escapeHtml(barcode)}
                        </div>
                    `;

                    item.addEventListener('click', function () {

                        productId.value = product.id;
                        quantityInput.min = product.allows_decimals ? '0.0001' : '1';
                        quantityInput.step = product.allows_decimals ? '0.0001' : '1';

                        searchInput.value = product.name;

                        selectedName.textContent =
                            `${product.internal_code ?? ''} - ${product.name}`;

                        selectedBox.classList.remove('hidden');

                        results.classList.add('hidden');
                    });

                    results.appendChild(item);
                });

                results.classList.remove('hidden');

            } catch (error) {

                console.error(error);

                results.innerHTML = `
                    <div class="px-4 py-4 text-sm text-red-600">
                        No fue posible realizar la búsqueda.
                    </div>
                `;

                results.classList.remove('hidden');
            }

        }, 250);
    });

    // Cerrar resultados al hacer clic afuera.
    document.addEventListener('click', function (event) {

        if (
            !results.contains(event.target) &&
            event.target !== searchInput
        ) {
            results.classList.add('hidden');
        }
    });

    // Evita inyectar HTML desde nombres/códigos de productos.
    function escapeHtml(value) {

        const div = document.createElement('div');

        div.textContent = value ?? '';

        return div.innerHTML;
    }

});
</script>

@endsection
