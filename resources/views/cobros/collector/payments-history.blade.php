@extends('cobros.layout')

@section('title', 'Mis recibos — MVS CobrOS')

@section('content')
{{-- Fase 3B: historial propio de recibos del cobrador, solo lectura. --}}
<div class="mx-auto max-w-3xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-bold text-slate-900">Mis recibos</h1>
        <a href="{{ route('cobros.collector.index') }}" class="inline-flex min-h-[44px] items-center gap-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
            ← MI CARTERA
        </a>
    </div>

    @if($receipts->isEmpty())
        <div class="rounded-xl border border-slate-200 bg-white p-6 text-center text-base text-slate-500">
            Todavía no tiene recibos registrados.
        </div>
    @else
        <ul class="space-y-3">
            @foreach($receipts as $receipt)
                <li>
                    <a href="{{ route('cobros.collector.payments.receipt', $receipt->receipt_number) }}"
                       class="flex min-h-[72px] flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 hover:bg-slate-50">
                        <span class="min-w-0">
                            <span class="block break-all text-base font-bold text-slate-900">{{ $receipt->receipt_number }}</span>
                            <span class="block break-words text-sm text-slate-500">{{ $receipt->customer->name }} · {{ $receipt->paid_at->timezone($company->timezone)->format('d/m/Y H:i') }}</span>
                        </span>
                        <span class="text-right">
                            <span class="block text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $receipt->amount, 'currency' => $receipt->currency_code])</span>
                            <span class="block text-sm text-slate-500">{{ $receipt->paymentMethod->name }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="flex items-center justify-center gap-2 text-sm">
            {{ $receipts->links() }}
        </div>
    @endif
</div>
@endsection
