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

        <div id="renewal-receipt-box" class="mt-3 space-y-3" @if($modality !== 'partial_payment') hidden @endif>
            @if(request('receipt_number'))
                <div class="rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-900">
                    Abono registrado. Recibo <strong>{{ request('receipt_number') }}</strong>. Ya puede calcular la renovación con el saldo actualizado.
                </div>
                <input type="hidden" name="receipt_number" value="{{ request('receipt_number') }}">
            @else
                <p class="text-xs text-slate-700">Registre el abono aquí. El recibo se genera automáticamente y el saldo se actualiza antes de calcular la renovación.</p>
                @if($partialPaymentPreview)
                    <section class="rounded-xl border-2 border-amber-300 bg-amber-50 p-3">
                        <h3 class="font-bold">Confirmar cobro real</h3>
                        <p class="mt-2 text-sm">Monto: <strong>{{ number_format((float) $partialPaymentPreview['amount'], 2) }}</strong></p>
                        <p class="text-sm">Método: <strong>{{ $partialPaymentPreview['method']->name }}</strong></p>
                        @if($partialPaymentPreview['reference'])<p class="text-sm">Referencia: {{ $partialPaymentPreview['reference'] }}</p>@endif
                        <p class="mt-2 text-xs">Este botón sí registra dinero. La mora pendiente se aplica primero. Se emitirá un recibo automático; no se creará todavía un crédito nuevo.</p>
                        <form method="POST" action="{{ route('cobros.collector.renewal.partial-pay', $customer) }}" class="mt-3">
                            @csrf
                            <input type="hidden" name="amount" value="{{ $partialPaymentPreview['amount'] }}">
                            <input type="hidden" name="payment_method_id" value="{{ $partialPaymentPreview['method']->id }}">
                            <input type="hidden" name="reference" value="{{ $partialPaymentPreview['reference'] }}">
                            <input type="hidden" name="client_token" value="{{ $partialPaymentToken }}">
                            <input type="hidden" name="confirm" value="1">
                            <button type="submit" class="min-h-[48px] w-full rounded-xl bg-primary px-4 py-3 font-bold text-slate-950">Confirmar abono y generar recibo</button>
                        </form>
                    </section>
                @else
                    <form method="POST" action="{{ route('cobros.collector.renewal.partial-pay', $customer) }}" class="space-y-3 rounded-lg border border-slate-200 p-3">
                        @csrf
                        <input type="hidden" name="client_token" value="{{ $partialPaymentToken }}">
                        <label class="block text-sm font-semibold">Monto del abono (₡)
                            <input name="amount" type="text" inputmode="decimal" required value="{{ old('amount') }}" placeholder="Ej: 100000" class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3">
                        </label>
                        <label class="block text-sm font-semibold">Forma de pago
                            <select name="payment_method_id" id="renewal-payment-method" required class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3">
                                <option value="">Seleccione</option>
                                @foreach($partialPaymentMethods as $method)
                                <option value="{{ $method->id }}" data-requires-reference="{{ $method->requires_reference ? '1' : '0' }}" @selected((string) old('payment_method_id') === (string) $method->id)>{{ $method->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-semibold">Referencia (obligatoria cuando el método lo requiera)
                            <input name="reference" id="renewal-payment-reference" type="text" maxlength="150" value="{{ old('reference') }}" class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3">
                        </label>
                        @error('amount')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                        @error('payment_method_id')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                        @error('reference')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                        <button type="submit" class="min-h-[48px] w-full rounded-xl border border-primary px-4 py-3 font-semibold">Revisar abono antes de cobrar</button>
                    </form>
                @endif
            @endif
        </div>

    <form method="GET" action="{{ route('cobros.collector.renewal.create', $customer) }}" class="rounded-xl border border-slate-200 bg-white p-4">
        <label class="block text-sm font-semibold text-slate-700">Modalidad de renovación
            <select name="modality" id="renewal-modality" class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-2 text-base text-slate-900">
                <option value="difference" @selected($modality === 'difference')>Por diferencia — deuda pendiente + dinero nuevo</option>
                <option value="full" @selected($modality === 'full')>Completa — monto total a renovar</option>
                <option value="no_disbursement" @selected($modality === 'no_disbursement')>Sin desembolso — no entregar dinero nuevo</option>
                <option value="partial_payment" @selected($modality === 'partial_payment')>Con abono parcial — pago confirmado con recibo</option>
            </select>
        </label>
        <div id="renewal-example" class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700" aria-live="polite"></div>
        @if($modality === 'partial_payment' && request('receipt_number'))
            <input type="hidden" name="receipt_number" value="{{ request('receipt_number') }}">
        @endif
        @error('modality')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
        <label class="mt-3 block text-sm font-semibold text-slate-700" id="renewal-capital-label">Monto de renovación (₡)
            <input type="text" name="new_capital" inputmode="decimal" value="{{ in_array($modality, ['no_disbursement', 'partial_payment'], true) ? '0' : request('new_capital') }}"
                   placeholder="Ej: 50000" {{ in_array($modality, ['no_disbursement', 'partial_payment'], true) ? 'readonly' : 'required' }}
                   class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-2 text-base text-slate-900">
        </label>
        <label class="mt-3 block text-sm font-semibold text-slate-700">Cuotas del nuevo calendario
            <input type="number" name="installments" min="1" max="120" value="{{ request('installments', $credit->installment_count) }}"
                   class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-2 text-base text-slate-900">
        </label>
        <button type="submit" @if($modality === 'partial_payment' && !request('receipt_number')) disabled title="Primero confirme el abono" @endif class="mt-4 flex min-h-[48px] w-full items-center justify-center rounded-xl bg-primary px-4 py-3 text-base font-bold text-slate-950 hover:bg-primary-hover">Calcular renovación</button>
    </form>
    <script>
    (() => {
        const mode = document.getElementById('renewal-modality');
        const capital = document.querySelector('input[name="new_capital"]');
        const label = document.getElementById('renewal-capital-label');
        if (!mode || !capital || !label) return;
        const update = () => {
            const withoutCash = mode.value === 'no_disbursement' || mode.value === 'partial_payment';
            const receiptBox = document.getElementById('renewal-receipt-box');
            if (receiptBox) { receiptBox.hidden = mode.value !== 'partial_payment'; const receiptInput = receiptBox.querySelector('input[name="receipt_number"]'); if (receiptInput) receiptInput.required = mode.value === 'partial_payment'; }
            if (withoutCash) capital.value = '0';
            capital.readOnly = withoutCash;
            capital.required = !withoutCash;
            label.firstChild.textContent = mode.value === 'full'
                ? 'Monto total a renovar (incluye deuda anterior) (₡)'
                : (withoutCash ? 'Dinero nuevo a entregar: ₡0' : 'Dinero nuevo a entregar (₡)');
        };
        const example = document.getElementById('renewal-example');
        const examples = {
            difference: 'Ejemplo: deuda pendiente ₡340.000 + dinero nuevo ₡75.000 = ₡415.000 antes de nuevos intereses y cargos.',
            full: 'Ejemplo: deuda pendiente ₡340.000; monto total acordado ₡500.000; desembolso ilustrativo ₡160.000, antes de nuevos intereses y cargos.',
            no_disbursement: 'Ejemplo: deuda pendiente ₡340.000; se renueva sin entregar dinero adicional (₡0), antes de nuevos intereses y cargos.',
            partial_payment: 'Ejemplo: deuda ₡340.000, abono ₡100.000. El sistema emite el recibo automático y cotiza sobre el saldo realmente actualizado; sin desembolso nuevo.'
        };
        if (example) {
            const refreshExample = () => { example.textContent = examples[mode.value] || ''; };
            mode.addEventListener('change', refreshExample);
            refreshExample();
        }
        const paymentMethod = document.getElementById('renewal-payment-method');
        const paymentReference = document.getElementById('renewal-payment-reference');
        if (paymentMethod && paymentReference) {
            const refreshReference = () => { paymentReference.required = paymentMethod.selectedOptions[0]?.dataset.requiresReference === '1'; };
            paymentMethod.addEventListener('change', refreshReference); refreshReference();
        }
        mode.addEventListener('change', update);
        update();
    })();
    </script>

    @if($quote)
        <section aria-labelledby="quote-title" class="rounded-xl border border-slate-200 bg-white p-4">
            <h2 id="quote-title" class="text-lg font-semibold text-slate-900">Cotización de renovación</h2>

            <p class="mt-2 text-sm text-slate-600">Modalidad: <strong>{{ ['full' => 'Completa', 'difference' => 'Por diferencia', 'no_disbursement' => 'Sin desembolso', 'partial_payment' => 'Con abono parcial'][$modality] ?? $modality }}</strong></p>
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
                <input type="hidden" name="modality" value="{{ $modality }}">
                @if($modality === 'partial_payment')<input type="hidden" name="receipt_number" value="{{ request('receipt_number') }}">@endif
                <input type="hidden" name="new_capital" value="{{ in_array($modality, ['no_disbursement', 'partial_payment'], true) ? '0' : request('new_capital') }}">
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
