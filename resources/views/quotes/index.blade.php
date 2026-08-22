@extends('layouts.app')
@section('title', 'Cotizaciones')
@section('content')
<div class="space-y-5">
    <div class="flex items-center justify-between"><h1 class="text-2xl font-bold">Cotizaciones</h1>@can('cotizaciones.crear')<a href="{{ route('pos.index') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-white">Nueva cotización</a>@endcan</div>
    <x-card>
        <form method="GET" class="mb-5 grid gap-3 md:grid-cols-5">
            <input name="number" value="{{ $filters['number'] ?? '' }}" placeholder="Número" class="rounded-lg border px-3 py-2">
            <input name="customer" value="{{ $filters['customer'] ?? '' }}" placeholder="Cliente" class="rounded-lg border px-3 py-2">
            <input type="date" name="date" value="{{ $filters['date'] ?? '' }}" class="rounded-lg border px-3 py-2">
            <select name="status" class="rounded-lg border px-3 py-2">
                <option value="">Todos los estados</option>
                <option value="active" {{ ($filters['status'] ?? '') === 'active' ? 'selected' : '' }}>Activa</option>
                <option value="expired" {{ ($filters['status'] ?? '') === 'expired' ? 'selected' : '' }}>Vencida</option>
                <option value="converted" {{ ($filters['status'] ?? '') === 'converted' ? 'selected' : '' }}>Convertida</option>
                <option value="cancelled" {{ ($filters['status'] ?? '') === 'cancelled' ? 'selected' : '' }}>Anulada</option>
            </select>
            <div class="flex gap-2"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Buscar</button><a href="{{ route('cotizaciones.index') }}" class="rounded-lg border px-4 py-2">Limpiar</a></div>
        </form>
        <div class="overflow-x-auto"><table class="min-w-full divide-y"><thead><tr><th class="p-3 text-left">Número</th><th class="p-3 text-left">Fecha</th><th class="p-3 text-left">Cliente</th><th class="p-3 text-left">Estado</th><th class="p-3 text-right">Total</th><th class="p-3 text-right">Acciones</th></tr></thead><tbody class="divide-y">
        @forelse($quotes as $quote)<tr><td class="p-3 font-semibold">{{ $quote->quote_number }}</td><td class="p-3">{{ $quote->created_at->format('d/m/Y H:i') }}</td><td class="p-3">{{ $quote->customer?->name ?? 'Consumidor Final' }}</td><td class="p-3">{{ $quote->status_label }}</td><td class="p-3 text-right">₡{{ number_format((float)$quote->total, 0, ',', '.') }}</td><td class="p-3"><div class="flex flex-wrap justify-end gap-3"><a class="underline" href="{{ route('cotizaciones.show', $quote) }}">Ver</a><a class="underline" target="_blank" href="{{ route('cotizaciones.print', $quote) }}">Imprimir</a>
                            @can('cotizaciones.crear')
                                @if($quote->effective_status === 'active')<a class="font-semibold underline" href="{{ route('pos.index', ['quote_id'=>$quote->id]) }}">Convertir en venta</a>@endif
                            @endcan
                            @can('cotizaciones.editar')
                                @if($quote->status === 'active')<form method="POST" action="{{ route('cotizaciones.cancel',$quote) }}" onsubmit="const reason=prompt('Motivo de anulación:'); if (!reason || reason.trim().length < 3) return false; this.cancellation_reason.value=reason.trim();">@csrf<input type="hidden" name="cancellation_reason"><button class="text-red-700 underline">Anular</button></form>@endif
                            @endcan
                        </div></td></tr>@empty<tr><td colspan="6" class="p-8 text-center text-slate-500">No hay cotizaciones.</td></tr>@endforelse
        </tbody></table></div><div class="mt-4">{{ $quotes->links() }}</div>
    </x-card>
</div>
@endsection
