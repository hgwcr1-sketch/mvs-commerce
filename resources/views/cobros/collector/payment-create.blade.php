@extends('cobros.layout')

@section('title', 'Cobrar a '.$customer->name.' — MVS CobrOS')

@section('content')
{{-- Fase 3A: pantalla previa. NO pide monto, NO registra pagos, NO toca caja. --}}
<div class="mx-auto max-w-2xl space-y-4">
    <a href="{{ route('cobros.collector.portfolio.show', $customer) }}" class="inline-flex min-h-[44px] items-center gap-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
        ← VOLVER
    </a>

    <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Cliente</p>
        <h1 class="mt-1 break-words text-2xl font-bold text-slate-900">{{ $customer->name }}</h1>
        @if($customer->phone || $customer->mobile)
            <p class="mt-1 break-words text-sm text-slate-500">{{ $customer->phone ?: $customer->mobile }}</p>
        @endif
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4 text-center sm:p-6">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Saldo pendiente</p>
        <p class="mt-2 break-all text-3xl font-bold text-slate-900 sm:text-4xl">@include('cobros.components.money', ['amount' => $account->balance_due, 'currency' => $account->currency_code])</p>
        <p class="mt-3 text-base font-semibold text-slate-700">
            @if(!$account->due_date)
                Sin fecha de vencimiento.
            @elseif($account->due_date->toDateString() < today($company->timezone)->toDateString())
                Este pago está vencido desde el {{ $account->due_date->format('d/m/Y') }}.
            @else
                Fecha de pago: {{ $account->due_date->format('d/m/Y') }}.
            @endif
        </p>
        <p class="mt-1 text-sm text-slate-500">
            @if($account->due_date && $account->due_date->toDateString() < today($company->timezone)->toDateString())
                Cuenta vencida.
            @elseif(bccomp((string) $account->balance_due, (string) $account->original_amount, 4) < 0)
                Cuenta con abonos previos.
            @else
                Pendiente de pago.
            @endif
        </p>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Dato de la cuenta</p>
        <p class="mt-1 break-words text-base font-semibold text-slate-900">
            Total de la cuenta: @include('cobros.components.money', ['amount' => $account->original_amount, 'currency' => $account->currency_code])
        </p>
        @if($account->branch)
            <p class="mt-1 text-sm text-slate-500">Sucursal: {{ $account->branch->name }}</p>
        @endif
    </div>

    @if($customer->hasLocation())
        <a href="{{ $customer->googleMapsUrl() }}" target="_blank" rel="noopener"
           class="flex min-h-[48px] w-full items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-3 text-base font-semibold text-slate-800 hover:bg-slate-50">🧭 IR AL CLIENTE</a>
    @endif

    {{-- 3A: el botón queda deshabilitado a propósito. Ninguna operación se registra aquí. --}}
    <button type="button" disabled aria-disabled="true"
            class="flex min-h-[56px] w-full cursor-not-allowed items-center justify-center gap-2 rounded-xl bg-primary px-4 py-4 text-lg font-bold text-slate-950">
        CONTINUAR CON EL COBRO
    </button>
    <p class="text-center text-xs text-slate-500">
        El registro del cobro se habilita en el siguiente paso de MVS CobrOS.
    </p>
</div>
@endsection
