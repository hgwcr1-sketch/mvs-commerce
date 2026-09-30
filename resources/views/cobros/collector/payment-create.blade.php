@extends('cobros.layout')

@section('title', 'Cobrar a '.$customer->name.' — MVS CobrOS')

@section('content')
{{-- Fase 3B: captura del cobro con confirmación explícita antes de aplicar. --}}
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
            Total de la cuenta: @include('cobros.components.money', ['amount' => $account->original_amount, 'currency' => $account->currency_code])
            @if($account->branch) · Sucursal: {{ $account->branch->name }} @endif
        </p>
    </div>

    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-4" role="alert">
            <ul class="list-inside list-disc space-y-1 text-sm font-semibold text-red-700">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($preview)
        {{-- Paso 2: confirmación con detalle. Sin confirm=1 el servidor no escribe nada. --}}
        <div class="rounded-xl border-2 border-primary bg-white p-4 sm:p-6">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Confirme el cobro</p>
            <dl class="mt-3 space-y-2 text-base">
                <div class="flex items-start justify-between gap-3">
                    <dt class="text-slate-500">Cliente</dt>
                    <dd class="text-right font-semibold text-slate-900">{{ $customer->name }}</dd>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <dt class="text-slate-500">Monto</dt>
                    <dd class="text-right text-xl font-bold text-slate-900">@include('cobros.components.money', ['amount' => $preview['amount'], 'currency' => $account->currency_code])</dd>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <dt class="text-slate-500">Forma de pago</dt>
                    <dd class="text-right font-semibold text-slate-900">{{ $preview['method']?->name ?? '—' }}</dd>
                </div>
                @if($preview['reference'])
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500">Referencia</dt>
                        <dd class="break-all text-right font-semibold text-slate-900">{{ $preview['reference'] }}</dd>
                    </div>
                @endif
                <div class="flex items-start justify-between gap-3">
                    <dt class="text-slate-500">Saldo restante</dt>
                    <dd class="text-right font-bold text-slate-900">@include('cobros.components.money', ['amount' => $preview['remaining'], 'currency' => $account->currency_code])</dd>
                </div>
            </dl>

            <form method="POST" action="{{ route('cobros.collector.payments.store', ['account' => $account, 'visit' => $visit?->id]) }}" class="mt-4 space-y-3">
                @csrf
                <input type="hidden" name="amount" value="{{ $preview['amount'] }}">
                <input type="hidden" name="payment_method_id" value="{{ $preview['method']?->id }}">
                <input type="hidden" name="reference" value="{{ $preview['reference'] }}">
                <input type="hidden" name="notes" value="{{ $preview['notes'] }}">
                <input type="hidden" name="client_token" value="{{ $clientToken }}">
                <input type="hidden" name="confirm" value="1">
                <button type="submit"
                        class="flex min-h-[56px] w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-4 text-lg font-bold text-slate-950 hover:bg-primary-hover">
                    CONFIRMAR COBRO
                </button>
            </form>

            <a href="{{ route('cobros.collector.payments.create', ['account' => $account, 'visit' => $visit?->id]) }}"
               class="mt-3 flex min-h-[48px] w-full items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-3 text-base font-semibold text-slate-800 hover:bg-slate-50">
                CORREGIR DATOS
            </a>
            <p class="mt-3 text-center text-xs text-slate-500">
                El cobro no se ingresa a una caja: queda en custodia hasta la liquidación.
            </p>
        </div>
    @else
        {{-- Paso 1: captura operacional. --}}
        <form method="POST" action="{{ route('cobros.collector.payments.store', ['account' => $account, 'visit' => $visit?->id]) }}"
              class="space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
            @csrf

            <div>
                <label for="amount" class="block text-sm font-semibold text-slate-700">Monto a cobrar</label>
                <div class="mt-1 flex gap-2">
                    <input id="amount" name="amount" type="text" inputmode="decimal" autocomplete="off"
                           value="{{ old('amount') }}" required
                           class="min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-right text-lg font-semibold text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                    <button type="button" id="amount-total"
                            class="min-h-[48px] shrink-0 rounded-xl border border-slate-300 bg-slate-50 px-3 text-sm font-semibold text-slate-800 hover:bg-slate-100">
                        Saldo total
                    </button>
                </div>
                <p class="mt-1 text-xs text-slate-500">Parcial o total. Máximo @include('cobros.components.money', ['amount' => $account->balance_due, 'currency' => $account->currency_code]).</p>
            </div>

            <div>
                <label for="payment_method_id" class="block text-sm font-semibold text-slate-700">Forma de pago</label>
                <select id="payment_method_id" name="payment_method_id" required
                        class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-base text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                    @foreach($methods as $method)
                        <option value="{{ $method->id }}" data-requires-reference="{{ $method->requires_reference ? '1' : '0' }}"
                                @selected((string) old('payment_method_id') === (string) $method->id)>
                            {{ $method->name }}{{ $method->requires_reference ? ' (requiere referencia)' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div id="reference-wrap" class="hidden">
                <label for="reference" class="block text-sm font-semibold text-slate-700">Referencia</label>
                <input id="reference" name="reference" type="text" autocomplete="off" maxlength="150"
                       value="{{ old('reference') }}"
                       class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-base text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
            </div>

            <div>
                <label for="notes" class="block text-sm font-semibold text-slate-700">Nota (opcional)</label>
                <textarea id="notes" name="notes" rows="2" maxlength="500"
                          class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3 text-base text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">{{ old('notes') }}</textarea>
            </div>

            <input type="hidden" name="client_token" value="{{ $clientToken }}">

            <button type="submit"
                    class="flex min-h-[56px] w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-4 text-lg font-bold text-slate-950 hover:bg-primary-hover">
                REVISAR COBRO
            </button>
            <p class="text-center text-xs text-slate-500">Nada se registra hasta que confirme el detalle.</p>
        </form>
    @endif

    @if($customer->hasLocation())
        <a href="{{ $customer->googleMapsUrl() }}" target="_blank" rel="noopener"
           class="flex min-h-[48px] w-full items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-3 text-base font-semibold text-slate-800 hover:bg-slate-50">🧭 IR AL CLIENTE</a>
    @endif
</div>

<script>
    (function () {
        var amount = document.getElementById('amount');
        var total = document.getElementById('amount-total');
        var select = document.getElementById('payment_method_id');
        var referenceWrap = document.getElementById('reference-wrap');
        var reference = document.getElementById('reference');
        var balance = @json((string) $account->balance_due);

        function syncReference() {
            var option = select.options[select.selectedIndex];
            var required = option && option.getAttribute('data-requires-reference') === '1';
            referenceWrap.classList.toggle('hidden', !required);
            if (required) { reference.required = true; } else { reference.required = false; }
        }
        if (select) { select.addEventListener('change', syncReference); syncReference(); }
        if (total && amount) {
            total.addEventListener('click', function () {
                amount.value = balance.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
                amount.focus();
            });
        }
    })();
</script>
@endsection
