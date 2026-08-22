@extends('layouts.app')

@section('title', 'Nueva Transferencia')

@section('content')

<div class="mx-auto max-w-4xl">

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">
            Nueva Transferencia
        </h1>

        <p class="mt-1 text-sm text-slate-500">
            Transfiere inventario desde la sucursal activa hacia otra sucursal.
        </p>
    </div>

    @if($errors->any())
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4">
            <ul class="list-disc pl-5 text-sm text-red-700">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">

        <form
            method="POST"
            action="{{ route('transferencias.store') }}">

            @csrf

            <div class="space-y-6">

                {{-- SUCURSAL DESTINO --}}
                <div>

                    <label class="mb-2 block text-sm font-semibold text-slate-700">
                        Sucursal destino *
                    </label>

                    <select
                        name="to_branch_id"
                        required
                        class="w-full rounded-xl border border-slate-300 px-4 py-3">

                        <option value="">
                            Seleccione sucursal...
                        </option>

                        @foreach($branches as $branch)

                            <option
                                value="{{ $branch->id }}"
                                @selected(old('to_branch_id') == $branch->id)>

                                {{ $branch->name }}

                            </option>

                        @endforeach

                    </select>

                </div>

                {{-- PRODUCTO --}}
                <div class="relative">

                    <label class="mb-2 block text-sm font-semibold text-slate-700">
                        Buscar producto *
                    </label>

                    <input
                        type="text"
                        id="product_search"
                        autocomplete="off"
                        placeholder="Escriba nombre, código o escanee código de barras..."
                        class="w-full rounded-xl border border-slate-300 px-4 py-3">

                    <input
                        type="hidden"
                        name="product_id"
                        id="product_id"
                        value="{{ old('product_id') }}">

                    {{-- COINCIDENCIAS --}}
                    <div
                        id="product_results"
                        class="absolute left-0 right-0 z-50 mt-1 hidden max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                    </div>

                    {{-- SELECCIONADO --}}
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

                </div>

                {{-- CANTIDAD --}}
                <div>

                    <label class="mb-2 block text-sm font-semibold text-slate-700">
                        Cantidad a transferir *
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

                </div>

                {{-- NOTAS --}}
                <div>

                    <label class="mb-2 block text-sm font-semibold text-slate-700">
                        Notas
                    </label>

                    <textarea
                        name="notes"
                        rows="3"
                        placeholder="Observaciones de la transferencia..."
                        class="w-full rounded-xl border border-slate-300 px-4 py-3">{{ old('notes') }}</textarea>

                </div>

            </div>

            <div class="mt-8 flex justify-end gap-3">

                <a
                    href="{{ route('transferencias.index') }}"
                    class="rounded-xl border border-slate-300 px-6 py-3 font-semibold text-slate-700 hover:bg-slate-50">

                    Cancelar

                </a>

                <button
                    type="submit"
                    class="rounded-xl bg-amber-500 px-6 py-3 font-semibold text-white hover:bg-amber-600">

                    Realizar Transferencia

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

    const selectedBox =
        document.getElementById('selected_product');

    const selectedName =
        document.getElementById('selected_product_name');
    const quantityInput = document.getElementById('quantity');

    let timer;

    searchInput.addEventListener('input', function () {

        clearTimeout(timer);

        const search = this.value.trim();

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
                    throw new Error('Error de búsqueda');
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

                    const item =
                        document.createElement('button');

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
                        No fue posible buscar productos.
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
