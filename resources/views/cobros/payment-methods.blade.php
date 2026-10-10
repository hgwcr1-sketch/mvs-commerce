@extends('cobros.layout')

@section('title', 'Métodos de pago · MVS CobrOS')

@section('content')
@php
    use App\Services\Cobros\CobrosModalityCatalog;
    use App\Support\Cobros\Money;

    // Anti doble escape: si un dato llega ya con entidades HTML
    // (ej. prestamist&#x61;) se decodifica UNA sola vez y Blade vuelve a
    // escapar con {{ }}. El valor escapado jamás se imprime con {!! !!}.
    $plain = static fn ($value): string => html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $statusLabels = [
        \App\Models\CobrosBillingContract::STATUS_ACTIVE => 'Activa',
        'draft' => 'Borrador',
        'cancelled' => 'Cancelada',
    ];
    $statusLabel = $plain($statusLabels[$contract->status] ?? $contract->status);

    $modeLabels = [
        \App\Models\CobrosBillingContract::MODE_INDIVIDUAL => 'Individual',
        \App\Models\CobrosBillingContract::MODE_ENTERPRISE => 'Empresarial',
        \App\Models\CobrosBillingContract::MODE_MIXED => 'Mixto',
    ];
    $modeLabel = $plain($modeLabels[$contract->billing_mode] ?? $contract->billing_mode);

    $modalityLabel = $contract->modality !== null && CobrosModalityCatalog::has($contract->modality)
        ? $plain(CobrosModalityCatalog::label($contract->modality))
        : null;

    $seats = max(1, (int) $quote->seats);

    // Nombre visible del método: siempre "SINPE Móvil" (el dato configurado
    // solo se conserva para el flag de referencia).
    $sinpeName = 'SINPE Móvil';
    $sinpeRecipient = $plain($sinpeConfig['recipient_name'] ?? 'MARIO VEGA SAENZ');
    $sinpePhone = $plain($sinpeConfig['phone_number'] ?? '88133806');
    $paymentReference = $plain($paymentReference);

    // Teléfono internacional para wa.me (misma regla CR +506 usada en CobrOS).
    $sinpeDigits = preg_replace('/\D/', '', $sinpePhone) ?? '';
    if ($sinpeDigits === '') {
        $sinpeDigits = '88133806';
    }
    if (strlen($sinpeDigits) === 8) {
        $sinpeDigits = '506'.$sinpeDigits;
    } elseif (strlen($sinpeDigits) > 10 && ! str_starts_with($sinpeDigits, '506')) {
        $sinpeDigits = '506'.substr($sinpeDigits, -8);
    }

    $whatsappMessage = "Comprobante de pago MVS CobrOS\n"
        .'Empresa: '.$company->trade_name."\n"
        .'Método: '.$sinpeName."\n"
        .'Referencia de pago: '.$paymentReference."\n"
        .'Importe mensual: '.$quote->formattedTotal()."\n"
        ."Adjunto el comprobante de la transferencia.";
    $whatsappUrl = 'https://wa.me/'.$sinpeDigits.'?text='.rawurlencode($whatsappMessage);
@endphp

<div class="space-y-5 pb-8">
    <a href="{{ url()->previous() }}"
       onclick="if (history.length > 1) { event.preventDefault(); history.back(); }"
       class="inline-flex min-h-[44px] items-center text-sm font-semibold text-primary">← Volver</a>

    <header class="rounded-xl border border-primary/30 bg-white p-4 shadow-sm sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-bold text-slate-900">Métodos de pago</h1>
            <span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-bold text-primary">{{ $statusLabel }}</span>
        </div>
        <p class="mt-1 text-sm text-slate-500">Elija cómo desea pagar la suscripción de MVS CobrOS. Esta pantalla solo presenta opciones: no inicia cobros.</p>
    </header>

    {{-- 1. Resumen del contrato e importe mensual --}}
    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" aria-label="Resumen del contrato">
        <h2 class="text-lg font-bold text-slate-900">Resumen del contrato</h2>

        <dl class="mt-3 space-y-2 text-sm">
            <div class="flex items-start justify-between gap-3">
                <dt class="text-slate-500">Empresa</dt>
                <dd class="text-right font-semibold text-slate-800">{{ $company->trade_name }}</dd>
            </div>
            <div class="flex items-start justify-between gap-3">
                <dt class="text-slate-500">Modalidad</dt>
                <dd class="text-right">
                    <span class="block font-semibold text-slate-800">{{ $modalityLabel ?? $modeLabel }}</span>
                    <span class="mt-1.5 flex flex-wrap justify-end gap-1.5">
                        @if($modalityLabel !== null)
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ $modeLabel }}</span>
                        @endif
                        <span class="inline-flex items-center rounded-full bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary">
                            {{ $seats }} {{ $seats === 1 ? 'cupo' : 'cupos' }}
                        </span>
                    </span>
                </dd>
            </div>
            @if($contract->valid_from !== null || $contract->valid_until !== null)
            <div class="flex items-start justify-between gap-3">
                <dt class="text-slate-500">Vigencia</dt>
                <dd class="text-right font-semibold text-slate-800">
                    {{ optional($contract->valid_from)->format('d/m/Y') }} — {{ optional($contract->valid_until)->format('d/m/Y') }}
                </dd>
            </div>
            @endif
        </dl>

        <div class="mt-4 rounded-xl bg-primary/10 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-primary">Importe mensual</p>
            <p class="mt-1 text-3xl font-bold text-slate-900">{{ $quote->formattedTotal() }}</p>
        </div>

        @if(count($quote->lines) > 0)
        <details class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
            <summary class="min-h-[44px] cursor-pointer select-none text-sm font-semibold text-slate-700">Ver desglose del importe</summary>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach($quote->lines as $line)
                <li class="flex items-start justify-between gap-3">
                    <span class="text-slate-600">{{ $plain($line->label) }}</span>
                    <span class="shrink-0 font-semibold text-slate-800">{{ Money::human($line->amount, $quote->currency) }}</span>
                </li>
                @endforeach
            </ul>
        </details>
        @endif
    </section>

    {{-- 2. Stripe: habilitado SOLO con credenciales Stripe TEST (17B-4C) --}}
    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" aria-label="Pago con tarjeta">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Tarjeta de crédito o débito</h2>
                <p class="mt-1 text-sm text-slate-500">
                    @if($stripeTestReady)
                        Procesado por Stripe en modo TEST. Sus datos de tarjeta nunca se guardan en MVS CobrOS.
                    @elseif($stripeAvailable)
                        Stripe está configurado pero no en modo TEST: el pago con tarjeta permanece deshabilitado.
                    @elseif(isset($effectiveProviderKey) && $effectiveProviderKey !== null)
                        El proveedor de pago seleccionado para este país ({{ $effectiveProviderKey }}) no permite pago con tarjeta en línea.
                    @else
                        Stripe aún no está configurado para esta empresa.
                    @endif
                </p>
            </div>
            <span class="shrink-0 rounded-full {{ $stripeTestReady ? 'bg-primary/10 text-primary' : 'bg-slate-100 text-slate-500' }} px-3 py-1 text-xs font-bold">
                {{ $stripeTestReady ? 'Stripe TEST' : 'No disponible' }}
            </span>
        </div>

        @error('checkout')
        <p class="mt-3 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">{{ $message }}</p>
        @enderror

        @if($stripeTestReady)
        <form method="POST" action="{{ route('cobros.payment.checkout', $contract) }}" class="mt-4">
            @csrf
            <button type="submit"
                    class="min-h-[44px] w-full rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover sm:w-auto">
                Pagar con tarjeta (Stripe TEST)
            </button>
        </form>
        <p class="mt-2 text-xs text-slate-500">Se abre el checkout seguro de Stripe. La licencia se activa únicamente cuando verificamos el pago.</p>
        @else
        <button type="button" disabled aria-disabled="true"
                class="mt-4 min-h-[44px] w-full cursor-not-allowed rounded-xl bg-slate-200 px-5 text-sm font-bold text-slate-500 sm:w-auto">
            Pagar con tarjeta
        </button>
        <p class="mt-2 text-xs text-slate-500">Se habilitará cuando Stripe TEST tenga sus credenciales configuradas.</p>
        @endif
    </section>

    {{-- 3. SINPE Móvil --}}
    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" aria-label="Pago por SINPE Móvil">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-bold text-slate-900">SINPE Móvil</h2>
                <p class="mt-1 text-sm text-slate-500">Realizá un SINPE Móvil al número indicado por el importe mensual y guardá el comprobante.</p>
            </div>
            <span class="shrink-0 rounded-full bg-primary/10 px-3 py-1 text-xs font-bold text-primary">Disponible</span>
        </div>

        <dl class="mt-3 space-y-3 text-sm">
            <div class="flex items-start justify-between gap-3">
                <dt class="text-slate-500">Beneficiario</dt>
                <dd class="text-right font-semibold text-slate-800">{{ $sinpeRecipient }}</dd>
            </div>
            <div class="flex flex-col gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Número SINPE Móvil</dt>
                    <dd class="mt-1 font-mono text-xl font-bold tracking-wider text-slate-900">{{ $sinpePhone }}</dd>
                </div>
                <button type="button" data-copy="{{ $sinpePhone }}"
                        class="min-h-[44px] w-full shrink-0 rounded-xl border border-primary px-5 text-sm font-bold text-primary hover:bg-primary/10 sm:w-auto">
                    Copiar número
                </button>
            </div>
            @if($sinpeMethod !== null && $sinpeMethod->requires_reference)
            <div class="flex items-start justify-between gap-3">
                <dt class="text-slate-500">Requiere referencia</dt>
                <dd class="text-right font-semibold text-slate-800">Sí</dd>
            </div>
            @endif
        </dl>

        {{-- 17B-5A: declaración de transferencia realizada (solo solicitud pendiente) --}}
        @if($sinpePending)
        <div class="mt-3 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900" role="status">
            Comprobante registrado: solicitud pendiente de verificación. La licencia se activa únicamente cuando confirmemos el pago.
        </div>
        @else
        <form method="POST" action="{{ route('cobros.payment.sinpe.report', $contract) }}" class="mt-4">
            @csrf
            <button type="submit"
                    class="min-h-[44px] w-full rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover sm:w-auto">
                Ya realicé la transferencia
            </button>
            <p class="mt-2 text-xs text-slate-500">
                Registramos tu solicitud como pendiente. No activa la licencia: primero verificamos el pago manualmente.
            </p>
        </form>
        @endif
    </section>

    {{-- 4. Referencia de pago --}}
    <section class="rounded-xl border border-primary/40 bg-white p-4 shadow-sm sm:p-6" aria-label="Referencia de pago">
        <h2 class="text-lg font-bold text-slate-900">Referencia de pago</h2>
        <p class="mt-1 text-sm text-slate-500">Use esta referencia en el concepto de la transferencia para identificarlo durante la verificación manual.</p>

        <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
            <output class="min-h-[48px] flex-1 rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 font-mono text-base font-bold tracking-wide text-slate-900 break-all">{{ $paymentReference }}</output>
            <button type="button" data-copy="{{ $paymentReference }}"
                    class="min-h-[44px] shrink-0 rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover">
                Copiar referencia
            </button>
        </div>
    </section>

    {{-- 5. WhatsApp: enviar comprobante --}}
    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" aria-label="Enviar comprobante por WhatsApp">
        <h2 class="text-lg font-bold text-slate-900">Enviar comprobante</h2>
        <p class="mt-1 text-sm text-slate-500">
            Adjunte la captura o el PDF de la transferencia. El mensaje se prepara con su empresa, importe y referencia.
        </p>

        <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer"
           class="mt-4 inline-flex min-h-[44px] w-full items-center justify-center rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover sm:w-auto">
            Enviar comprobante por WhatsApp
        </a>
    </section>

    {{-- 6. Aviso de activación --}}
    <section class="rounded-xl border border-amber-300 bg-amber-50 p-4 sm:p-6" aria-label="Aviso de activación">
        <h2 class="text-lg font-bold text-amber-900">Activación tras verificar el pago</h2>
        <p class="mt-1 text-sm text-amber-900">
            Tu licencia se activará cuando confirmemos tu pago manualmente. Envía el comprobante y
            espera nuestra confirmación: enviar el mensaje por WhatsApp no activa la licencia por sí solo.
            La verificación y la activación las realiza el equipo MVS.
        </p>
    </section>

    {{-- 7. Depósito bancario: oculto en este paso --}}
    <section hidden aria-label="Depósito bancario">
        <h2 class="text-lg font-bold text-slate-900">Depósito bancario</h2>
        <p class="mt-1 text-sm text-slate-500">
            {{ $bankTransferMethod !== null ? $bankTransferMethod->name : 'Depósito bancario' }}: se habilitará en un paso posterior.
        </p>
    </section>
</div>

<script>
    (function () {
        function fallbackCopy(text) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', 'readonly');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(area);
            return ok;
        }

        document.querySelectorAll('[data-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                var text = button.getAttribute('data-copy') || '';
                var original = button.textContent;
                var done = function () {
                    button.textContent = 'Copiado ✓';
                    window.setTimeout(function () { button.textContent = original; }, 2000);
                };

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(done, function () {
                        if (fallbackCopy(text)) { done(); }
                    });
                } else if (fallbackCopy(text)) {
                    done();
                }
            });
        });
    })();
</script>
@endsection
