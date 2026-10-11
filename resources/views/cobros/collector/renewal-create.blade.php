@extends('cobros.layout')

@section('title', 'Renovar crédito - '.$customer->name.' - MVS CobrOS')

@section('content')
<div class="mx-auto max-w-2xl space-y-4 px-4 py-6">
    <a href="{{ route('cobros.collector.portfolio.show', $customer) }}" class="text-sm font-semibold text-primary hover:underline">&larr; Volver a la ficha del cliente</a>

    <h1 class="text-2xl font-black tracking-tight">Renovar crédito</h1>
    <p class="text-sm text-slate-600">Cliente: <strong>{{ $customer->name }}</strong>. Crédito actual: saldo pendiente de <strong>{{ number_format((float) $credit->total_due, 2) }}</strong> ({{ $credit->installment_count }} cuotas, frecuencia {{ $credit->frequency }}).</p>

    @if(session('renewal_status') === 'pending')
        <div role="status" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
            ⏳ Renovación registrada. <strong>Pendiente de aprobación</strong> del prestamista. No se ha creado crédito nuevo ni desembolsado dinero.
        </div>
    @elseif(session('renewal_status') === 'approved')
        <div role="status" class="rounded-xl border border-green-300 bg-green-50 px-4 py-3 text-sm font-semibold text-green-900">
            ✅ Renovación aprobada. Pendiente de consentimiento y firma del cliente para la activación (fase posterior). No se ha creado crédito nuevo todavía.
        </div>
    @endif

    @if($mode === 'approval')
        <div class="rounded-xl border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-sky-900">
            Su perfil de cobrador requiere la aprobación del prestamista. La solicitud quedará <strong>Pendiente de aprobación</strong> al enviarla.
        </div>
    @endif

    <form method="GET" action="{{ route('cobros.collector.renewal.create', $customer) }}" class="rounded-xl border border-slate-200 bg-white p-4">
        <label class="block text-sm font-semibold text-slate-700">Nuevo capital a entregar (₡)
            <input type="text" name="new_capital" inputmode="decimal" value="{{ request('new_capital') }}"
                   placeholder="Ej: 50000" required
                   class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-2 text-base text-slate-900">
        </label>
        <label class="mt-3 block text-sm font-semibold text-slate-700">Cuotas del nuevo calendario
            <input type="number" name="installments" min="1" max="120" value="{{ request('installments', $credit->installment_count) }}"
                   class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-2 text-base text-slate-900">
        </label>
        <button type="submit" class="mt-4 flex min-h-[48px] w-full items-center justify-center rounded-xl bg-primary px-4 py-3 text-base font-bold text-slate-950 hover:bg-primary-hover">Calcular renovación</button>
    </form>

    @if($quote)
        <section aria-labelledby="quote-title" class="rounded-xl border border-slate-200 bg-white p-4">
            <h2 id="quote-title" class="text-lg font-semibold text-slate-900">Cotización de renovación</h2>

            <dl class="mt-3 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-slate-600">Deuda anterior (pendiente)</dt><dd class="font-semibold">{{ number_format((float) $quote['previous']['total'], 2) }}</dd></div>
                <div class="flex justify-between pl-4 text-slate-500"><dt>&bull; Capital anterior</dt><dd>{{ number_format((float) $quote['previous']['principal'], 2) }}</dd></div>
                <div class="flex justify-between pl-4 text-slate-500"><dt>&bull; Intereses anteriores</dt><dd>{{ number_format((float) $quote['previous']['interest'], 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-600">Nuevo capital entregado</dt><dd class="font-semibold">{{ number_format((float) $quote['new_money']['principal'], 2) }}</dd></div>
                <div class="flex justify-between pl-4 text-slate-500"><dt>&bull; Intereses nuevos (solo sobre el capital nuevo)</dt><dd>{{ number_format((float) $quote['new_money']['interest'], 2) }}</dd></div>
                <div class="mt-2 flex justify-between border-t border-slate-200 pt-2 text-base font-bold text-slate-900"><dt>Total consolidado propuesto</dt><dd>{{ number_format((float) $quote['consolidated_total'], 2) }}</dd></div>
                <div class="flex justify-between text-sm font-semibold text-primary"><dt>Desembolso real al cliente</dt><dd>{{ number_format((float) $quote['disbursement'], 2) }}</dd></div>
            </dl>

            <h3 class="mt-4 text-sm font-semibold text-slate-900">Calendario propuesto ({{ $quote['schedule']['installment_count'] }} cuotas, {{ $quote['schedule']['frequency'] }})</h3>
            <ul class="mt-2 space-y-1 text-sm text-slate-700">
                @foreach($quote['schedule']['rows'] as $row)
                    <li class="flex justify-between"><span>Cuota {{ $row['number'] }} — {{ \Carbon\Carbon::parse($row['due_date'])->format('d/m/Y') }}</span><span class="font-semibold">{{ number_format((float) $row['contractual_amount'], 2) }}</span></li>
                @endforeach
            </ul>

            @if($quote['late_fee_excluded'] !== '0.0000' && $quote['late_fee_excluded'] !== '0')
                <p class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-900">La mora pendiente ({{ number_format((float) $quote['late_fee_excluded'], 2) }}) no se capitaliza ni forma parte de esta renovación; se cobra aparte.</p>
            @endif

            <form method="POST" action="{{ route('cobros.collector.renewal.store', $customer) }}" class="mt-4">
                @csrf
                <input type="hidden" name="new_capital" value="{{ request('new_capital') }}">
                <input type="hidden" name="installment_count" value="{{ request('installments', $credit->installment_count) }}">
                <input type="hidden" name="first_due_date" value="{{ today($company->timezone ?: config('app.timezone'))->addWeek()->toDateString() }}">
                <p class="mb-3 text-xs text-slate-500">Al enviar, la solicitud se registra con estos números. La activación exige el consentimiento y la firma electrónica del cliente en una fase posterior. No se crea crédito nuevo ni se desembolsa dinero en este paso.</p>
                <button type="submit" class="flex min-h-[48px] w-full items-center justify-center rounded-xl {{ $mode === 'direct' ? 'bg-primary hover:bg-primary-hover' : 'bg-slate-400' }} px-4 py-3 text-base font-bold text-slate-950">
                    {{ $mode === 'direct' ? 'Aprobar renovación' : 'Enviar solicitud (pendiente de aprobación)' }}
                </button>
            </form>
        </section>
    @endif
</div>
@endsection
