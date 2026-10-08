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

    @if($loan)
    {{-- 9B.1: préstamo a cobrar en lenguaje humano, sin IDs internos. --}}
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 sm:p-6">
        <p class="text-xs font-medium uppercase tracking-wide text-amber-700">Préstamo</p>
        <dl class="mt-2 space-y-1 text-sm">
            <div class="flex items-start justify-between gap-3">
                <dt class="text-amber-800">Referencia del préstamo</dt>
                <dd class="font-bold text-slate-900">{{ $loan['reference'] }}</dd>
            </div>
            <div class="flex items-start justify-between gap-3">
                <dt class="text-amber-800">Saldo del préstamo</dt>
                <dd class="font-semibold text-slate-900">@include('cobros.components.money', ['amount' => $loan['saldo'], 'currency' => $account->currency_code])</dd>
            </div>
            @if($loan['installment'])
            <div class="flex items-start justify-between gap-3">
                <dt class="text-amber-800">Próxima cuota</dt>
                <dd class="font-semibold text-slate-900">@include('cobros.components.money', ['amount' => $loan['installment']->contractual_amount, 'currency' => $account->currency_code]) · {{ $loan['installment']->due_date?->format('d/m/Y') }}</dd>
            </div>
            <div class="flex items-start justify-between gap-3">
                <dt class="text-amber-800">Pendiente de esta cuota</dt>
                <dd class="font-bold text-slate-900">@include('cobros.components.money', ['amount' => $loan['pending'], 'currency' => $account->currency_code])</dd>
            </div>
            @else
            <p class="text-sm font-semibold text-slate-700">Este préstamo no tiene cuotas pendientes.</p>
            @endif
        </dl>
        @if($lateFee)
        {{-- 9B.3: mora pendiente; al confirmar se cobra PRIMERO, antes que la cuota. --}}
        <div class="mt-3 border-t border-amber-200 pt-3 text-sm">
            @if($lateFee['state'] === 'overdue')
            <dl class="space-y-1">
                <div class="flex items-start justify-between gap-3">
                    <dt class="text-amber-800">Cuota pendiente</dt>
                    <dd class="font-semibold text-slate-900">@include('cobros.components.money', ['amount' => $lateFee['pending'], 'currency' => $account->currency_code])</dd>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <dt class="text-amber-800">Mora pendiente</dt>
                    <dd class="font-semibold text-slate-900">@include('cobros.components.money', ['amount' => $lateFee['amount'], 'currency' => $account->currency_code])</dd>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <dt class="font-bold text-slate-700">Total pendiente hoy</dt>
                    <dd class="font-bold text-slate-900">@include('cobros.components.money', ['amount' => $lateFee['total'], 'currency' => $account->currency_code])</dd>
                </div>
            </dl>
            <p class="mt-2 font-semibold text-amber-700">Esta cuota tiene @include('cobros.components.money', ['amount' => $lateFee['amount'], 'currency' => $account->currency_code]) de mora por atraso.</p>
            <p class="mt-1 text-xs text-slate-500">Al confirmar, la mora se aplica primero y luego el resto a la cuota.</p>
            @else
            <p class="text-xs text-slate-600">Todavía está dentro de los días de gracia.</p>
            @endif
        </div>
        @endif
    </div>
    @endif

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
                @if($plan)
                <p class="text-sm font-semibold text-slate-700">{{ !empty($plan['late_fee']) ? 'Este pago se aplicará así:' : 'Este pago se distribuirá así:' }}</p>
                @if(!empty($plan['late_fee']))
                <div class="flex items-start justify-between gap-3">
                    <dt class="text-amber-700">
                        Mora por atraso — <span class="font-semibold">se cobra primero</span>
                    </dt>
                    <dd class="text-right font-semibold text-slate-900">@include('cobros.components.money', ['amount' => $plan['late_fee']['applies'], 'currency' => $account->currency_code])
                        @if(bccomp($plan['late_fee']['applies'], $plan['late_fee']['pending'], 4) < 0) · @include('cobros.components.money', ['amount' => bcsub($plan['late_fee']['pending'], $plan['late_fee']['applies'], 4), 'currency' => $account->currency_code]) queda @endif
                    </dd>
                </div>
                @endif
                @foreach($plan['lines'] as $line)
                <div class="flex items-start justify-between gap-3">
                    <dt class="text-slate-500">
                        Cuota {{ $line['number'] }} —
                        @if($line['completes'])
                        <span class="font-semibold text-green-700">queda pagada ✓</span>
                        @else
                        <span class="font-semibold text-slate-700">quedan</span>
                        @endif
                    </dt>
                    <dd class="text-right font-semibold text-slate-900">@include('cobros.components.money', ['amount' => $line['applies'], 'currency' => $account->currency_code])
                        @if(!$line['completes']) · @include('cobros.components.money', ['amount' => $line['pending_after'], 'currency' => $account->currency_code]) @endif
                    </dd>
                </div>
                @endforeach
                <div class="flex items-start justify-between gap-3">
                    <dt class="text-slate-500">Saldo después del pago</dt>
                    <dd class="text-right font-bold text-slate-900">@include('cobros.components.money', ['amount' => $plan['saldo_after'], 'currency' => $account->currency_code])</dd>
                </div>
                @endif
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
                @if($preview['tendered'] ?? null)
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500">Paga con</dt>
                        <dd class="text-right font-semibold text-slate-900">@include('cobros.components.money', ['amount' => $preview['tendered'], 'currency' => $account->currency_code])</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500">Vuelto</dt>
                        <dd class="text-right font-semibold text-slate-900">@include('cobros.components.money', ['amount' => $preview['change'], 'currency' => $account->currency_code])</dd>
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
                <input type="hidden" name="tendered" value="{{ $preview['tendered'] ?? '' }}">
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
                                data-type="{{ $method->type }}"
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

            {{-- Solo Efectivo: paga con + vuelto informativo (no toca dinero registrado). --}}
            <div id="tendered-wrap" class="hidden">
                <label for="tendered" class="block text-sm font-semibold text-slate-700">Paga con</label>
                <input id="tendered" name="tendered" type="text" inputmode="decimal" autocomplete="off"
                       value="{{ old('tendered') }}"
                       class="mt-1 min-h-[56px] w-full rounded-xl border border-slate-300 px-3 py-3 text-right text-2xl font-bold text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                <p class="mt-2 text-base font-semibold text-slate-700">Vuelto: <span id="tendered-change">—</span></p>
                <p id="tendered-error" role="alert" class="mt-1 hidden text-sm font-semibold text-red-600">
                    El efectivo recibido no puede ser menor al monto cobrado.
                </p>
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
        <a href="{{ $customer->google_maps_url }}" target="_blank" rel="noopener"
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
        var tenderedWrap = document.getElementById('tendered-wrap');
        var tendered = document.getElementById('tendered');
        var tenderedChange = document.getElementById('tendered-change');
        var tenderedError = document.getElementById('tendered-error');
        var form = tendered ? tendered.closest('form') : null;
        var balance = @json((string) $account->balance_due);
        var sign = @json($account->currency_code === 'CRC' ? '₡' : $account->currency_code.' ');

        function syncReference() {
            var option = select.options[select.selectedIndex];
            var required = option && option.getAttribute('data-requires-reference') === '1';
            referenceWrap.classList.toggle('hidden', !required);
            if (required) { reference.required = true; } else { reference.required = false; }
        }

        // Enteros de 1/10000: sin floats para dinero.
        function units(value) {
            var m = /^(\d{1,12})(?:\.(\d{1,4}))?$/.exec(String(value == null ? '' : value).trim());
            if (!m) { return null; }
            var fraction = m[2] || '';
            while (fraction.length < 4) { fraction += '0'; }
            return parseInt(m[1], 10) * 10000 + parseInt(fraction, 10);
        }

        function money(value) {
            var cents = Math.round(Math.abs(value) / 100);
            var whole = Math.floor(cents / 100);
            var fraction = cents % 100;
            return (value < 0 ? '-' : '') + sign
                + String(whole).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
                + '.' + (fraction < 10 ? '0' + fraction : fraction);
        }

        function syncTendered() {
            var option = select.options[select.selectedIndex];
            var isCash = option && option.getAttribute('data-type') === 'cash';
            tenderedWrap.classList.toggle('hidden', !isCash);
            recalcTendered();
        }

        function recalcTendered() {
            var a = units(amount.value);
            var t = units(tendered.value);
            if (t === null || a === null) {
                tenderedChange.textContent = '—';
                tenderedError.classList.add('hidden');
                return;
            }
            tenderedChange.textContent = money(t - a);
            tenderedError.classList.toggle('hidden', t >= a);
        }

        if (select) {
            select.addEventListener('change', syncReference);
            select.addEventListener('change', syncTendered);
            syncReference();
            syncTendered();
        }
        if (tendered) {
            tendered.addEventListener('input', recalcTendered);
            recalcTendered();
        }
        if (amount) { amount.addEventListener('input', recalcTendered); }
        if (form) {
            form.addEventListener('submit', function (event) {
                var a = units(amount.value);
                var t = units(tendered.value);
                if (! tenderedWrap.classList.contains('hidden') && t !== null && a !== null && t < a) {
                    event.preventDefault();
                    tenderedError.classList.remove('hidden');
                    tendered.focus();
                }
            });
        }
        if (total && amount) {
            total.addEventListener('click', function () {
                amount.value = balance.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
                amount.focus();
            });
        }
    })();
</script>
@endsection
