@extends('cobros.layout')

@section('title', 'Historial de visitas — MVS CobrOS')

@section('content')
<div class="space-y-4">
    <a href="{{ route('cobros.collector.portfolio.show', $customer) }}" class="inline-flex min-h-[44px] items-center gap-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
        ← Volver al cliente
    </a>

    <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <h1 class="text-xl font-bold text-slate-900">{{ $customer->name }}</h1>
        <p class="mt-1 text-sm text-slate-500">Historial de gestiones y próximas visitas</p>
    </div>

    <section aria-labelledby="programar" class="rounded-xl border border-slate-200 bg-white p-4">
        <h2 id="programar" class="text-lg font-semibold text-slate-900">Programar visita</h2>
        <p class="mt-1 text-xs text-slate-500">Solo crea la visita pendiente. La gestión se registra desde Agenda.</p>
        <form method="POST" action="{{ route('cobros.visits.store', $customer) }}" class="mt-3 space-y-3">
            @csrf
            <label class="block text-xs font-medium text-slate-500">Fecha programada (obligatoria)
                <input type="date" name="scheduled_for" required value="{{ today($company->timezone)->toDateString() }}" class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 py-2">
            </label>
            <label class="block text-xs font-medium text-slate-500">Observación (opcional)
                <textarea name="notes" rows="2" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"></textarea>
            </label>
            <button type="submit" class="min-h-[44px] w-full rounded-xl bg-primary px-4 py-2.5 text-base font-semibold text-white hover:bg-primary-hover cursor-pointer">Programar visita</button>
        </form>
    </section>

    @php
        $human = [
            'pendiente' => 'Esperando visita',
            'visitado' => 'Visitado',
            'no_localizado' => 'No estaba',
            'promesa_pago' => 'Promesa de pago',
            'reprogramada' => 'Otro día',
        ];
    @endphp

    <section aria-labelledby="historial">
        <h2 id="historial" class="text-lg font-semibold text-slate-900">Gestiones ({{ $visits->count() }})</h2>
        @if($visits->isEmpty())
            <p class="mt-3 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">Sin gestiones registradas todavía.</p>
        @endif
        <ol class="mt-3 space-y-2">
            @foreach($visits as $visit)
                <li class="rounded-xl border border-slate-200 bg-white px-4 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-sm font-semibold text-slate-900">{{ $visit->scheduled_for->format('d/m/Y') }}</span>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $visit->status === 'pendiente' ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800' }}">{{ $human[$visit->status] }}</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Por {{ $visit->collector->name }}{{ $visit->branch ? ' · '.$visit->branch->name : '' }}
                        @if($visit->visited_at) · Gestionada {{ $visit->visited_at->format('d/m/Y') }} @endif
                        @if($visit->promise_date) · Pagará el {{ $visit->promise_date->format('d/m/Y') }} @endif
                        @if($visit->promise_amount !== null) · @include('cobros.components.money', ['amount' => $visit->promise_amount, 'currency' => $company->currency]) @endif
                    </p>
                    @if($visit->notes)
                        <p class="mt-1 break-words text-sm text-slate-700">{{ $visit->notes }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
</div>
@endsection
