@extends('cobros.layout')

@section('title', $customer->name.' — Mi cartera — MVS CobrOS')

@section('content')
<div class="space-y-4">
    <a href="{{ route('cobros.collector.portfolio') }}" class="inline-flex min-h-[44px] items-center gap-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
        ← Volver a mi cartera
    </a>

    @if(session('renewal_status') === 'pending')
        <div role="status" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
            ⏳ Renovación registrada. <strong>Pendiente de aprobación</strong> del prestamista. No se ha creado crédito nuevo ni desembolsado dinero.
        </div>
    @elseif(session('renewal_status') === 'approved')
        <div role="status" class="rounded-xl border border-green-300 bg-green-50 px-4 py-3 text-sm font-semibold text-green-900">
            ✅ Renovación aprobada. Pendiente de consentimiento y firma del cliente para la activación (fase posterior). No se ha creado crédito nuevo todavía.
        </div>
    @endif


    {{-- Centro de trabajo del cobrador: identidad, contacto y cobro en una pantalla. --}}
    <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <h1 class="break-words text-3xl font-bold leading-tight text-slate-900"><span class="inline-flex items-center gap-2">@include('cobros.components.credit-risk', ['customer' => $customer, 'company' => $company, 'size' => 'lg'])<span>{{ $customer->name }}</span></span></h1>
        <p class="mt-2 text-base font-semibold text-slate-700">
            {{ $customer->identification ?: 'Identificación pendiente' }}
        </p>

        @if($customer->phone || $customer->mobile)
            <p class="mt-1 break-words text-lg text-slate-600">{{ $customer->phone ?: $customer->mobile }}</p>
        @endif

        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            @if($customer->phone || $customer->mobile)
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $customer->phone ?: $customer->mobile) }}"
                   class="flex min-h-[48px] items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-3 text-base font-bold text-slate-800 hover:bg-slate-50">📞 LLAMAR</a>
            @endif
            @if($whatsappUrl)
                <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener"
                   class="flex min-h-[48px] items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-3 text-base font-bold text-white hover:bg-green-700">🟢 WHATSAPP</a>
            @endif
        </div>

        @unless($whatsappUrl)
            {{-- Mismo flujo/campo que el recibo: reutiliza cobros.collector.customer.phone. --}}
            <details class="mt-3 rounded-xl border-2 border-primary bg-white">
                <summary class="flex min-h-[48px] w-full cursor-pointer list-none items-center justify-center gap-2 rounded-xl bg-primary px-4 py-3 text-base font-bold text-slate-950 hover:bg-primary-hover">
                    ➕ AGREGAR TELÉFONO
                </summary>
                <form method="POST" action="{{ route('cobros.collector.customer.phone', $customer) }}" class="space-y-3 p-3">
                    @csrf
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
                            class="flex min-h-[48px] w-full items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-3 text-base font-bold text-white hover:bg-slate-700">
                        GUARDAR TELÉFONO
                    </button>
                </form>
            </details>
        @endunless

        <div class="mt-4 rounded-xl bg-slate-50 p-3 text-center">
            <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Saldo total</span>
            <span class="mt-1 block text-4xl font-extrabold text-slate-900">@include('cobros.components.money', ['amount' => $totalBalance, 'currency' => $company->currency])</span>
        </div>

        @if(isset($chargeableAccounts[$customer->id]))
            <a href="{{ route('cobros.collector.payments.create', $chargeableAccounts[$customer->id]) }}"
               class="mt-3 flex min-h-[56px] w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-4 text-xl font-bold text-slate-950 hover:bg-primary-hover">💰 COBRAR</a>
        @endif
        @if($credits->contains(fn ($c) => $c->status === \App\Models\CobrosCredit::STATUS_DISBURSED))
            <a href="{{ route('cobros.collector.renewal.create', $customer) }}"
               class="mt-3 flex min-h-[48px] w-full items-center justify-center gap-2 rounded-xl border-2 border-primary bg-white px-4 py-3 text-base font-bold text-slate-900 hover:bg-primary/10">🔄 RENOVAR CRÉDITO</a>
        @endif
    </div>

    {{-- Resumen: próxima promesa / reprogramación si existe. --}}
    @if($nextVisit)
        <div role="status" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-base font-semibold text-amber-900">
            @if($nextVisit->status === \App\Models\CobrosVisit::STATUS_PROMISE && $nextVisit->promise_date)
                🤝 Promesa de pago: {{ $nextVisit->promise_date->format('d/m/Y') }}
            @else
                📅 Próxima visita: {{ $nextVisit->scheduled_for->format('d/m/Y') }}
            @endif
        </div>
    @endif

    {{-- Ficha central: resumen + secciones desplegables (reutiliza datos del controlador). --}}
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-sm text-slate-600">Calificación crediticia: <strong class="text-slate-900">Sin calificar</strong></p>
        @if(!empty($nextDueDate))<p class="mt-1 text-sm text-slate-600">Próximo pago: <strong>{{ \Carbon\Carbon::parse($nextDueDate)->format('d/m/Y') }}</strong></p>@endif
    </div>

    <section aria-labelledby="gestion-rapida">
        <h2 id="gestion-rapida" class="text-lg font-semibold text-slate-900">Gestión rápida</h2>
        <div class="mt-3 grid grid-cols-2 gap-2">
            <form method="POST" action="{{ route('cobros.visits.manage', $customer) }}">
                @csrf
                <input type="hidden" name="status" value="visitado">
                <button type="submit" class="flex min-h-[48px] w-full items-center justify-center rounded-xl bg-green-600 px-2 py-3 text-base font-bold text-white hover:bg-green-700 cursor-pointer">✅ VISITADO</button>
            </form>
            <form method="POST" action="{{ route('cobros.visits.manage', $customer) }}">
                @csrf
                <input type="hidden" name="status" value="no_localizado">
                <button type="submit" class="flex min-h-[48px] w-full items-center justify-center rounded-xl bg-slate-600 px-2 py-3 text-base font-bold text-white hover:bg-slate-700 cursor-pointer">🚪 NO ESTABA</button>
            </form>
            <button type="button" data-gestion-toggle="promesa"
                    class="flex min-h-[48px] items-center justify-center rounded-xl bg-primary px-2 py-3 text-base font-bold text-slate-950 hover:bg-primary-hover cursor-pointer">🤝 PROMESA</button>
            <button type="button" data-gestion-toggle="otro"
                    class="flex min-h-[48px] items-center justify-center rounded-xl bg-sky-700 px-2 py-3 text-base font-bold text-white hover:bg-sky-800 cursor-pointer">📅 OTRO DÍA</button>
        </div>

        {{-- Solo lo que 2B exige: fecha obligatoria de la promesa. --}}
        <form method="POST" action="{{ route('cobros.visits.manage', $customer) }}" data-gestion-panel="promesa" class="mt-3 hidden space-y-3 rounded-xl border border-slate-200 bg-white p-3">
            @csrf
            <input type="hidden" name="status" value="promesa_pago">
            <label class="block text-sm font-semibold text-slate-700">¿Para cuándo prometió pagar? (obligatorio)
                <input type="date" name="promise_date" required
                       class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-2 text-base text-slate-900">
            </label>
            <button type="submit" class="flex min-h-[48px] w-full items-center justify-center rounded-xl bg-primary px-4 py-3 text-base font-bold text-slate-950 hover:bg-primary-hover cursor-pointer">GUARDAR PROMESA</button>
            <button type="button" data-gestion-cancel class="flex min-h-[44px] w-full items-center justify-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer">Cancelar</button>
        </form>

        {{-- Reprogramación existente de 2B: nueva fecha obligatoria. --}}
        <form method="POST" action="{{ route('cobros.visits.manage', $customer) }}" data-gestion-panel="otro" class="mt-3 hidden space-y-3 rounded-xl border border-slate-200 bg-white p-3">
            @csrf
            <input type="hidden" name="status" value="reprogramada">
            <label class="block text-sm font-semibold text-slate-700">¿Para qué día lo deja? (obligatorio)
                <input type="date" name="scheduled_for" required
                       class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-2 text-base text-slate-900">
            </label>
            <button type="submit" class="flex min-h-[48px] w-full items-center justify-center rounded-xl bg-sky-700 px-4 py-3 text-base font-bold text-white hover:bg-sky-800 cursor-pointer">GUARDAR NUEVA FECHA</button>
            <button type="button" data-gestion-cancel class="flex min-h-[44px] w-full items-center justify-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer">Cancelar</button>
        </form>
    </section>

    {{-- Bloque 6: configuración OPERATIVA de día/frecuencia de visita (nunca toca la CxC). --}}

    <section aria-labelledby="dia-cobro">
        <h2 id="dia-cobro" class="text-lg font-semibold text-slate-900">Día de cobro / visita</h2>
        <p class="mt-1 text-xs text-slate-500">Organiza sus visitas. No modifica vencimientos, saldos ni condiciones del crédito.</p>

        @if($errors->any())
            <div role="alert" class="mt-2 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                <ul class="list-disc pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('cobros.collector.customer.visit-schedule', $customer) }}" class="mt-3 space-y-3 rounded-xl border border-slate-200 bg-white p-4">
            @csrf
            <div>
                <label for="visit_frequency" class="block text-sm font-semibold text-slate-700">Frecuencia</label>
                <select id="visit_frequency" name="visit_frequency" required data-visit-frequency
                        class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-base text-slate-900">
                    <option value="">Seleccione...</option>
                    @foreach(['diario' => 'Diario (cada día calendario)', 'semanal' => 'Semanal', 'quincenal' => 'Quincenal (fecha ancla)', 'mensual' => 'Mensual'] as $freqValue => $freqLabel)
                        <option value="{{ $freqValue }}" @selected(old('visit_frequency', $visitSchedule?->visit_frequency) === $freqValue)>{{ $freqLabel }}</option>
                    @endforeach
                </select>
            </div>

            <div data-visit-weekday class="hidden">
                <label for="visit_weekday" class="block text-sm font-semibold text-slate-700">Día de la semana</label>
                <select id="visit_weekday" name="visit_weekday"
                        class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-base text-slate-900">
                    <option value="">Seleccione...</option>
                    @foreach(['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'] as $wd => $wdLabel)
                        <option value="{{ $wd }}" @selected((string) old('visit_weekday', $visitSchedule?->visit_weekday) === (string) $wd)>{{ $wdLabel }}</option>
                    @endforeach
                </select>
            </div>

            <div data-visit-monthday class="hidden">
                <label for="visit_month_day" class="block text-sm font-semibold text-slate-700">Día del mes (1-31)</label>
                <input id="visit_month_day" name="visit_month_day" type="number" min="1" max="31" inputmode="numeric"
                       value="{{ old('visit_month_day', $visitSchedule?->visit_month_day) }}"
                       class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-base text-slate-900">
            </div>

            <div data-visit-anchor>
                <label for="next_visit_date" class="block text-sm font-semibold text-slate-700">Próxima visita / fecha ancla</label>
                <input id="next_visit_date" name="next_visit_date" type="date"
                       value="{{ old('next_visit_date', $visitSchedule?->next_visit_date?->format('Y-m-d')) }}"
                       class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-base text-slate-900">
                <p class="mt-1 text-xs text-slate-500">Quincenal: fecha ancla explícita (nunca se asume día 1/15). En las demás frecuencias confirma la próxima visita.</p>
            </div>

            <button type="submit" class="flex min-h-[48px] w-full items-center justify-center rounded-xl bg-primary px-4 py-3 text-base font-bold text-slate-950 hover:bg-primary-hover cursor-pointer">GUARDAR DÍA DE VISITA</button>
        </form>
    </section>

    <script>
    (function () {
        var freq = document.getElementById('visit_frequency');
        if (!freq) return;
        function sync() {
            document.querySelectorAll('[data-visit-weekday]').forEach(function (el) { el.classList.toggle('hidden', freq.value !== 'semanal'); });
            document.querySelectorAll('[data-visit-monthday]').forEach(function (el) { el.classList.toggle('hidden', freq.value !== 'mensual'); });
        }
        freq.addEventListener('change', sync);
        sync();
    })();
    </script>


    <section aria-labelledby="cxc-open">
        <h2 id="cxc-open" class="text-lg font-semibold text-slate-900">Cuentas por cobrar abiertas ({{ $openAccounts->count() }})</h2>
        @if($openAccounts->isEmpty())
            <p class="mt-3 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">No hay cuentas por cobrar abiertas para este cliente.</p>
        @endif
        <ul class="mt-3 space-y-3">
            @foreach($openAccounts as $account)
                <li class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ $account->sale?->sale_number ?: 'Cuenta por cobrar' }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">Emitida {{ $account->issued_at->format('d/m/Y') }}</p>
                        </div>
                        <div class="text-right">
                            <span class="block text-xs uppercase tracking-wide text-slate-400">Saldo</span>
                            <span class="text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $account->balance_due, 'currency' => $account->currency_code])</span>
                        </div>
                    </div>
                    <p class="mt-2 text-sm {{ $account->due_date->isPast() ? 'font-semibold text-red-700' : 'text-slate-600' }}">
                        Vence {{ $account->due_date->format('d/m/Y') }}{{ $account->due_date->isPast() ? ' · vencida' : '' }}
                    </p>
                    {{-- 3A: acceso al Gate solo para la cuenta que el Gate declara cobrable. --}}
                    @if(($chargeableAccounts[$customer->id] ?? null) === $account->id)
                        <a href="{{ route('cobros.collector.payments.create', $account) }}"
                           class="mt-3 flex min-h-[48px] w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-3 text-base font-bold text-slate-950 hover:bg-primary-hover">💰 COBRAR</a>
                    @elseif(isset($accountGateDecisions[$account->id]) && ! $accountGateDecisions[$account->id]->isAllowed())
                        <p role="status" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            {{ $accountGateDecisions[$account->id]->message }}
                        </p>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="ubicacion" data-geo-section>
        <h2 id="ubicacion" class="text-lg font-semibold text-slate-900">Ubicación</h2>
        @if($customer->hasLocation())
            <button type="button" data-nav-btn data-lat="{{ $customer->latitude }}" data-lng="{{ $customer->longitude }}"
                    class="mt-3 flex min-h-[48px] w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-3 text-base font-bold text-white hover:bg-primary-hover cursor-pointer">🧭 IR AL CLIENTE</button>
            <div data-nav-fallback class="mt-2 hidden rounded-xl border border-slate-200 bg-white p-3">
                <p class="text-xs text-slate-500">Elige tu app de navegación:</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <a data-nav-app="google" href="#" class="min-h-[44px] inline-flex items-center rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Google Maps</a>
                    <a data-nav-app="waze" href="#" class="min-h-[44px] inline-flex items-center rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Waze</a>
                    <a data-nav-app="apple" href="#" class="min-h-[44px] inline-flex items-center rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Apple Maps</a>
                </div>
            </div>
        @else
            <form method="POST" action="{{ route('cobros.collector.portfolio.location', $customer) }}" data-save-location class="mt-3 space-y-2">
                @csrf
                <input type="hidden" name="latitude" value="" data-loc-lat>
                <input type="hidden" name="longitude" value="" data-loc-lng>
                <button type="submit" class="flex min-h-[48px] w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 bg-white px-4 py-3 text-base font-semibold text-slate-700 hover:border-primary hover:text-slate-900 cursor-pointer">📍 GUARDAR UBICACIÓN ACTUAL</button>
                <p data-loc-msg class="text-xs text-slate-500" hidden></p>
            </form>
        @endif
    </section>

    <section aria-labelledby="expediente-block">
        <h2 id="expediente-block" class="text-lg font-semibold text-slate-900">Expediente</h2>
        @php
            $estadoExp = $expediente === null
                ? 'Sin expediente'
                : ($expediente->occupation && $expediente->income_amount && $expediente->address_reference && $expediente->references->count() > 0
                    ? 'Completo'
                    : 'Incompleto');
        @endphp
        <div class="mt-3 rounded-xl border border-slate-200 bg-white p-4 text-sm">
            <p class="font-semibold text-slate-700">Estado: {{ $estadoExp }}</p>
            <p class="mt-1 text-slate-500">Trabajo e ingresos, referencias personales, fiador opcional y documentos privados.</p>
            <p class="mt-1 text-slate-500">Calificación interna: <span class="font-semibold text-slate-700">Sin calificar</span></p>
            <a href="{{ route('cobros.collector.customers.expediente', $customer) }}"
               class="mt-3 inline-flex min-h-[44px] items-center justify-center rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover">VER / EDITAR EXPEDIENTE</a>
        </div>
    </section>

    <details class="rounded-xl border border-slate-200 bg-white p-4">
        <summary class="flex min-h-[48px] cursor-pointer items-center justify-between text-base font-bold text-slate-900">Solicitudes ({{ $creditRequests->count() }})</summary>
        @forelse($creditRequests as $req)
            <div class="mt-2 border-t border-slate-100 pt-2 text-sm">
                <p class="font-semibold">{{ $req->created_at?->format('d/m/Y') }} · ₡{{ number_format((float) $req->amount_requested, 2) }} · {{ $req->statusLabel() }}</p>
                <a class="text-amber-700 underline" href="{{ route('cobros.collector.credit-requests.evaluate', ['solicitud' => $req->id]) }}">Ver detalle</a>
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-600">Sin solicitudes.</p>
        @endforelse
    </details>

    <details class="rounded-xl border border-slate-200 bg-white p-4">
        <summary class="flex min-h-[48px] cursor-pointer items-center justify-between text-base font-bold text-slate-900">Préstamos ({{ $credits->count() }})</summary>
        @forelse($credits as $cr)
            <div class="mt-2 border-t border-slate-100 pt-2 text-sm">
                <p class="font-semibold">{{ $cr->public_reference ?? ('Préstamo #'.$cr->id) }} · ₡{{ number_format((float) $cr->principal, 2) }} · {{ $cr->status === \App\Models\CobrosCredit::STATUS_DISBURSED ? "Desembolsado" : $cr->status }}</p>
                @if($cr->next_due_date)<p>Próximo pago: {{ \Carbon\Carbon::parse($cr->next_due_date)->format('d/m/Y') }}</p>@endif
                <a class="text-amber-700 underline" href="{{ route('cobros.collector.creditos.entrega', ['credit' => $cr->id]) }}">Ver préstamo</a>
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-600">Sin préstamos.</p>
        @endforelse
    </details>

    <details class="rounded-xl border border-slate-200 bg-white p-4">
        <summary class="flex min-h-[48px] cursor-pointer items-center justify-between text-base font-bold text-slate-900">Pagos y recibos ({{ $receipts->count() }})</summary>
        @forelse($receipts as $rc)
            <div class="mt-2 border-t border-slate-100 pt-2 text-sm">
                <p class="font-semibold">{{ $rc->paid_at?->format('d/m/Y') }} · ₡{{ number_format((float) $rc->amount, 2) }} · {{ $rc->receipt_number }}</p>
                <a class="text-amber-700 underline" href="{{ route('cobros.collector.payments.receipt', $rc->receipt_number) }}">Ver comprobante</a>
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-600">Sin recibos.</p>
        @endforelse
    </details>

    <details class="rounded-xl border border-slate-200 bg-white p-4">
        <summary class="flex min-h-[48px] cursor-pointer items-center justify-between text-base font-bold text-slate-900">Visitas ({{ $visits->count() }})</summary>
        @forelse($visits as $v)
            <p class="mt-2 border-t border-slate-100 pt-2 text-sm">{{ $v->scheduled_for?->format('d/m/Y') }} · {{ $v->status }}</p>
        @empty
            <p class="mt-2 text-sm text-slate-600">Sin visitas registradas.</p>
        @endforelse
        <a class="mt-2 inline-block text-sm font-semibold text-amber-700 underline" href="{{ route('cobros.visits.today') }}">Ver agenda</a>
    </details>

    <div class="flex flex-wrap gap-2">
        <a href="{{ route('cobros.visits.customer', $customer) }}"
           class="mt-3 inline-flex min-h-[44px] flex-1 items-center justify-center rounded-xl border border-primary px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-primary/10">Agenda / historial de visitas</a>
    </div>
    <section aria-labelledby="pagos-historicos" class="mt-6">
        <div class="flex items-center justify-between gap-2">
            <h2 id="pagos-historicos" class="text-lg font-semibold text-slate-900">Últimos pagos</h2>
            @if($payments->isNotEmpty())
                <a href="{{ route('cobros.collector.payments.history') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-slate-700 hover:text-slate-900">Ver historial →</a>
            @endif
        </div>
        @if($payments->isEmpty())
            <p class="mt-3 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">Sin pagos registrados con fuente fiable.</p>
        @else
            <ul class="mt-3 space-y-2">
                @foreach($payments->take(3) as $payment)
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm">
                        <span class="font-semibold text-slate-900">@include('cobros.components.money', ['amount' => $payment->amount, 'currency' => $company->currency])</span>
                        <span class="text-slate-500">{{ $payment->paid_at->format('d/m/Y H:i') }} · {{ $payment->paymentMethod?->name ?: '—' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

</div>

<script>
(function () {
    var section = document.querySelector('[data-geo-section]');
    if (!section) { return; }

    var navBtn = section.querySelector('[data-nav-btn]');
    if (navBtn) {
        var lat = navBtn.getAttribute('data-lat');
        var lng = navBtn.getAttribute('data-lng');
        var q = lat + ',' + lng;
        var links = {
            google: 'https://www.google.com/maps/dir/?api=1&destination=' + q,
            waze: 'https://waze.com/ul?ll=' + q + '&navigate=yes',
            apple: 'https://maps.apple.com/?daddr=' + q
        };
        var fallback = section.querySelector('[data-nav-fallback]');
        section.querySelectorAll('[data-nav-app]').forEach(function (a) {
            a.href = links[a.getAttribute('data-nav-app')] || links.google;
            a.target = '_blank';
            a.rel = 'noopener';
        });

        navBtn.addEventListener('click', function () {
            var ua = navigator.userAgent || '';
            var isIOS = /iPad|iPhone|iPod/.test(ua);
            var isAndroid = /Android/.test(ua);
            // Un toque: intenta el esquema nativo del SO (app preferida del dispositivo).
            // Si el SO no lo resuelve, se muestra el fallback de apps (no depende de Waze).
            var scheme = isIOS
                ? 'https://maps.apple.com/?daddr=' + q
                : (isAndroid ? 'geo:' + q + '?q=' + q : links.google);
            try { window.location.href = scheme; } catch (e) { /* fallback abajo */ }
            if (fallback) { fallback.classList.remove('hidden'); }
        });
    }

    var saveForm = section.querySelector('[data-save-location]');
    if (saveForm) {
        var latIn = saveForm.querySelector('[data-loc-lat]');
        var lngIn = saveForm.querySelector('[data-loc-lng]');
        var msg = saveForm.querySelector('[data-loc-msg]');
        saveForm.addEventListener('submit', function (e) {
            if (latIn.value && lngIn.value) { return; }
            e.preventDefault();
            if (!navigator.geolocation) {
                if (msg) { msg.hidden = false; msg.textContent = 'Tu navegador no permite ubicación (contexto no seguro o sin soporte). Puedes continuar sin ella.'; }
                return;
            }
            navigator.geolocation.getCurrentPosition(function (pos) {
                latIn.value = String(pos.coords.latitude);
                lngIn.value = String(pos.coords.longitude);
                saveForm.submit();
            }, function (err) {
                if (!msg) { return; }
                msg.hidden = false;
                if (err && err.code === 1) {
                    msg.textContent = 'Permiso de ubicación denegado. Actívalo en el navegador o continúa sin GPS.';
                } else if (err && err.code === 2) {
                    msg.textContent = 'Ubicación no disponible. Revisa la señal o el GPS del dispositivo y vuelve a intentar.';
                } else if (err && err.code === 3) {
                    msg.textContent = 'Tiempo agotado al buscar la ubicación. Intenta de nuevo o continúa sin GPS.';
                } else {
                    msg.textContent = 'No se pudo obtener tu ubicación. Puedes continuar sin ella.';
                }
            }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
        });
    }
})();
</script>

<script>
(function () {
    var panels = document.querySelectorAll('[data-gestion-panel]');
    if (!panels.length) { return; }
    function hideAll() {
        panels.forEach(function (panel) { panel.classList.add('hidden'); });
    }
    document.querySelectorAll('[data-gestion-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var panel = document.querySelector('[data-gestion-panel="' + button.getAttribute('data-gestion-toggle') + '"]');
            if (!panel) { return; }
            var wasHidden = panel.classList.contains('hidden');
            hideAll();
            if (wasHidden) { panel.classList.remove('hidden'); }
        });
    });
    document.querySelectorAll('[data-gestion-cancel]').forEach(function (button) {
        button.addEventListener('click', hideAll);
    });
})();
</script>
@endsection
