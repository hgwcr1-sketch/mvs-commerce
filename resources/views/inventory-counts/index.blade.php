@extends('layouts.app')

@section('title', 'Tomas de Inventario')

@section('content')
<div class="mx-auto max-w-7xl space-y-6" data-responsive="360 768 1280">
    <header class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Tomas de Inventario</h1>
            <p class="mt-1 text-sm text-slate-600">Listado de conteos físicos de la sucursal activa</p>
        </div>

        @can('inventario.conteo.iniciar')
        <a href="{{ route('inventory-counts.create') }}"
           class="shrink-0 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
            Nueva Toma
        </a>
        @endcan
    </header>

    @if(session('success'))
        <div class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-slate-200 px-4 py-3">
            <form method="GET" class="flex flex-wrap gap-3">
                <select name="status" class="form-input w-full sm:w-48">
                    <option value="">Todos los estados</option>
                    <option value="draft" {{ request('status') === 'draft' ? 'selected' : '' }}>Borrador</option>
                    <option value="counting" {{ request('status') === 'counting' ? 'selected' : '' }}>En Conteo</option>
                    <option value="review" {{ request('status') === 'review' ? 'selected' : '' }}>Revisión</option>
                    <option value="confirmed" {{ request('status') === 'confirmed' ? 'selected' : '' }}>Confirmada</option>
                    <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelada</option>
                </select>
                <button class="min-h-11 rounded-xl bg-slate-800 px-4 font-semibold text-white">Filtrar</button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-slate-600">Referencia</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-slate-600">Estado</th>
                        <th class="px-4 py-3 text-center text-sm font-semibold text-slate-600">Productos</th>
                        <th class="px-4 py-3 text-center text-sm font-semibold text-slate-600">Con Diferencia</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-slate-600">Iniciada por</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-slate-600">Fecha Inicio</th>
                        <th class="px-4 py-3 text-right text-sm font-semibold text-slate-600">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse($counts as $count)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <span class="font-mono text-sm text-slate-700">{{ $count->reference ?? '—' }}</span>
                            @if($count->id)
                                <span class="ml-2 text-xs text-slate-400 font-mono">#{{ $count->id }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $statusColors = [
                                    'draft' => 'bg-slate-100 text-slate-700',
                                    'counting' => 'bg-amber-100 text-amber-700',
                                    'review' => 'bg-indigo-100 text-indigo-700',
                                    'confirmed' => 'bg-emerald-100 text-emerald-700',
                                    'cancelled' => 'bg-red-100 text-red-700',
                                ];
                                $statusLabels = [
                                    'draft' => 'Borrador',
                                    'counting' => 'En Conteo',
                                    'review' => 'Revisión',
                                    'confirmed' => 'Confirmada',
                                    'cancelled' => 'Cancelada',
                                ];
                            @endphp
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $statusColors[$count->status] ?? 'bg-slate-100 text-slate-700' }}">
                                {{ $statusLabels[$count->status] ?? $count->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-slate-700">{{ $count->items_count ?? $count->items->count() }}</td>
                        <td class="px-4 py-3 text-center text-sm font-semibold {{ $count->items_with_difference > 0 ? 'text-amber-700' : 'text-slate-600' }}">
                            {{ $count->items_with_difference ?? $count->items->where('difference', '!=', 0)->count() }}
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-700">{{ $count->startedBy?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-slate-600">{{ $count->started_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @can('inventario.conteo.ver')
                                <a href="{{ route('inventory-counts.show', $count) }}"
                                   class="rounded-xl border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                    Ver
                                </a>
                                @endcan

                                @can('inventario.conteo.contar')
                                @if($count->canBeEdited())
                                <a href="{{ route('inventory-counts.edit', $count) }}"
                                   class="rounded-xl bg-amber-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-600">
                                    {{ $count->isDraft() ? 'Iniciar Conteo' : 'Continuar' }}
                                </a>
                                @endif
                                @endcan

                                @can('inventario.conteo.revisar')
                                @if($count->isCounting())
                                <form method="POST" action="{{ route('inventory-counts.review', $count) }}" class="inline">
                                    @csrf
                                    <button class="rounded-xl bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700">
                                        Enviar a Revisión
                                    </button>
                                </form>
                                @endif
                                @if($count->isReview())
                                <form method="POST" action="{{ route('inventory-counts.back-to-counting', $count) }}" class="inline">
                                    @csrf
                                    <button class="rounded-xl border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                        Volver a Conteo
                                    </button>
                                </form>
                                @endif
                                @endcan

                                @can('inventario.conteo.confirmar')
                                @if($count->isReview())
                                <form method="POST" action="{{ route('inventory-counts.confirm', $count) }}" class="inline" onclick="return confirm('¿Confirmar esta toma de inventario? Se ajustará el stock y no se puede deshacer.')">
                                    @csrf
                                    <button class="rounded-xl bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700">
                                        Confirmar
                                    </button>
                                </form>
                                @endif
                                @endcan

                                @can('inventario.conteo.cancelar')
                                @if($count->canTransitionTo('cancelled'))
                                <form method="POST" action="{{ route('inventory-counts.cancel', $count) }}" class="inline" onclick="return confirm('¿Cancelar esta toma de inventario?')">
                                    @csrf
                                    <button class="rounded-xl border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">
                                        Cancelar
                                    </button>
                                </form>
                                @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center text-slate-400">
                            No hay tomas de inventario registradas.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($counts->hasPages())
        <div class="border-t border-slate-200 px-4 py-3">
            {{ $counts->links() }}
        </div>
        @endif
    </div>
</div>
@endsection