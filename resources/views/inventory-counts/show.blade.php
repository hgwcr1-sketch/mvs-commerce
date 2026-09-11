@extends('layouts.app')

@section('title', 'Toma de Inventario - ' . ($inventoryCount->reference ?? 'Toma #' . $inventoryCount->id))

@section('content')
<div class="mx-auto max-w-4xl space-y-6" data-responsive="360 768 1280">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">
                @if($inventoryCount->isDraft())
                Nueva Toma
                @elseif($inventoryCount->isCounting())
                En Conteo
                @elseif($inventoryCount->isReview())
                Revisión
                @elseif($inventoryCount->isConfirmed())
                Confirmada
                @elseif($inventoryCount->isCancelled())
                Cancelada
                @endif
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

    {{-- Inventory Count Details --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <p class="font-semibold text-slate-700">Estado</p>
                <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $inventoryCount->status === 'confirmed' ? 'bg-emerald-100 text-emerald-700' : ($inventoryCount->status === 'counting' ? 'bg-amber-100 text-amber-700' : ($inventoryCount->status === 'review' ? 'bg-indigo-100 text-indigo-700' : ($inventoryCount->status === 'cancelled' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-700'))) }}">
                    {{ ucfirst($inventoryCount->status) }}
                </span>
            </div>
            <div>
                <p class="font-semibold text-slate-700">Conteo por</p>
                <span class="text-slate-600">{{ $inventoryCount->countedBy?->name ?? '—' }}</span>
            </div>
            <div>
                <p class="font-semibold text-slate-700">Fecha inicio</p>
                <span class="text-slate-600">{{ $inventoryCount->started_at?->format('d/m/Y H:i') ?? '—' }}</span>
            </div>
            <div>
                <p class="font-semibold text-slate-700">Fecha conclusión</p>
                <span class="text-slate-600">{{ $inventoryCount->confirmed_at?->format('d/m/Y H:i') ?? ($inventoryCount->isCancelled() ? $inventoryCount->cancelled_at?->format('d/m/Y H:i') : '—') }}</span>
            </div>
        </div>
    </div>

    {{-- Items List --}}
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-slate-200 px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h2 class="font-semibold text-slate-800">
                Productos ({{ $items->count() }})
            </h2>
            <div class="flex gap-2 text-xs">
                <span class="text-slate-500">Teórica: <strong>{{ number_format($items->sum(fn($i) => $i->theoretical_quantity), 4) }}</strong></span>
                <span class="text-amber-600">Contada: <strong>{{ $items->whereNotNull('counted_quantity')->sum(fn($i) => $i->counted_quantity) ?? '0.0000' }}</strong></span>
                <span class="{{ $items->where('difference', '!=', 0)->count() > 0 ? 'text-red-600' : 'text-slate-500' }}">
                    Diferencia: <strong>{{ $items->where('difference', '!=', 0)->count() }}</strong>
                </span>
            </div>
        </div>

        @if($items->isEmpty())
        <div class="px-6 py-12 text-center text-slate-400">
            @if($inventoryCount->isDraft())
            Agrega productos para comenzar el conteo.
            @elseif($inventoryCount->isCounting() || $inventoryCount->isReview())
            No hay productos registrados en esta toma.
            @endif
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
    <div class="flex flex-wrap gap-3">
        @can('inventario.conteo.contar')
        @if($inventoryCount->canBeEdited())
        <form method="POST" action="{{ route('inventory-counts.update', $inventoryCount) }}">
            @csrf @method('PUT')
            <button type="submit"
                    class="rounded-xl bg-amber-500 px-5 py-2.5 font-semibold text-white hover:bg-amber-600">
                Guardar Borrador
            </button>
        </form>
        @elseif($inventoryCount->isCounting())
        <a href="{{ route('inventory-counts.review', $inventoryCount) }}"
           class="rounded-xl bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-700">
            Enviar a Revisión
        </a>
        @endif
        @endcan

        @can('inventario.conteo.cancelar')
        @if($inventoryCount->canTransitionTo('cancelled'))
        <form method="POST" action="{{ route('inventory-counts.cancel', $inventoryCount) }}" onclick="return confirm('¿Cancelar esta toma?')">
            @csrf
            <button class="rounded-xl border border-red-300 bg-red-50 px-5 py-2.5 font-semibold text-red-700 hover:bg-red-100">
                Cancelar
            </button>
        </form>
        @endif
        @endcan

        @can('inventario.conteo.confirmar')
        @if($inventoryCount->isReview())
        <form method="POST" action="{{ route('inventory-counts.confirm', $inventoryCount) }}" onclick="return confirm('¿Confirmar esta toma de inventario? Se ajustará el stock y no se puede deshacer.')">
            @csrf
            <button class="rounded-xl bg-emerald-600 px-5 py-2.5 font-semibold text-white hover:bg-emerald-700">
                Confirmar
            </button>
        </form>
        @endif
        @endcan
    </div>
    </div>

    {{-- Manual Search Script --}}
    @if($inventoryCount->canBeEdited())
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('product-search');
            const addProductForm = document.getElementById('add-product-form');
            const resultsDiv = document.getElementById('search-results');

            if (!searchInput) return;

            let timer;

            // Search autocomplete
            searchInput.addEventListener('input', function () {
                clearTimeout(timer);
                const term = this.value.trim();
                if (term.length < 2) {
                    resultsDiv.classList.add('hidden');
                    return;
                }
                timer = setTimeout(() => searchProduct(term), 250);
            });

            async function searchProduct(term) {
                try {
                    const response = await fetch(`{{ route('productos.search') }}?q=${encodeURIComponent(term)}`, {
                        headers: { 'Accept': 'application/json' }
                    });
                    const products = await response.json();
                    resultsDiv.innerHTML = '';
                    if (products.length === 0) {
                        resultsDiv.innerHTML = '<div class="px-4 py-3 text-sm text-slate-500">Sin resultados</div>';
                        resultsDiv.classList.remove('hidden');
                        return;
                    }
                    products.forEach(p => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'block w-full border-b border-slate-100 px-4 py-3 text-left hover:bg-indigo-50';
                        btn.innerHTML = `<div class="font-semibold text-slate-800">${escapeHtml(p.name)}</div><div class="text-xs text-slate-500">${escapeHtml(p.internal_code || '')} · ${escapeHtml(p.barcode || '')}</div>`;
                        btn.addEventListener('click', () => addProduct(p.id, term));
                        resultsDiv.appendChild(btn);
                    });
                    resultsDiv.classList.remove('hidden');
                } catch (e) {
                    resultsDiv.classList.add('hidden');
                }
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
                } catch (e) {
                    alert('Error al agregar producto.');
                }
            }

            // Close results on click outside
            document.addEventListener('click', function (e) {
                if (!resultsDiv.contains(e.target) && e.target !== searchInput) resultsDiv.classList.add('hidden');
            });

            function escapeHtml(value) {
                const div = document.createElement('div');
                div.textContent = value ?? '';
                return div.innerHTML;
            }
        });
    </script>
    @endif
</div>
@endsection
