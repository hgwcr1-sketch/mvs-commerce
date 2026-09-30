@extends('cobros.layout')

@section('title', 'Recibo '.$receipt->receipt_number.' — MVS CobrOS')

@section('content')
{{-- Fase 3B: recibo único por empresa. Solo lectura: no modifica saldos ni caja. --}}
<div class="mx-auto max-w-2xl space-y-4">
    @if(session('cobros_receipt_replay'))
        <div role="status" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
            Este cobro ya estaba registrado. Se muestra el recibo original.
        </div>
    @endif

    <div class="flex items-center justify-between gap-2">
        <a href="{{ route('cobros.collector.index') }}" class="inline-flex min-h-[44px] items-center gap-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
            ← MI CARTERA
        </a>
        <a href="{{ route('cobros.collector.payments.history') }}" class="inline-flex min-h-[44px] items-center gap-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
            Mis recibos
        </a>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6" id="cobros-receipt">
        <div class="text-center">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Recibo de cobro</p>
            <p class="mt-1 break-all text-2xl font-bold text-slate-900">{{ $receipt->receipt_number }}</p>
            <p class="mt-1 text-sm text-slate-500">{{ $receipt->paid_at->timezone($company->timezone)->format('d/m/Y H:i') }}</p>
        </div>

        <dl class="mt-4 space-y-2 text-base">
            <div class="flex items-start justify-between gap-3">
                <dt class="text-slate-500">Empresa</dt>
                <dd class="text-right font-semibold text-slate-900">{{ $company->trade_name }}</dd>
            </div>
            @if($receipt->branch)
                <div class="flex items-start justify-between gap-3">
                    <dt class="text-slate-500">Sucursal</dt>
                    <dd class="text-right font-semibold text-slate-900">{{ $receipt->branch->name }}</dd>
                </div>
            @endif
            <div class="flex items-start justify-between gap-3">
                <dt class="text-slate-500">Cliente</dt>
                <dd class="text-right font-semibold text-slate-900">{{ $receipt->customer->name }}</dd>
            </div>
            <div class="flex items-start justify-between gap-3">
                <dt class="text-slate-500">Cobrador</dt>
                <dd class="text-right font-semibold text-slate-900">{{ $receipt->collector->name }}</dd>
            </div>
            <div class="flex items-start justify-between gap-3">
                <dt class="text-slate-500">Forma de pago</dt>
                <dd class="text-right font-semibold text-slate-900">{{ $receipt->paymentMethod->name }}</dd>
            </div>
            @if($receipt->reference)
                <div class="flex items-start justify-between gap-3">
                    <dt class="text-slate-500">Referencia</dt>
                    <dd class="break-all text-right font-semibold text-slate-900">{{ $receipt->reference }}</dd>
                </div>
            @endif
            <div class="flex items-start justify-between gap-3 border-t border-slate-200 pt-3">
                <dt class="text-slate-500">Monto cobrado</dt>
                <dd class="text-right text-2xl font-bold text-slate-900">@include('cobros.components.money', ['amount' => $receipt->amount, 'currency' => $receipt->currency_code])</dd>
            </div>
            <div class="flex items-start justify-between gap-3">
                <dt class="text-slate-500">Saldo restante de la cuenta</dt>
                <dd class="text-right font-bold text-slate-900">@include('cobros.components.money', ['amount' => $receipt->accountReceivable->balance_due, 'currency' => $receipt->currency_code])</dd>
            </div>
        </dl>

        <p class="mt-4 text-center text-xs text-slate-500">
            Este cobro no se ingresó a una sesión de caja: queda en custodia hasta la liquidación.
        </p>
    </div>

    <button type="button" onclick="window.print()"
            class="flex min-h-[52px] w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-4 text-lg font-bold text-slate-950 hover:bg-primary-hover">
        IMPRIMIR / COMPARTIR
    </button>
</div>
@endsection
