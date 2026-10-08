@extends('cobros.layout')

@section('title', 'Recibo '.$receipt->receipt_number.' — MVS CobrOS')

@section('content')
{{-- Fase 3B: recibo único por empresa. Solo lectura: no modifica saldos ni caja. --}}
<div class="mx-auto max-w-2xl space-y-4">
    @if(session('cobros_reversal'))
        <div role="status" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">
            Cobro anulado. Reverso {{ session('cobros_reversal') }}@if(session('cobros_reversal_replay')) (ya estaba registrado)@endif.
        </div>
    @endif
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
                <dd class="text-right font-bold text-slate-900">@include('cobros.components.money', ['amount' => $receipt->accountReceivable->fresh()->balance_due, 'currency' => $receipt->currency_code])</dd>
            </div>
            @if(!empty($loanApplication))
            {{-- 9B.1/9B.3: aplicación del cobro al préstamo, en lenguaje humano. --}}
            <div class="mt-3 space-y-1 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm">
                <p class="font-bold uppercase tracking-wide text-amber-700">Pago registrado ✓ · Préstamo {{ $loanApplication['reference'] }}</p>
                <div class="flex items-start justify-between gap-3">
                    <span class="text-amber-800">Aplicado al préstamo</span>
                    <span class="font-semibold text-slate-900">@include('cobros.components.money', ['amount' => $loanApplication['amount'], 'currency' => $receipt->currency_code])</span>
                </div>
                @if(bccomp((string) $loanApplication['mora'], '0', 4) > 0)
                <div class="flex items-start justify-between gap-3">
                    <span class="text-amber-800">Mora pagada</span>
                    <span class="font-semibold text-slate-900">@include('cobros.components.money', ['amount' => $loanApplication['mora'], 'currency' => $receipt->currency_code])</span>
                </div>
                @endif
                @foreach($loanApplication['lines'] as $line)
                <div class="flex items-start justify-between gap-3">
                    <span class="text-amber-800">Cuota {{ $line['number'] }}: @include('cobros.components.money', ['amount' => $line['amount'], 'currency' => $receipt->currency_code])
                        @if($line['completes'])
                        <span class="font-semibold text-green-700">· queda pagada ✓</span>
                        @else
                        <span class="font-semibold text-amber-700">· pendiente @include('cobros.components.money', ['amount' => $line['pending_after'], 'currency' => $receipt->currency_code])</span>
                        @endif
                    </span>
                </div>
                @endforeach
                <div class="flex items-start justify-between gap-3">
                    <span class="text-amber-800">Saldo del préstamo</span>
                    <span class="font-bold text-slate-900">@include('cobros.components.money', ['amount' => $loanApplication['saldo'], 'currency' => $receipt->currency_code])</span>
                </div>
                @if($loanApplication['cancelled'])
                <p class="font-bold text-green-700">Préstamo cancelado ✓</p>
                @else
                <div class="flex items-start justify-between gap-3">
                    <span class="text-amber-800">Próximo pago</span>
                    <span class="font-semibold text-slate-900">{{ $loanApplication['next_due'] }}</span>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <span class="text-amber-800">Monto pendiente</span>
                    <span class="font-semibold text-slate-900">@include('cobros.components.money', ['amount' => $loanApplication['next_amount'], 'currency' => $receipt->currency_code])</span>
                </div>
                @endif
            </div>
            @endif
        </dl>

        @if($receipt->annulled_at)
            <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                <p class="font-bold uppercase tracking-wide">Cobro anulado</p>
                <p class="mt-1 break-words">Motivo: {{ $receipt->annul_reason }}</p>
                <p class="mt-1">{{ $receipt->annulledBy?->name }} · {{ $receipt->annulled_at->timezone($company->timezone)->format('d/m/Y H:i') }}</p>
                @if($reversal)
                    <p class="mt-1 break-all">Reverso: {{ $reversal->reversal_number }}</p>
                @endif
            </div>
        @endif

        <p class="mt-4 text-center text-xs text-slate-500">
            Este cobro no se ingresó a una sesión de caja: queda en custodia hasta la liquidación.
        </p>
    </div>

    {{-- Acciones grandes: WhatsApp (si hay teléfono) + impresión del recibo formal. --}}
    <div class="space-y-3">
        @if($whatsappUrl)
            <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener"
               class="flex min-h-[56px] w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-4 text-lg font-bold text-white hover:bg-green-700">
                🟢 ENVIAR POR WHATSAPP
            </a>
        @else
            <button type="button" disabled aria-disabled="true"
                    class="flex min-h-[56px] w-full cursor-not-allowed items-center justify-center gap-2 rounded-xl bg-slate-200 px-4 py-4 text-lg font-bold text-slate-500">
                🟢 ENVIAR POR WHATSAPP
            </button>
            <p class="text-center text-sm font-semibold text-slate-600">
                Sin teléfono registrado en el cliente: no se puede enviar por WhatsApp.
            </p>
            {{-- Captura in-situ del teléfono (cliente o contacto indicado). Sin JS: <details>. --}}
            <details class="rounded-xl border-2 border-primary bg-white">
                <summary class="flex min-h-[56px] w-full cursor-pointer list-none items-center justify-center gap-2 rounded-xl bg-primary px-4 py-4 text-lg font-bold text-slate-950 hover:bg-primary-hover">
                    ➕ AGREGAR TELÉFONO
                </summary>
                <form method="POST" action="{{ route('cobros.collector.customer.phone', $receipt->customer) }}" class="space-y-3 p-4">
                    @csrf
                    <input type="hidden" name="receipt" value="{{ $receipt->receipt_number }}">
                    <div>
                        <label for="phone" class="block text-sm font-semibold text-slate-700">Teléfono (cliente o contacto indicado)</label>
                        <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required maxlength="30"
                               value="{{ old('phone') }}" placeholder="Ej. 8888 8888 o +506 8888 8888"
                               class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-base text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                        @error('phone')
                            <p role="alert" class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-slate-500">No se envían documentos ni cédulas.</p>
                    </div>
                    <button type="submit"
                            class="flex min-h-[52px] w-full items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-3 text-base font-bold text-white hover:bg-slate-700">
                        GUARDAR TELÉFONO
                    </button>
                </form>
            </details>
        @endif
        <a href="{{ route('cobros.collector.payments.receipt.print', $receipt->receipt_number) }}" target="_blank" rel="noopener"
           class="flex min-h-[56px] w-full items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-4 text-lg font-bold text-slate-800 hover:bg-slate-50">
            🖨️ IMPRIMIR
        </a>
    </div>

    @if($mayAnnul)
        <form method="POST" action="{{ route('cobros.collector.payments.annul', $receipt->receipt_number) }}"
              class="space-y-3 rounded-xl border border-red-200 bg-white p-4 sm:p-6">
            @csrf
            <p class="text-sm font-semibold text-red-700">
                Anular este cobro no borra el pago: devuelve el saldo, crea un reverso auditable y deja constancia de quién y por qué.
            </p>
            @if($errors->any())
                <ul class="list-inside list-disc space-y-1 text-sm font-semibold text-red-700">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
            <div>
                <label for="reason" class="block text-sm font-semibold text-slate-700">Motivo de la anulación</label>
                <textarea id="reason" name="reason" rows="2" minlength="8" maxlength="250" required
                          class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3 text-base text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40"></textarea>
            </div>
            <input type="hidden" name="client_token" value="{{ $annulToken }}">
            <button type="submit"
                    class="flex min-h-[52px] w-full items-center justify-center gap-2 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-base font-bold text-red-700 hover:bg-red-100">
                ANULAR COBRO
            </button>
        </form>
    @endif
</div>
@endsection
