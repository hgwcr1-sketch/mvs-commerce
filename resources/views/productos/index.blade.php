@extends('layouts.app')

@section('title', 'Productos')

@section('description', 'Administración de productos')

@section('content')

<div class="space-y-6" x-data="productosPage()" x-init="init()">

    {{-- ENCABEZADO --}}
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-slate-800 sm:text-2xl">Productos</h1>
        <a href="{{ route('productos.create') }}"
           class="shrink-0 rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-amber-600">
            + Nuevo
        </a>
    </div>

    {{-- TARJETAS ESTADÍSTICAS --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-medium text-slate-500">Total</p>
            <p class="mt-1 text-2xl font-bold text-slate-800 sm:text-3xl">{{ $totalProducts }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-medium text-slate-500">Activos</p>
            <p class="mt-1 text-2xl font-bold text-green-600 sm:text-3xl">{{ $activeProducts }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-medium text-slate-500">Stock bajo</p>
            <p class="mt-1 text-2xl font-bold text-amber-500 sm:text-3xl">{{ $lowStockProducts }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-medium text-slate-500">Sin existencias</p>
            <p class="mt-1 text-2xl font-bold text-red-600 sm:text-3xl">{{ $outOfStockProducts }}</p>
        </div>
    </div>

    {{-- BUSCADOR + CÁMARA --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <form method="GET" action="{{ route('productos.index') }}" class="space-y-3">
            <div class="flex gap-2">
                <div class="relative flex-1">
                    <input
                        id="product-search"
                        type="text"
                        name="search"
                        value="{{ request('search') }}"
                        placeholder="Nombre, código o barras..."
                        autocomplete="off"
                        class="w-full rounded-xl border border-slate-300 px-4 py-3 pr-11 text-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">
                    <div
                        id="product-search-results"
                        class="absolute left-0 right-0 top-full z-50 mt-1 hidden max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                    </div>
                </div>
                @if(isset($scannerAvailable) ? $scannerAvailable : true)
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
                @endif
                <button type="submit"
                        class="flex h-[46px] shrink-0 items-center rounded-xl bg-amber-500 px-5 text-sm font-semibold text-white shadow-sm hover:bg-amber-600">
                    Buscar
                </button>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <select name="category" class="form-select w-full text-sm sm:w-auto">
                    <option value="">Todas las categorías</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected(request('category') == $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
                <select name="brand" class="form-select w-full text-sm sm:w-auto">
                    <option value="">Todas las marcas</option>
                    @foreach($brands as $brand)
                        <option value="{{ $brand->id }}" @selected(request('brand') == $brand->id)">
                            {{ $brand->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>

    {{-- TABLA (desktop) --}}
    <div class="hidden overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm md:block">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">Imagen</th>
                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">Código</th>
                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">Producto</th>
                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">Categoría</th>
                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">Marca</th>
                        <th class="px-5 py-4 text-right text-sm font-semibold text-slate-600">Costo</th>
                        <th class="px-5 py-4 text-right text-sm font-semibold text-slate-600">Precio</th>
                        <th class="px-5 py-4 text-center text-sm font-semibold text-slate-600">Stock</th>
                        <th class="px-5 py-4 text-center text-sm font-semibold text-slate-600">Estado</th>
                        <th class="px-5 py-4 text-center text-sm font-semibold text-slate-600">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse($products as $product)
                        <tr class="hover:bg-slate-50">
                            <td class="px-5 py-4">
                                @if($product->image)
                                    <img src="{{ asset('storage/'.$product->image) }}" class="h-10 w-10 rounded-lg object-cover">
                                @else
                                    <div class="h-10 w-10 rounded-lg bg-slate-200"></div>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-sm text-slate-600">{{ $product->internal_code }}</td>
                            <td class="px-5 py-4 font-medium text-slate-800">{{ $product->name }}</td>
                            <td class="px-5 py-4 text-sm text-slate-600">{{ $product->category->name ?? '-' }}</td>
                            <td class="px-5 py-4 text-sm text-slate-600">{{ $product->brand->name ?? '-' }}</td>
                            <td class="px-5 py-4 text-right text-sm text-slate-600">₡ {{ number_format($product->cost, 2) }}</td>
                            <td class="px-5 py-4 text-right text-sm font-semibold text-slate-800">₡ {{ number_format($product->sale_price, 2) }}</td>
                            <td class="px-5 py-4 text-center text-sm font-bold text-slate-800">{{ number_format($product->branch_stock, 2) }}</td>
                            <td class="px-5 py-4 text-center">
                                @if($product->is_active)
                                    <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700">Activo</span>
                                @else
                                    <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">Inactivo</span>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-center">
                                <div class="flex justify-center gap-2">
                                    <a href="{{ route('productos.proveedores.index', $product) }}" class="rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-sky-700">Proveedores</a>
                                    <a href="{{ route('productos.edit', $product) }}" class="rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-600">Editar</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-6 py-12 text-center text-slate-400">
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
                <div class="flex items-start gap-3">
                    @if($product->image)
                        <img src="{{ asset('storage/'.$product->image) }}" class="h-14 w-14 shrink-0 rounded-xl object-cover">
                    @else
                        <div class="h-14 w-14 shrink-0 rounded-xl bg-slate-200"></div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold text-slate-800">{{ $product->name }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ $product->internal_code }}@if($product->barcode) · {{ $product->barcode }}@endif</p>
                        <p class="mt-1 text-xs text-slate-500">{{ $product->category->name ?? '-' }}@if($product->brand->name) · {{ $product->brand->name }}@endif</p>
                    </div>
                    @if($product->is_active)
                        <span class="shrink-0 rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-semibold text-green-700">Activo</span>
                    @else
                        <span class="shrink-0 rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-700">Inactivo</span>
                    @endif
                </div>
                <div class="mt-3 grid grid-cols-3 gap-2 border-t border-slate-100 pt-3">
                    <div class="text-center">
                        <p class="text-[10px] font-medium uppercase text-slate-400">Costo</p>
                        <p class="text-sm font-bold text-slate-700">₡ {{ number_format($product->cost, 0) }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-[10px] font-medium uppercase text-slate-400">Precio</p>
                        <p class="text-sm font-bold text-amber-600">₡ {{ number_format($product->sale_price, 0) }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-[10px] font-medium uppercase text-slate-400">Stock</p>
                        <p class="text-sm font-bold text-slate-800">{{ number_format($product->branch_stock, 2) }}</p>
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <a href="{{ route('productos.proveedores.index', $product) }}" class="flex-1 rounded-xl bg-sky-600 py-2.5 text-center text-xs font-semibold text-white">Proveedores</a>
                    <a href="{{ route('productos.edit', $product) }}" class="flex-1 rounded-xl bg-amber-500 py-2.5 text-center text-xs font-semibold text-white">Editar</a>
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

    const searchInput = document.getElementById('product-search');
    const results = document.getElementById('product-search-results');

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
                    item.innerHTML = `
                        <div class="font-semibold text-slate-800">${escapeHtml(product.name)}</div>
                        <div class="mt-1 text-xs text-slate-500">
                            ${escapeHtml(product.internal_code ?? '')}
                            ${product.barcode ? ' · ' + escapeHtml(product.barcode) : ''}
                            ${product.sale_price != null ? ' · ₡' + Number(product.sale_price).toLocaleString() : ''}
                            ${product.branch_stock != null ? ' · Stock: ' + Number(product.branch_stock).toLocaleString() : ''}
                        </div>`;
                    item.addEventListener('click', function () {
                        window.location.href = `{{ route('productos.index') }}?search=${encodeURIComponent(product.internal_code)}`;
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
function productosPage() {
    return {
        cameraAvailable: false,
        init() {
            this.cameraAvailable = window.mvsScannerAvailable === true;
        }
    };
}
</script>

@endsection
