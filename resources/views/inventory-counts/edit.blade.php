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

    @if(session('warning'))
        <div class="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{{ session('warning') }}</div>
    @endif

    {{-- Search / Add Product --}}
    @if($inventoryCount->canBeEdited())
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <form id="add-product-form" class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <div class="relative min-w-0 flex-1">
                <input type="text"
                       id="product-search"
                       name="search"
                       autocomplete="off"
                       placeholder="Escanear o buscar producto..."
                       class="w-full rounded-xl border border-slate-300 px-4 py-3 pr-11 text-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">
                <div id="search-results"
                     class="absolute left-0 right-0 top-full z-50 mt-1 hidden max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                </div>
            </div>
            <div class="flex gap-3 sm:shrink-0">
                <button type="button" x-data @click="$dispatch('mvs-scanner-open')"
                        class="min-h-11 flex-1 sm:flex-none rounded-xl border border-slate-300 px-4 font-semibold text-slate-700 sm:min-w-[5rem]"
                        aria-label="Abrir cámara para escanear producto">
                    Cámara
                </button>
                <button type="submit"
                        class="min-h-11 flex-1 sm:flex-none rounded-xl bg-amber-500 px-5 font-semibold text-black hover:bg-amber-600 sm:min-w-[5rem]">
                    Agregar
                </button>
            </div>
        </form>
        <p class="mt-2 text-xs text-slate-500">
            Escanea con pistola, cámara o escribe el código. Productos ya agregados se actualizan.
        </p>
    </div>
    @endif

    {{-- Mobile: Product Cards --}}
    @if($inventoryCount->canBeEdited() && $items->isNotEmpty())
    <div class="md:hidden space-y-3">
        @foreach($items as $item)
        <div class="rounded-2xl border {{ $item->hasDifference() ? 'border-amber-300 bg-amber-50/50' : 'border-slate-200 bg-white' }} p-4 shadow-sm" data-item-id="{{ $item->id }}">
            <div class="mb-3">
                <div class="font-semibold text-slate-800">{{ $item->product->name }}</div>
                <div class="text-xs text-slate-500">
                    {{ $item->product->internal_code }}
                    @if($item->product->barcode) · {{ $item->product->barcode }} @endif
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 text-sm mb-3">
                <div>
                    <span class="text-slate-500">Sistema:</span>
                    <span class="ml-1 font-semibold text-slate-700">{{ number_format((float) $item->theoretical_quantity, 4) }}</span>
                </div>
                <div>
                    <span class="text-slate-500">Contada:</span>
                    @if($item->counted_quantity !== null)
                        <span class="ml-1 font-semibold text-slate-800">{{ number_format((float) $item->counted_quantity, 4) }}</span>
                    @else
                        <span class="ml-1 text-slate-400">—</span>
                    @endif
                </div>
                @if($item->recount_quantity !== null)
                <div>
                    <span class="text-slate-500">Reconteo:</span>
                    <span class="ml-1 font-semibold text-[#B1922D]">{{ number_format((float) $item->recount_quantity, 4) }}</span>
                </div>
                @endif
                <div>
                    @if($item->difference != 0)
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $item->isShortage() ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">
                            {{ $item->isShortage() ? 'Faltante' : 'Sobrante' }}
                            {{ number_format(abs((float) $item->difference), 4) }}
                        </span>
                    @else
                        <span class="text-slate-400 text-xs">Sin diferencia</span>
                    @endif
                </div>
            </div>

            <div class="space-y-2">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">Cantidad física</label>
                    <input type="text"
                           inputmode="decimal"
                           value="{{ $item->counted_quantity !== null ? number_format((float) $item->counted_quantity, 4, '.', '') : '' }}"
                           data-item-id="{{ $item->id }}"
                           data-theoretical="{{ $item->theoretical_quantity }}"
                           class="qty-input w-full rounded-xl border border-slate-300 px-4 py-3 text-base font-semibold text-slate-800 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500"
                           placeholder="0.0000">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">Notas</label>
                    <input type="text"
                           maxlength="500"
                           value="{{ $item->notes ?? '' }}"
                           data-item-id="{{ $item->id }}"
                           data-original="{{ $item->notes ?? '' }}"
                           class="notes-input w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-700 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500"
                           placeholder="Opcional">
                </div>
            </div>

            <div class="mt-3 flex gap-2">
                <button type="button"
                        data-save-qty
                        data-item-id="{{ $item->id }}"
                        class="save-qty-btn min-h-11 flex-1 rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-semibold text-black hover:bg-amber-600">
                    Guardar
                </button>
            </div>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Desktop: Items Table --}}
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
        <div class="hidden md:block overflow-x-auto">
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
                            @if($inventoryCount->canBeEdited())
                            <div class="flex items-center justify-center gap-1">
                                <input type="text"
                                       inputmode="decimal"
                                       value="{{ $item->counted_quantity !== null ? number_format((float) $item->counted_quantity, 4, '.', '') : '' }}"
                                       data-item-id="{{ $item->id }}"
                                       class="qty-input-desktop w-24 rounded-lg border border-slate-300 px-2 py-1.5 text-center text-sm font-semibold text-slate-800 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                                       placeholder="0.0000">
                                <button type="button"
                                        data-save-desktop
                                        data-item-id="{{ $item->id }}"
                                        class="shrink-0 rounded-lg bg-amber-500 px-2 py-1.5 text-xs font-semibold text-black hover:bg-amber-600">
                                    Guardar
                                </button>
                            </div>
                            @else
                                @if($item->counted_quantity !== null)
                                    <span class="font-semibold text-slate-800">{{ number_format((float) $item->counted_quantity, 4) }}</span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($item->recount_quantity !== null)
                                <span class="font-semibold text-[#B1922D]">{{ number_format((float) $item->recount_quantity, 4) }}</span>
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
                    class="rounded-xl bg-amber-500 px-5 py-2.5 font-semibold text-black hover:bg-amber-600">
                Guardar Borrador
            </button>
        </form>
        @endcan

        @can('inventario.conteo.revisar')
        @if($inventoryCount->isCounting() && $items->whereNotNull('counted_quantity')->count() > 0)
        <form method="POST" action="{{ route('inventory-counts.review', $inventoryCount) }}">
            @csrf
            <button class="rounded-xl bg-amber-500 px-5 py-2.5 font-semibold text-black hover:bg-amber-600">
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
    let selectedProductId = null;

    window.addEventListener('mvs-scan', function (event) {
        const code = String(event?.detail?.code ?? '').trim();
        if (!code) return;
        searchInput.value = code;
        selectedProductId = null;
        addProduct(null, code);
        searchInput.focus();
    });

    searchInput.addEventListener('input', function () {
        clearTimeout(timer);
        selectedProductId = null;
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
                btn.className = 'block w-full border-b border-slate-100 px-4 py-3 text-left hover:bg-amber-50';
                btn.innerHTML = `<div class="font-semibold text-slate-800">${escapeHtml(p.name)}</div><div class="text-xs text-slate-500">${escapeHtml(p.internal_code || '')} · ${escapeHtml(p.barcode || '')}</div>`;
                btn.addEventListener('click', () => {
                    selectedProductId = p.id;
                    searchInput.value = p.name;
                    results.classList.add('hidden');
                    addProduct(p.id, p.name);
                });
                results.appendChild(btn);
            });
            results.classList.remove('hidden');
        } catch (e) { results.classList.add('hidden'); }
    }

    async function addProduct(productId, searchTerm) {
        try {
            const payload = {};
            if (productId) {
                payload.product_id = productId;
            } else if (searchTerm) {
                payload.search = searchTerm;
            } else {
                return;
            }

            const response = await fetch(`{{ route('inventory-counts.add-item', $inventoryCount) }}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                let msg = 'Error al agregar producto.';
                try {
                    const errData = await response.json();
                    msg = errData?.message || errData?.errors?.search?.[0] || msg;
                } catch (_) {}
                alert(msg);
                return;
            }

            const data = await response.json();
            if (data.exists) {
                alert('Producto ya agregado. Enfoca la línea existente.');
            } else {
                location.reload();
            }
        } catch (e) {
            alert('Error de red al agregar producto.');
        }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const term = searchInput.value.trim();
        if (!term) return;
        addProduct(selectedProductId, term);
        selectedProductId = null;
    });

    document.addEventListener('click', function (e) {
        if (!results.contains(e.target) && e.target !== searchInput) results.classList.add('hidden');
    });

    // ─── Shared: save counted quantity ───
    async function saveCountedQuantity(itemId, qtyValue) {
        const response = await fetch(`{{ url('tomas-inventario') }}/{{ $inventoryCount->id }}/items/${itemId}/cantidad`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ counted_quantity: qtyValue })
        });

        if (!response.ok) {
            let msg = 'Error al guardar cantidad.';
            try {
                const errData = await response.json();
                msg = errData?.message || errData?.errors?.counted_quantity?.[0] || msg;
            } catch (_) {}
            throw new Error(msg);
        }

        return await response.json();
    }

    // ─── Mobile: save per card ───
    document.querySelectorAll('[data-save-qty]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const itemId = this.dataset.itemId;
            const card = this.closest('[data-item-id]');
            const qtyInput = card.querySelector('.qty-input');
            const notesInput = card.querySelector('.notes-input');
            const qty = qtyInput.value.trim();
            const notes = notesInput.value.trim();

            if (!qty) { qtyInput.focus(); return; }

            try {
                await saveCountedQuantity(itemId, qty);

                if (notes !== (notesInput.dataset.original || '')) {
                    const res = await fetch(`{{ url('tomas-inventario') }}/{{ $inventoryCount->id }}/items/${itemId}/notas`, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({ notes: notes || null })
                    });
                    if (!res.ok) {
                        let noteMsg = 'Error al guardar notas.';
                        try { const e = await res.json(); noteMsg = e?.message || noteMsg; } catch (_) {}
                        throw new Error(noteMsg);
                    }
                }

                location.reload();
            } catch (e) {
                alert(e.message);
            }
        });
    });

    // ─── Desktop: save per row ───
    document.querySelectorAll('[data-save-desktop]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const itemId = this.dataset.itemId;
            const row = this.closest('tr');
            const qtyInput = row.querySelector('.qty-input-desktop');
            const qty = qtyInput.value.trim();

            if (!qty) { qtyInput.focus(); return; }

            try {
                await saveCountedQuantity(itemId, qty);
                location.reload();
            } catch (e) {
                alert(e.message);
            }
        });
    });

    // ─── Desktop: save on Enter key ───
    document.querySelectorAll('.qty-input-desktop').forEach(function (input) {
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const itemId = this.dataset.itemId;
                const row = this.closest('tr');
                const saveBtn = row.querySelector('[data-save-desktop]');
                if (saveBtn) saveBtn.click();
            }
        });
    });

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }
});
</script>
@endsection
