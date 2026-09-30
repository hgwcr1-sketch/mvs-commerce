@extends('cobros.layout')

@section('title', 'Auditoría de cobros — MVS CobrOS')

@section('content')
{{-- Fase 3C: consulta de auditoría (solo lectura) de cobros y anulaciones. --}}
<div class="mx-auto max-w-3xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-bold text-slate-900">Auditoría de cobros</h1>
        <a href="{{ auth()->user()->hasPermission('cobros.portal_dueno', $company) ? route('cobros.owner.dashboard') : route('cobros.supervisor.index') }}"
           class="inline-flex min-h-[44px] items-center gap-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
            ← VOLVER
        </a>
    </div>

    @if($logs->isEmpty())
        <div class="rounded-xl border border-slate-200 bg-white p-6 text-center text-base text-slate-500">
            Todavía no hay cobros ni anulaciones registradas.
        </div>
    @else
        <ul class="space-y-3">
            @foreach($logs as $log)
                @php($data = is_array($log->metadata) ? $log->metadata : (json_decode($log->metadata ?? '[]', true) ?: []))
                <li class="rounded-xl border border-slate-200 bg-white px-4 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-base font-bold {{ $log->action === 'cobros.payment.annulled' ? 'text-red-700' : 'text-slate-900' }}">
                            {{ $log->action === 'cobros.payment.annulled' ? 'Cobro anulado' : 'Cobro registrado' }}
                        </span>
                        <span class="text-sm text-slate-500">{{ \Illuminate\Support\Carbon::parse($log->occurred_at)->timezone($company->timezone)->format('d/m/Y H:i') }}</span>
                    </div>
                    <p class="mt-1 break-all text-sm text-slate-600">
                        @isset($data['receipt_number'])Recibo {{ $data['receipt_number'] }}@endisset
                        @isset($data['reversal_number']) · Reverso {{ $data['reversal_number'] }}@endisset
                    </p>
                    @if(isset($data['amount']))
                        <p class="mt-1 text-base font-semibold text-slate-900">
                            @include('cobros.components.money', ['amount' => $data['amount'], 'currency' => $company->currency])
                        </p>
                    @endif
                    @if(isset($data['reason']))
                        <p class="mt-1 break-words text-sm text-slate-600">Motivo: {{ $data['reason'] }}</p>
                    @endif
                    <p class="mt-1 text-sm text-slate-500">
                        {{ $actorNames[$log->actor_id] ?? 'Usuario del sistema' }}
                        @if($log->entity_id) · registro #{{ $log->entity_id }}@endif
                    </p>
                </li>
            @endforeach
        </ul>

        <div class="flex items-center justify-center gap-2 text-sm">
            {{ $logs->links() }}
        </div>
    @endif
</div>
@endsection
