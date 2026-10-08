@extends('cobros.layout')

@section('title', 'Mis solicitudes — MVS CobrOS')

@section('content')
<div class="space-y-4">
    <a href="{{ route('cobros.collector.portfolio') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Volver a mi cartera</a>

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Mis solicitudes</h1>
            <p class="mt-1 text-sm text-slate-500">Ruta {{ $route->name }} · {{ $company->trade_name }}</p>
        </div>
        <a href="{{ route('cobros.collector.credit-requests.create') }}"
           class="inline-flex min-h-[48px] items-center rounded-xl bg-primary px-5 text-base font-bold text-white hover:bg-primary-hover">Solicitud de crédito</a>
    </div>

    @if(session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700" role="status">
            {{ session('success') }}
        </div>
    @endif

    @if($requests->isEmpty())
        <div class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-600">
            Todavía no tiene solicitudes en esta ruta.
        </div>
    @endif

    <ul class="space-y-3">
        @foreach($requests as $item)
            <li class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-base font-semibold text-slate-900">{{ $item->name }}</h2>
                        <p class="mt-0.5 text-sm text-slate-500">{{ $item->identification }}@if($item->phone) · {{ $item->phone }}@endif</p>
                        <p class="mt-0.5 text-xs text-slate-400">Enviada: {{ $item->submitted_at?->format('d/m/Y H:i') ?? '—' }}</p>
                    </div>
                    <div class="text-right">
                        @php
                            $log = $item->latestLog();
                            $motivo = in_array($item->status, ['requiere_correccion', 'rechazada'], true) ? ($log?->notes ?? null) : null;
                            // 9C: estado humano seg�n el cr�dito REAL (no exponer DISBURSED).
                            $creditRow = $credits[$item->id] ?? null;
                            $creditDisbursed = $creditRow !== null && $creditRow->status === \App\Models\CobrosCredit::STATUS_DISBURSED;
                            $badgeLabel = $creditDisbursed
                                ? 'Pr�stamo entregado'
                                : (($item->status === 'pendiente' && ($canSelfVerify ?? false))
                                    ? 'Lista para verificar'
                                    : $item->statusLabel());
                        @endphp
                        <span @class([
                                'inline-block rounded-full border px-3 py-1 text-xs font-bold',
                                'border-amber-300 bg-amber-50 text-amber-800' => $item->status === 'pendiente',
                                'border-emerald-300 bg-emerald-50 text-emerald-800' => $item->status === 'verificada' || $creditDisbursed,
                                'border-orange-300 bg-orange-50 text-orange-800' => $item->status === 'requiere_correccion',
                                'border-red-300 bg-red-50 text-red-800' => $item->status === 'rechazada',
                                'border-slate-300 bg-slate-50 text-slate-700' => !in_array($item->status, ['pendiente', 'verificada', 'requiere_correccion', 'rechazada'], true) && ! $creditDisbursed,
                            ])>{{ $badgeLabel }}</span>
                        <p class="mt-2 text-base font-bold text-slate-900">@include('cobros.components.money', ['amount' => $item->amount_requested, 'currency' => $company->currency])</p>
                    </div>
                </div>
                @if($motivo)
                    <p class="mt-3 rounded-lg bg-orange-50 p-2 text-sm text-orange-800">
                        <span class="font-semibold">{{ $item->status === 'rechazada' ? 'Rechazo' : 'Corrección solicitada' }}:</span> {{ $motivo }}
                    </p>
                @endif
                @if($item->status === 'requiere_correccion')
                    <a href="{{ route('cobros.collector.credit-requests.create', ['resume' => $item->id]) }}"
                       class="mt-3 inline-flex min-h-[44px] w-full items-center justify-center rounded-xl border-2 border-primary bg-white px-5 text-sm font-bold text-slate-900 hover:bg-primary/10 sm:w-auto">Corregir y reenviar</a>
                @endif
                @if($item->status === 'pendiente' && ($canSelfVerify ?? false))
                    {{-- 13) collector_authorized: el cobrador revisa sus propios datos. --}}
                    <form method="POST" action="{{ route('cobros.collector.credit-requests.verify', $item->id) }}"
                          class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
                        @csrf
                        <label class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-slate-700">Verificar información con el cliente</span>
                            <input name="motivo" value="Datos revisados con el cliente" maxlength="500" required
                                   class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-sm">
                        </label>
                        <button type="submit"
                                class="min-h-[44px] rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover sm:w-auto">VERIFICAR</button>
                    </form>
                @endif
                @if($item->status === 'verificada')
                    <a href="{{ route('cobros.collector.credit-requests.prepare', $item->id) }}"
                       class="mt-3 inline-flex min-h-[44px] w-full items-center justify-center rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover sm:w-auto">PREPARAR PRÉSTAMO</a>
                @endif
                @if($item->status === 'aprobada' && isset($creditIds[$item->id]))
                    @if(($credits[$item->id]->status ?? null) === \App\Models\CobrosCredit::STATUS_DISBURSED)
                        {{-- 9C: ya desembolsado — sin "Entregar pr�stamo"; CTA a la vista existente. --}}
                        <a href="{{ route('cobros.collector.creditos.comprobante', $creditIds[$item->id]) }}"
                           class="mt-3 inline-flex min-h-[44px] w-full items-center justify-center rounded-xl border-2 border-primary bg-white px-5 text-sm font-bold text-slate-900 hover:bg-primary/10 sm:w-auto">VER PRÉSTAMO</a>
                    @else
                        <a href="{{ route('cobros.collector.creditos.entrega', $creditIds[$item->id]) }}"
                           class="mt-3 inline-flex min-h-[44px] w-full items-center justify-center rounded-xl border-2 border-primary bg-white px-5 text-sm font-bold text-slate-900 hover:bg-primary/10 sm:w-auto">Entregar préstamo</a>
                    @endif
                @endif
            </li>
        @endforeach
    </ul>
</div>
@endsection
