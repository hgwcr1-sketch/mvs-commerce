@extends('layouts.app')

@section('title', 'Conteo - ' . ($inventoryCount->reference ?? 'Toma #' . $inventoryCount->id))

@section('content')
<div class="mx-auto max-w-4xl space-y-6" data-responsive="360 768 1280">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">
                {{ $inventoryCount->isDraft() ? 'Nueva Toma' : ($inventoryCount->isCounting() ? 'En Conteo' : 'Revisión') }}
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Ref: {{ $inventoryCount->reference ?? '—' }} | Sucursal: {{ $inventoryCount->branch->name }}
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('inventory-counts.index') }}"
               class="shrink-0 rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                ← Listado
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    {{-- Search / Add Product --}}
    @if($inventoryCount->canBeEdited())
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <form id="add-product-form" class="flex flex-wrap gap-3">
            <div class="relative min-w-0 flex-1 basis-full sm:basis-0">
                <input type="text"
                       id="product-search"
                       name="search"
                       autocomplete="off"
                       placeholder="Escanear código, buscar nombre o código interno..."
                       class="w-full rounded-xl border border-slate-300 px-4 py-3 pr-11 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <div id="search-results"
                     class="absolute left-0 right-0 top-full z-50 mt-1 hidden max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                </div>
            </div>
            <button type="button" x-data @click="$dispatch('mvs-scanner-open')"
                    class="min-h-11 rounded-xl border border-slate-300 px-4 font-semibold text-slate-700"
                    aria-label="Abrir cámara para escanear producto">
                Cámara
            </button>
            <button type="submit"
                    class="min-h-11 rounded-xl bg-indigo-600 px-5 font-semibold text-white hover:bg-indigo-700">
                Agregar
            </button>
        </form>
        <p class="mt-2 text-xs text-slate-500">
            💡 Escanea con pistola, cámara o escribe el código. Productos ya agregados se actualizan.
        </p>
    </div>
    @endif

    {{-- Items List --}}
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-slate-200 px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h2 class="font-semibold text-slate-800">
                Productos ({{ $items->count() }})
            </h2>
            <div class="flex gap-2 text-xs">
                <span class="text-slate-500">Teórica: <strong>{{ $items->sum(fn($i) => $i->theoretical_quantity) }}</strong></span>
                <span class="text-amber-600">Contada: <strong>{{ $items->whereNotNull('counted_quantity')->sum(fn($i) => $i->counted_quantity) }}</strong></span>
                <span class="{{ $items->where('difference', '!=', 0)->count() > 0 ? 'text-red-600' : 'text-slate-500' }}">
                    Diferencia: <strong>{{ $items->where('difference', '!=', 0)->count() }}</strong>
                </span>
            </div>
        </div>

        @if($items->isEmpty())
        <div class="px-6 py-12 text-center text-slate-400">
            {{ $inventoryCount->isDraft() ? 'Agrega productos para comenzar el conteo.' : 'Sin productos registrados.' }}
        </div>
        @else
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-slate-600">Producto</th>
                        <th class="px-4 py-3 text-center text-sm font-semibold text-slate-600">Teórica</th>
                        <th class="px-4 py-3 text-center text-sm font-semibold text-slate-600">Contada</th>
                        <th class="px-4 py-3 text-center text-sm font-semibold text-slate-600">Reconteo</th>
                        <th class="px-4 py-3 text-center text-sm font-semibold text-slate-600">Diferencia</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-slate-600">Notas</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @foreach($items as $item)
                    <tr class="hover:bg-slate-50 {{ $item->hasDifference() ? 'bg-amber-50/50' : '' }}">
                        <td class="px-4 py-3">
                            <div class="font-medium text-slate-800">{{ $item->product->name }}</div>
                            <div class="text-xs text-slate-500">
                                {{ $item->product->internal_code }}
                                @if($item->product->barcode) · {{ $item->product->barcode }} @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-slate-700">
                            {{ number_format((float) $item->theoretical_quantity, 4) }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($item->counted_quantity !== null)
                                <span class="font-semibold text-slate-800">{{ number_format((float) $item->counted_quantity, 4) }}</span>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($item->recount_quantity !== null)
                                <span class="font-semibold text-indigo-700">{{ number_format((float) $item->recount_quantity, 4) }}</span>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($item->difference != 0)
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $item->isShortage() ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">
                                    {{ $item->isShortage() ? 'Faltante' : 'Sobrante' }}
                                    {{ number_format(abs((float) $item->difference), 4) }}
                                </span>
                            @else
                                <span class="text-slate-400">0</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600 max-w-24 truncate">
                            {{ $item->notes ?? '—' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- Actions --}}
    @if($inventoryCount->canBeEdited())
    <div class="flex flex-wrap gap-3">
        @can('inventario.conteo.contar')
        <form method="POST" action="{{ route('inventory-counts.update', $inventoryCount) }}">
            @csrf @method('PUT')
            <button type="submit"
                    class="rounded-xl bg-amber-500 px-5 py-2.5 font-semibold text-white hover:bg-amber-600">
                Guardar Borrador
            </button>
        </form>
        @endcan

        @can('inventario.conteo.revisar')
        @if($inventoryCount->isCounting() && $items->whereNotNull('counted_quantity')->count() > 0)
        <form method="POST" action="{{ route('inventory-counts.review', $inventoryCount) }}">
            @csrf
            <button class="rounded-xl bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-700">
                Enviar a Revisión
            </button>
        </form>
        @endif
        @endcan

        @can('inventario.conteo.cancelar')
        <form method="POST" action="{{ route('inventory-counts.cancel', $inventoryCount) }}" onclick="return confirm('¿Cancelar esta toma?')">
            @csrf
            <button class="rounded-xl border border-red-300 bg-red-50 px-5 py-2.5 font-semibold text-red-700 hover:bg-red-100">
                Cancelar
            </button>
        </form>
        @endcan
    </div>
    @endif

    {{-- Scanner Camera Component --}}
    <x-scanner.mvs-scanner />
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('add-product-form');
    const searchInput = document.getElementById('product-search');
    const results = document.getElementById('search-results');

    if (!form || !searchInput) return;

    let timer;

    window.addEventListener('mvs-scan', function (event) {
        const code = String(event?.detail?.code ?? '').trim();
        if (!code) return;
        searchInput.value = code;
        addProduct(null, code);
        searchInput.focus();
    });

    // Search autocomplete
    searchInput.addEventListener('input', function () {
        clearTimeout(timer);
        const term = this.value.trim();
        if (term.length < 2) { results.classList.add('hidden'); return; }
        timer = setTimeout(() => searchProduct(term), 250);
    });

    async function searchProduct(term) {
        try {
            const response = await fetch(`{{ route('productos.search') }}?q=${encodeURIComponent(term)}`, {
                headers: { 'Accept': 'application/json' }
            });
            const products = await response.json();
            results.innerHTML = '';
            if (products.length === 0) {
                results.innerHTML = '<div class="px-4 py-3 text-sm text-slate-500">Sin resultados</div>';
                results.classList.remove('hidden');
                return;
            }
            products.forEach(p => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'block w-full border-b border-slate-100 px-4 py-3 text-left hover:bg-indigo-50';
                btn.innerHTML = `<div class="font-semibold text-slate-800">${escapeHtml(p.name)}</div><div class="text-xs text-slate-500">${escapeHtml(p.internal_code || '')} · ${escapeHtml(p.barcode || '')}</div>`;
                btn.addEventListener('click', () => addProduct(p.id, term));
                results.appendChild(btn);
            });
            results.classList.remove('hidden');
        } catch (e) { results.classList.add('hidden'); }
    }

    async function addProduct(productId, searchTerm) {
        try {
            const response = await fetch(`{{ route('inventory-counts.add-item', $inventoryCount) }}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({ search: searchTerm })
            });
            const data = await response.json();
            if (data.exists) {
                alert('Producto ya agregado. Enfoca la línea existente.');
            } else {
                location.reload();
            }
        } catch (e) { alert('Error al agregar producto.'); }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const term = searchInput.value.trim();
        if (!term) return;
        addProduct(null, term);
    });

    // Close results on click outside
    document.addEventListener('click', function (e) {
        if (!results.contains(e.target) && e.target !== searchInput) results.classList.add('hidden');
    });

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }
});
</script>
@endsection
