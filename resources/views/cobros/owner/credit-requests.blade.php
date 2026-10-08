@extends('cobros.layout')

@section('title', 'Solicitudes — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold sm:text-3xl">Solicitudes de crédito</h1>
        <p class="mt-1 text-sm text-slate-500">Bandeja de verificación · {{ $company->trade_name }}. Solo solicitudes de su empresa.</p>
    </div>

    <form method="GET" action="{{ route('cobros.owner.solicitudes.index') }}"
          class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-end">
        <div class="flex-1">
            <label for="route_id" class="text-sm font-semibold">Ruta</label>
            <select id="route_id" name="route_id" class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 bg-white px-3 text-base">
                <option value="">Todas</option>
                @foreach($routes as $r)
                    <option value="{{ $r->id }}" @selected((string) old('route_id', $filters['route_id'] ?? '') === (string) $r->id)>{{ $r->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex-1">
            <label for="status" class="text-sm font-semibold">Estado</label>
            <select id="status" name="status" class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 bg-white px-3 text-base">
                <option value="">Todos</option>
                @foreach(\App\Models\CobrosCreditRequest::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="min-h-[44px] rounded-xl bg-primary px-6 text-base font-bold text-white hover:bg-primary-hover">Filtrar</button>
    </form>

    @php $grouped = $requests->groupBy(fn ($r) => $r->route?->name ?? 'Sin ruta'); @endphp
    @forelse($grouped as $routeName => $rows)
        <section class="space-y-3">
            <h2 class="text-lg font-bold text-slate-800">Ruta: {{ $routeName }} <span class="text-sm font-normal text-slate-500">({{ $rows->count() }})</span></h2>
            @foreach($rows as $r)
                <a href="{{ route('cobros.owner.solicitudes.show', $r->id) }}"
                   class="flex min-h-[64px] items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 hover:bg-slate-50">
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-slate-900">#{{ $r->id }} · {{ $r->name }}</p>
                        <p class="truncate text-sm text-slate-500">{{ $r->identification }} · {{ $r->collector?->name ?? '—' }} · ₡{{ number_format((float) $r->amount_requested, 2) }}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-3 py-1 text-xs font-bold
                        @if(($creditStatus[$r->id] ?? null) === \App\Models\CobrosCredit::STATUS_DISBURSED)
                            bg-emerald-100 text-emerald-800
                        @else
                            @switch($r->status)
                                @case(\App\Models\CobrosCreditRequest::STATUS_VERIFIED) bg-emerald-100 text-emerald-800 @break
                                @case(\App\Models\CobrosCreditRequest::STATUS_CORRECTION) bg-amber-100 text-amber-800 @break
                                @case(\App\Models\CobrosCreditRequest::STATUS_REJECTED) bg-red-100 text-red-800 @break
                                @default bg-slate-100 text-slate-700
                            @endswitch
                        @endif
                    ">{{ ($creditStatus[$r->id] ?? null) === \App\Models\CobrosCredit::STATUS_DISBURSED ? 'Préstamo entregado' : $r->statusLabel() }}</span>
                </a>
            @endforeach
        </section>
    @empty
        <p class="rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No hay solicitudes con estos filtros.</p>
    @endforelse
</div>
@endsection
