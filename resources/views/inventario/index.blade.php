@extends('layouts.app')

@section('title', 'Inventario')

@section('description', 'Inventario de la sucursal activa')

@section('content')

<div class="space-y-6" x-data="inventarioPage()" x-init="init()">

    {{-- ENCABEZADO --}}
    <div class="flex items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-slate-800 sm:text-2xl">Inventario</h1>
            <p class="mt-0.5 text-xs text-slate-500 sm:text-sm">Sucursal activa</p>
        </div>
        <a href="{{ route('importaciones.inventario') }}"
           class="shrink-0 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
            Importar
        </a>
    </div>

    {{-- BUSCADOR + CÁMARA --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div class="flex gap-2">
            <div class="relative flex-1">
                <input
                    type="text"
                    id="product_search"
                    autocomplete="off"
                    value="{{ request('search') }}"
                    placeholder="Producto, código o escanee código de barras..."
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 pr-11 text-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">
                <div
                    id="product_results"
                    class="absolute left-0 right-0 top-full z-50 mt-1 hidden max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                </div>
            </div>
            <button type="button"
                    x-show="cameraAvailable"
                    x-cloak
                    @click="$dispatch('mvs-scanner-open')"
                    class="flex h-[46px] w-[46px] shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-600 shadow-sm hover:bg-slate-50"
                    aria-label="Escanear código con cámara">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/>
                    <path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/>
                    <line x1="7" y1="12" x2="17" y2="12"/><line x1="7" y1="8" x2="17" y2="8"/>
                    <line x1="7" y1="16" x2="17" y2="16"/>
                </svg>
            </button>
        </div>

        @if(request('search'))
            <div class="mt-3">
                <a href="{{ route('inventario.index') }}"
                   class="text-sm font-semibold text-amber-600 hover:text-amber-700">
                    Limpiar búsqueda
                </a>
            </div>
        @endif
    </div>

    {{-- TABLA (desktop) --}}
    <div class="hidden overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm md:block">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">Código</th>
                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">Producto</th>
                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">Categoría</th>
                        <th class="px-5 py-4 text-center text-sm font-semibold text-slate-600">Stock</th>
                        <th class="px-5 py-4 text-center text-sm font-semibold text-slate-600">Mínimo</th>
                        <th class="px-5 py-4 text-center text-sm font-semibold text-slate-600">Máximo</th>
                        <th class="px-5 py-4 text-center text-sm font-semibold text-slate-600">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse($products as $product)
                        <tr class="hover:bg-slate-50">
                            <td class="px-5 py-4 text-sm text-slate-600">{{ $product->internal_code }}</td>
                            <td class="px-5 py-4 font-semibold text-slate-800">{{ $product->name }}</td>
                            <td class="px-5 py-4 text-sm text-slate-600">{{ $product->category->name ?? '-' }}</td>
                            <td class="px-5 py-4 text-center text-lg font-bold text-slate-800">{{ number_format($product->branch_stock, 2) }}</td>
                            <td class="px-5 py-4 text-center text-sm text-slate-600">{{ number_format($product->branch_minimum_stock, 2) }}</td>
                            <td class="px-5 py-4 text-center text-sm text-slate-600">{{ number_format($product->branch_maximum_stock, 2) }}</td>
                            <td class="px-5 py-4 text-center">
                                @if($product->branch_stock <= 0)
                                    <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">Sin existencias</span>
                                @elseif($product->branch_minimum_stock > 0 && $product->branch_stock <= $product->branch_minimum_stock)
                                    <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">Stock bajo</span>
                                @else
                                    <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700">Disponible</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-slate-400">
                                No hay productos registrados.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- TARJETAS MÓVILES --}}
    <div class="space-y-3 md:hidden">
        @forelse($products as $product)
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold text-slate-800">{{ $product->name }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ $product->internal_code }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ $product->category->name ?? '-' }}</p>
                    </div>
                    @if($product->branch_stock <= 0)
                        <span class="shrink-0 rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-700">Sin stock</span>
                    @elseif($product->branch_minimum_stock > 0 && $product->branch_stock <= $product->branch_minimum_stock)
                        <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Stock bajo</span>
                    @else
                        <span class="shrink-0 rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-semibold text-green-700">Disponible</span>
                    @endif
                </div>
                <div class="mt-3 grid grid-cols-3 gap-2 border-t border-slate-100 pt-3">
                    <div class="text-center">
                        <p class="text-[10px] font-medium uppercase text-slate-400">Stock</p>
                        <p class="text-lg font-bold text-slate-800">{{ number_format($product->branch_stock, 2) }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-[10px] font-medium uppercase text-slate-400">Mínimo</p>
                        <p class="text-sm font-semibold text-slate-600">{{ number_format($product->branch_minimum_stock, 2) }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-[10px] font-medium uppercase text-slate-400">Máximo</p>
                        <p class="text-sm font-semibold text-slate-600">{{ number_format($product->branch_maximum_stock, 2) }}</p>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-slate-400 shadow-sm">
                No hay productos registrados.
            </div>
        @endforelse
    </div>

    @if($products->hasPages())
        <div class="flex justify-center">
            {{ $products->links() }}
        </div>
    @endif

    {{-- R03: hoja del escáner por cámara (reutiliza R02). --}}
    <x-scanner.mvs-scanner />

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('product_search');
    const results = document.getElementById('product_results');

    if (!searchInput || !results) return;

    let timer;

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }

    function doSearch(term) {
        clearTimeout(timer);
        if (!term) { results.innerHTML = ''; results.classList.add('hidden'); return; }
        timer = setTimeout(async function () {
            try {
                const response = await fetch(
                    `{{ route('productos.search') }}?q=${encodeURIComponent(term)}`,
                    { headers: { 'Accept': 'application/json' } }
                );
                if (!response.ok) throw new Error('Error al buscar');
                const products = await response.json();
                results.innerHTML = '';
                if (products.length === 0) {
                    results.innerHTML = '<div class="px-4 py-4 text-sm text-slate-500">No se encontraron coincidencias.</div>';
                    results.classList.remove('hidden');
                    return;
                }
                products.forEach(function (product) {
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'block w-full border-b border-slate-100 px-4 py-3 text-left hover:bg-amber-50';
                    const stockLabel = product.branch_stock != null ? ` · Stock: ${Number(product.branch_stock).toLocaleString()}` : '';
                    item.innerHTML = `
                        <div class="font-semibold text-slate-800">${escapeHtml(product.name)}</div>
                        <div class="mt-1 text-xs text-slate-500">
                            ${escapeHtml(product.internal_code ?? '')}
                            ${product.barcode ? ' · ' + escapeHtml(product.barcode) : ''}
                            ${stockLabel}
                        </div>`;
                    item.addEventListener('click', function () {
                        window.location.href = `{{ route('inventario.index') }}?search=${encodeURIComponent(product.internal_code)}`;
                    });
                    results.appendChild(item);
                });
                results.classList.remove('hidden');
            } catch (error) {
                console.error(error);
                results.innerHTML = '<div class="px-4 py-4 text-sm text-red-600">Error al buscar productos.</div>';
                results.classList.remove('hidden');
            }
        }, 250);
    }

    searchInput.addEventListener('input', function () { doSearch(this.value.trim()); });

    document.addEventListener('click', function (event) {
        if (event.target !== searchInput && !results.contains(event.target)) {
            results.classList.add('hidden');
        }
    });

    // R03: escuchar lectura del escáner por cámara.
    window.addEventListener('mvs-scan', function (event) {
        const code = String(event?.detail?.code ?? '').trim();
        if (!code) return;
        searchInput.value = code;
        doSearch(code);
        searchInput.focus();
    });

});
</script>

<script>
function inventarioPage() {
    return {
        cameraAvailable: false,
        init() {
            this.cameraAvailable = window.mvsScannerAvailable === true;
        }
    };
}
</script>

@endsection
