@extends('cobros.layout')

@section('title', 'Resumen de carteras iniciales — MVS CobrOS')

@section('content')
{{-- Control administrativo del prestamista: NO gate operativo. --}}
<div class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-2xl font-bold text-slate-900">Carteras iniciales</h1>
    <p class="text-sm text-slate-500">Resumen por cobrador. Revisar es control administrativo: no bloquea trabajar ni cobrar.</p>

    @if(session('success'))
        <div role="status" class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-800">{{ session('success') }}</div>
    @endif

    @if($rows->isEmpty())
        <p class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">Aún no hay ciclos de carga inicial.</p>
    @endif

    @foreach($rows as $row)
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate text-lg font-bold text-slate-900">{{ $row['collector']?->name ?? '—' }}</p>
                    <p class="mt-1 text-sm text-slate-600">Clientes: {{ $row['clients'] }}</p>
                    <p class="text-sm text-slate-600">Saldo declarado: @include('cobros.components.money', ['amount' => $row['balance'], 'currency' => $company->currency])</p>
                    <p class="text-sm text-slate-600">
                        Finalización: {{ $row['finalized_at']?->timezone($company->timezone)->format('d/m/Y H:i') ?? '—' }}
                    </p>
                </div>
                <span class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold {{ $row['reviewed'] ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                    {{ $row['reviewed'] ? 'Revisada' : 'Pendiente de revisión' }}
                </span>
            </div>

            @if($row['finalized_at'] && ! $row['reviewed'])
                <form method="POST" action="{{ route('cobros.initial-portfolio.review') }}" class="mt-3">
                    @csrf
                    <input type="hidden" name="collector_user_id" value="{{ $row['migration']->collector_user_id }}">
                    <button type="submit" class="flex min-h-[48px] w-full items-center justify-center rounded-xl bg-primary px-4 py-3 text-base font-bold text-slate-950 hover:bg-primary-hover">
                        REVISAR / MARCAR REVISADA
                    </button>
                </form>
            @endif

            @if($row['finalized_at'])
                <form method="POST" action="{{ route('cobros.initial-portfolio.reopen.owner') }}" class="mt-3 space-y-2">
                    @csrf
                    <input type="hidden" name="collector_user_id" value="{{ $row['migration']->collector_user_id }}">
                    <input name="reason" type="text" required minlength="8" maxlength="250" placeholder="Motivo de reapertura (obligatorio)"
                           class="min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <button type="submit" class="flex min-h-[48px] w-full items-center justify-center rounded-xl border border-sky-300 bg-white px-3 py-2 text-sm font-bold text-sky-800 hover:bg-sky-50">
                        REABRIR CARGA INICIAL
                    </button>
                </form>
            @endif
        </div>
    @endforeach

    @if($corrections->isEmpty())
        <p class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">Sin solicitudes de corrección pendientes.</p>
    @else
        <h2 class="text-lg font-semibold text-slate-900">Solicitudes de corrección</h2>
        @foreach($corrections as $correction)
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm">
                <p class="font-bold text-amber-900">{{ $correction->record?->customer?->name }} · {{ $correction->field }}</p>
                <p class="mt-1 text-slate-700">Original: <strong>{{ $correction->original_value }}</strong></p>
                <p class="text-slate-700">Solicitado: <strong>{{ $correction->requested_value }}</strong></p>
                <p class="text-slate-700">Motivo: {{ $correction->reason }}</p>
                <p class="mt-1 text-xs text-slate-500">
                    Solicitado por {{ $correction->requestedBy?->name }} el {{ $correction->requested_at?->timezone($company->timezone)->format('d/m/Y H:i') }}
                </p>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <form method="POST" action="{{ route('cobros.initial-portfolio.correction.resolve', $correction) }}">
                        @csrf
                        <input type="hidden" name="decision" value="aprobar">
                        <button type="submit" class="flex min-h-[48px] w-full items-center justify-center rounded-xl bg-green-600 px-3 py-3 font-bold text-white hover:bg-green-700">APROBAR</button>
                    </form>
                    <form method="POST" action="{{ route('cobros.initial-portfolio.correction.resolve', $correction) }}">
                        @csrf
                        <input type="hidden" name="decision" value="rechazar">
                        <button type="submit" class="flex min-h-[48px] w-full items-center justify-center rounded-xl bg-slate-600 px-3 py-3 font-bold text-white hover:bg-slate-700">RECHAZAR</button>
                    </form>
                </div>
            </div>
        @endforeach
    @endif
</div>
@endsection
