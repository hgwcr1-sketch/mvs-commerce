@extends('cobros.layout')

@section('title', 'Cierre del día — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold text-slate-900 sm:text-3xl">Cierre del día</h1>
                <p class="mt-1 text-sm text-slate-500">Declare los totales cobrados por medio de pago y envíe el cierre al dueño.</p>
            </div>
            <div class="text-right">
                <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Fecha del cierre</span>
                <span class="text-lg font-bold capitalize text-slate-900">{{ $dayLabel }}</span>
            </div>
        </div>

        <form method="GET" action="{{ route('cobros.closing.show') }}" class="mt-4">
            <label for="closing-date" class="block text-sm font-semibold text-slate-700">Ver otro día</label>
            <select id="closing-date" name="date" onchange="this.form.submit()"
                    class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2.5 text-base">
                <option value="{{ $day->toDateString() }}" selected>Hoy ({{ $day->format('d/m/Y') }})</option>
                @foreach($otherDates as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </form>
    </div>

    @if(session('success'))
        <div role="status" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" aria-label="Totales reales del día">
        <h2 class="text-lg font-semibold text-slate-900">Totales registrados hoy en el sistema</h2>
        <p class="mt-1 text-sm text-slate-500">Pagos del día menos anulaciones. Use estos valores para conciliar su declaración.</p>

        <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div class="rounded-xl bg-slate-50 p-3">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Efectivo</dt>
                <dd class="mt-1 text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $totals['buckets']['cash'], 'currency' => $company->currency])</dd>
            </div>
            <div class="rounded-xl bg-slate-50 p-3">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">SINPE</dt>
                <dd class="mt-1 text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $totals['buckets']['sinpe'], 'currency' => $company->currency])</dd>
            </div>
            <div class="rounded-xl bg-slate-50 p-3">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Datáfono</dt>
                <dd class="mt-1 text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $totals['buckets']['card'], 'currency' => $company->currency])</dd>
            </div>
            <div class="rounded-xl bg-slate-50 p-3">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Depósito</dt>
                <dd class="mt-1 text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $totals['buckets']['bank_transfer'], 'currency' => $company->currency])</dd>
            </div>
            <div class="rounded-xl bg-slate-50 p-3">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Otros</dt>
                <dd class="mt-1 text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $totals['buckets']['other'], 'currency' => $company->currency])</dd>
            </div>
            <div class="rounded-xl bg-slate-50 p-3">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pagos del día</dt>
                <dd class="mt-1 text-lg font-bold text-slate-900">{{ $totals['payments_count'] }}</dd>
            </div>
        </dl>

        <div class="mt-3 rounded-xl border border-slate-200 bg-white p-3">
            <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Total del día</span>
            <span class="text-xl font-bold text-slate-900">@include('cobros.components.money', ['amount' => $totals['total'], 'currency' => $company->currency])</span>
        </div>
    </section>

    @if($closing)
        <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" aria-label="Cierre enviado">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-slate-900">Cierre enviado</h2>
                <span class="rounded-full {{ $differences['has_differences'] ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }} px-3 py-1 text-xs font-bold">
                    {{ $differences['has_differences'] ? 'Con diferencias' : 'Conforme' }}
                </span>
            </div>
            <p class="mt-1 text-sm text-slate-500">
                Enviado el {{ $closing->submitted_at?->format('d/m/Y H:i') }} por {{ $closing->collector?->name }}.
                @if($closing->reviewed_at)
                    · Revisado por {{ $closing->reviewedBy?->name }} el {{ $closing->reviewed_at->format('d/m/Y H:i') }}
                @endif
            </p>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[520px] text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                            <th class="py-2 pr-3">Medio</th>
                            <th class="py-2 pr-3 text-right">Declarado</th>
                            <th class="py-2 pr-3 text-right">Sistema</th>
                            <th class="py-2 text-right">Diferencia</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($differences['buckets'] as $bucket => $row)
                            <tr class="border-b border-slate-100">
                                <td class="py-2 pr-3 font-semibold text-slate-900">
                                    {{ ['cash' => 'Efectivo', 'sinpe' => 'SINPE', 'card' => 'Datáfono', 'bank_transfer' => 'Depósito', 'other' => 'Otros'][$bucket] }}
                                </td>
                                <td class="py-2 pr-3 text-right">@include('cobros.components.money', ['amount' => $row['declared'], 'currency' => $company->currency])</td>
                                <td class="py-2 pr-3 text-right">@include('cobros.components.money', ['amount' => $row['computed'], 'currency' => $company->currency])</td>
                                <td class="py-2 text-right font-bold {{ $row['difference'] !== '0.0000' ? 'text-amber-700' : 'text-slate-500' }}">
                                    @include('cobros.components.money', ['amount' => $row['difference'], 'currency' => $company->currency])
                                </td>
                            </tr>
                        @endforeach
                        <tr class="border-b border-slate-200 bg-slate-50">
                            <td class="py-2 pr-3 font-bold text-slate-900">Total</td>
                            <td class="py-2 pr-3 text-right font-bold">@include('cobros.components.money', ['amount' => $differences['total']['declared'], 'currency' => $company->currency])</td>
                            <td class="py-2 pr-3 text-right font-bold">@include('cobros.components.money', ['amount' => $differences['total']['computed'], 'currency' => $company->currency])</td>
                            <td class="py-2 text-right font-bold {{ $differences['total']['difference'] !== '0.0000' ? 'text-amber-700' : 'text-slate-500' }}">
                                @include('cobros.components.money', ['amount' => $differences['total']['difference'], 'currency' => $company->currency])
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            @if($closing->notes)
                <p class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-700"><strong>Notas:</strong> {{ $closing->notes }}</p>
            @endif
            @if($closing->review_notes)
                <p class="mt-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900"><strong>Observaciones del dueño:</strong> {{ $closing->review_notes }}</p>
            @endif

            <div class="mt-3 flex flex-wrap gap-3 text-sm font-semibold">
                @if($closing->evidence_deposit_path)
                    <a class="text-amber-700 underline min-h-[44px] inline-flex items-center"
                       href="{{ route('cobros.closing.evidence', ['closing' => $closing, 'kind' => 'deposito']) }}" target="_blank" rel="noopener">Ver comprobante de depósito</a>
                @endif
                @if($closing->evidence_dataphone_path)
                    <a class="text-amber-700 underline min-h-[44px] inline-flex items-center"
                       href="{{ route('cobros.closing.evidence', ['closing' => $closing, 'kind' => 'dataphone']) }}" target="_blank" rel="noopener">Ver cierre de datáfono</a>
                @endif
            </div>
        </section>
    @endif

    <form method="POST" action="{{ route('cobros.closing.store') }}" enctype="multipart/form-data"
          class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        @csrf
        <input type="hidden" name="business_date" value="{{ $day->toDateString() }}">
        <h2 class="text-lg font-semibold text-slate-900">{{ $closing ? 'Rectificar cierre' : 'Declarar cierre' }}</h2>
        <p class="mt-1 text-sm text-slate-500">Deje en blanco el medio que no cobró. Reenviar actualiza el cierre de este día.</p>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="declared_cash" class="block text-sm font-semibold text-slate-700">Efectivo</label>
                <input id="declared_cash" name="declared_cash" inputmode="decimal" autocomplete="off"
                       value="{{ old('declared_cash', $closing?->declared_cash) }}" placeholder="0.0000"
                       class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2.5 text-base text-right">
            </div>
            <div>
                <label for="declared_sinpe" class="block text-sm font-semibold text-slate-700">SINPE</label>
                <input id="declared_sinpe" name="declared_sinpe" inputmode="decimal" autocomplete="off"
                       value="{{ old('declared_sinpe', $closing?->declared_sinpe) }}" placeholder="0.0000"
                       class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2.5 text-base text-right">
            </div>
            <div>
                <label for="declared_card" class="block text-sm font-semibold text-slate-700">Datáfono</label>
                <input id="declared_card" name="declared_card" inputmode="decimal" autocomplete="off"
                       value="{{ old('declared_card', $closing?->declared_card) }}" placeholder="0.0000"
                       class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2.5 text-base text-right">
            </div>
            <div>
                <label for="declared_bank_transfer" class="block text-sm font-semibold text-slate-700">Depósito</label>
                <input id="declared_bank_transfer" name="declared_bank_transfer" inputmode="decimal" autocomplete="off"
                       value="{{ old('declared_bank_transfer', $closing?->declared_bank_transfer) }}" placeholder="0.0000"
                       class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2.5 text-base text-right">
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="evidence_deposit" class="block text-sm font-semibold text-slate-700">Comprobante de depósito (imagen)</label>
                <input id="evidence_deposit" name="evidence_deposit" type="file" accept="image/*"
                       class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2 text-base">
            </div>
            <div>
                <label for="evidence_dataphone" class="block text-sm font-semibold text-slate-700">Cierre de datáfono (imagen)</label>
                <input id="evidence_dataphone" name="evidence_dataphone" type="file" accept="image/*"
                       class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2 text-base">
            </div>
        </div>

        <div class="mt-4">
            <label for="notes" class="block text-sm font-semibold text-slate-700">Notas</label>
            <textarea id="notes" name="notes" rows="3" maxlength="1000"
                      class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-2.5 text-base"
                      placeholder="Observaciones del día, faltantes, incidencias…">{{ old('notes', $closing?->notes) }}</textarea>
        </div>

        <button type="submit"
                class="mt-5 inline-flex min-h-[48px] w-full items-center justify-center rounded-xl bg-primary px-6 text-base font-bold text-white hover:bg-primary-hover sm:w-auto">
            {{ $closing ? 'Actualizar y enviar cierre' : 'Enviar cierre al dueño' }}
        </button>
    </form>
</div>
@endsection
