@extends('cobros.layout')

@section('title', 'Alertas CobrOS — MVS CobrOS')

@section('content')
{{-- Fase 5A: alertas del canal CobrOS en el Centro de Notificaciones. --}}
<div class="mx-auto max-w-4xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Alertas CobrOS</h1>
            <p class="mt-1 break-words text-sm text-slate-500">{{ $company->trade_name }}</p>
        </div>
        <a href="{{ auth()->user()->hasPermission('cobros.portal_dueno', $company) ? route('cobros.owner.dashboard') : route('cobros.supervisor.index') }}"
           class="inline-flex min-h-[44px] items-center gap-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
            ← VOLVER
        </a>
    </div>

    {{-- Conteo de alertas activas por tipo (solo canal CobrOS). --}}
    <section class="grid grid-cols-2 gap-3 lg:grid-cols-3">
        @foreach($labels as $type => $label)
            <div class="rounded-xl border border-slate-200 bg-white p-3 text-center">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-lg font-bold text-slate-900">{{ $openCounts->get($type, 0) }}</p>
                <p class="text-xs text-slate-500">activas</p>
            </div>
        @endforeach
    </section>

    @if(in_array(1, $summary, true))
        <p class="text-center text-xs text-slate-500">Evaluación ejecutada: nuevas alertas {{ collect($summary)->sum() }}.</p>
    @endif

    <section class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <h2 class="text-lg font-semibold text-slate-900">Historial de alertas</h2>

        @if($alerts->isEmpty())
            <p class="mt-3 text-sm text-slate-500">Todavía no hay alertas del canal CobrOS.</p>
        @else
            <ul class="mt-3 space-y-3">
                @foreach($alerts as $alert)
                    @php($meta = is_array($alert->metadata) ? $alert->metadata : [])
                    @php($severityClass = match ($alert->severity) {
                        \App\Models\Alert::SEVERITY_CRITICAL => 'border-red-300 bg-red-50 text-red-800',
                        \App\Models\Alert::SEVERITY_ATTENTION => 'border-amber-300 bg-amber-50 text-amber-800',
                        default => 'border-slate-200 bg-slate-50 text-slate-700',
                    })
                    <li class="rounded-xl border {{ $severityClass }} p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="break-words text-base font-bold">
                                {{ $labels[$alert->type] ?? $alert->type }}
                            </span>
                            <span class="text-xs text-slate-500">
                                {{ $alert->occurred_at->timezone($company->timezone)->format('d/m/Y H:i') }}
                                · {{ $alert->status }}
                            </span>
                        </div>
                        <p class="mt-1 break-words text-sm">
                            @isset($meta['receipt_number'])Recibo {{ $meta['receipt_number'] }} · @endisset
                            @isset($meta['reversal_number'])Reverso {{ $meta['reversal_number'] }} · @endisset
                            @isset($meta['customer_name']){{ $meta['customer_name'] }} · @endisset
                            @isset($meta['net'])Custodia neta {{ $meta['net'] }} · @endisset
                            @isset($meta['expires_at'])Vence {{ $meta['expires_at'] }} · @endisset
                            @if($alert->branch)Sucursal: {{ $alert->branch->name }} · @endif
                            @if($alert->link)<a class="font-semibold underline" href="{{ url($alert->link) }}">Ver detalle</a>@endif
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-4 flex items-center justify-center text-sm">
            {{ $alerts->links() }}
        </div>
    </section>

    <p class="text-center text-xs text-slate-500">
        Canal CobrOS del Centro de Notificaciones: solo lectura y solo para dueño/supervisor.
        La frecuencia se controla desde las preferencias de notificaciones.
    </p>
</div>
@endsection
