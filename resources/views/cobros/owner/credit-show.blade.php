@extends('cobros.layout')

@section('title', 'Préstamo '.($credit->publicReference() ?? '').' — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <a href="{{ route('cobros.owner.solicitudes.show', $credit->credit_request_id) }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Volver a la solicitud</a>

    @if(session('success'))
        <div role="status" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-bold sm:text-3xl">{{ $creditRequest?->name ?? $credit->request?->name ?? 'Préstamo' }}</h1>
        @if($credit->status === \App\Models\CobrosCredit::STATUS_DISBURSED)
            <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">Préstamo entregado</span>
        @else
            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800">Aprobado · Pendiente de desembolso</span>
        @endif
    </div>
    <p class="-mt-3 text-sm font-semibold text-slate-600">Referencia del préstamo: <span class="font-bold text-slate-900">{{ $credit->publicReference() }}</span></p>

    <section class="rounded-2xl border border-slate-200 bg-white p-4">
        <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Condiciones (fijas desde la aprobación)</h2>
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-slate-500">Solicitante</dt>
                <dd class="font-semibold">{{ $creditRequest?->name }} · {{ $creditRequest?->identification }}</dd></div>
            <div><dt class="text-slate-500">Monto solicitado</dt>
                <dd class="font-semibold">₡ {{ number_format((float) ($credit->terms_snapshot['amount_requested'] ?? 0), 2, '.', '') }}</dd></div>
            <div><dt class="text-slate-500">Monto aprobado (principal)</dt>
                <dd class="font-semibold">₡ {{ number_format((float) $credit->principal, 2, '.', '') }}</dd></div>
            <div><dt class="text-slate-500">Interés</dt>
                <dd class="font-semibold">
                    @if($credit->interest_type === \App\Models\CobrosCredit::INTEREST_NONE) Sin interés
                    @elseif($credit->interest_type === \App\Models\CobrosCredit::INTEREST_FIXED) Monto fijo de ₡ {{ number_format((float) $credit->interest_value, 2, '.', '') }}
                    @else {{ rtrim(rtrim(number_format((float) $credit->interest_value, 4, '.', ''), '0'), '.') }} % (simple)
                    @endif
                </dd></div>
            <div><dt class="text-slate-500">Total a cobrar</dt>
                <dd class="text-lg font-bold">₡ {{ number_format((float) $credit->total_due, 2, '.', '') }}</dd></div>
            <div><dt class="text-slate-500">Cuotas</dt>
                <dd class="font-semibold">{{ $credit->installment_count }} · {{ ['daily' => 'Diarias', 'weekly' => 'Semanales', 'biweekly' => 'Quincenales', 'monthly' => 'Mensuales'][$credit->frequency] ?? $credit->frequency }}</dd></div>
            <div><dt class="text-slate-500">Primera cuota</dt>
                <dd class="font-semibold">{{ $credit->first_due_date->format('d/m/Y') }}</dd></div>
            <div><dt class="text-slate-500">Mora</dt>
                <dd class="font-semibold">
                    @if($credit->late_fee_type === \App\Models\CobrosCredit::LATE_FEE_NONE) Sin mora
                    @elseif($credit->late_fee_type === \App\Models\CobrosCredit::LATE_FEE_FIXED) ₡ {{ number_format((float) $credit->late_fee_value, 2, '.', '') }} por atraso
                    @else {{ rtrim(rtrim(number_format((float) $credit->late_fee_value, 4, '.', ''), '0'), '.') }} % por atraso
                    @endif
                    @if((int) $credit->late_fee_grace_days > 0) · {{ $credit->late_fee_grace_days }} día(s) de gracia @endif
                </dd></div>
            <div><dt class="text-slate-500">Ruta</dt><dd class="font-semibold">{{ $credit->route?->name ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Cobrador</dt><dd class="font-semibold">{{ $credit->collector?->name ?? '—' }}</dd></div>
        </dl>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4">
        <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Calendario de cuotas</h2>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[340px] text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-2">#</th>
                        <th class="py-2 pr-2">Fecha</th>
                        <th class="py-2 text-right">Monto</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($rows as $row)
                        <tr>
                            <td class="py-2 pr-2">{{ $row['number'] }}</td>
                            <td class="py-2 pr-2">{{ \Illuminate\Support\Carbon::parse($row['due_date'])->format('d/m/Y') }}</td>
                            <td class="py-2 text-right">₡ {{ number_format((float) $row['contractual_amount'], 2, '.', '') }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-slate-300 font-bold">
                        <td class="py-2 pr-2" colspan="2">Total</td>
                        <td class="py-2 text-right">₡ {{ number_format((float) $credit->total_due, 2, '.', '') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @if($credit->status === \App\Models\CobrosCredit::STATUS_APPROVED)
            <p class="mt-2 text-xs text-slate-500">Vista previa: las cuotas se registran al confirmar el desembolso.</p>
        @endif
    </section>

    @if($credit->status === \App\Models\CobrosCredit::STATUS_APPROVED)
        <section class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Entrega</h2>
            <p class="mb-4 text-sm text-slate-600">Revise las condiciones con el cliente y reciba su firma para entregar el dinero.</p>
            <a href="{{ route('cobros.owner.creditos.entrega', $credit->id) }}"
               class="flex min-h-[48px] w-full items-center justify-center rounded-xl bg-primary px-6 text-base font-bold text-white hover:bg-primary-hover">ENTREGAR PRÉSTAMO</a>
        </section>
    @else
        <section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
            Desembolsado el {{ $credit->disbursement_date?->format('d/m/Y') }} · CxC #{{ $credit->account_receivable_id }} en cartera del cobrador.
            <a href="{{ route('cobros.owner.creditos.comprobante', $credit->id) }}"
               class="mt-3 flex min-h-[48px] items-center justify-center rounded-xl bg-primary px-6 text-base font-bold text-white hover:bg-primary-hover">VER COMPROBANTE</a>
        </section>
    @endif
</div>
@endsection
