@extends('cobros.layout')

@section('title', 'Agenda — MVS CobrOS')

@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $tab === 'hoy' ? 'Agenda de hoy' : 'Agenda' }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $company->trade_name }} · {{ \Carbon\Carbon::parse($rangeStart)->translatedFormat('d/m/Y') }}@if($rangeEnd !== $rangeStart) — {{ \Carbon\Carbon::parse($rangeEnd)->translatedFormat('d/m/Y') }}@endif</p>
        </div>
        <a href="{{ route('cobros.collector.portfolio') }}" class="inline-flex min-h-[44px] items-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Mi cartera</a>
    </div>

    {{-- Bloque 6: filtros grandes de la agenda (mobile-first, >=48px). --}}
    <nav aria-label="Filtros de agenda" class="flex gap-2 overflow-x-auto pb-1">
        @foreach($tabs as $tabKey => $tabLabel)
            <a href="{{ route('cobros.visits.today', ['tab' => $tabKey]) }}"
               @if($tab === $tabKey) aria-current="page" @endif
               class="inline-flex min-h-[48px] flex-1 items-center justify-center whitespace-nowrap rounded-xl px-3 text-sm font-bold @if($tab === $tabKey) bg-primary text-slate-950 @else border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 @endif">{{ $tabLabel }}</a>
        @endforeach
    </nav>

    @if(count($items) === 0)
        <div class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-600">
            @if($tab === 'hoy')
                Hoy no tienes visitas programadas. Desde la ficha de un cliente puedes programar una visita.
            @else
                No hay visitas programadas en este rango.
            @endif
        </div>
    @endif

    @php
        // Etiquetas humanas (solo presentación; los estados de BD no cambian).
        $human = [
            'pendiente' => 'Esperando visita',
            'visitado' => 'Visitado',
            'no_localizado' => 'No estaba',
            'promesa_pago' => 'Promesa de pago',
            'reprogramada' => 'Otro día',
        ];
        $todayStr = now($company->timezone ?: config('app.timezone'))->toDateString();
    @endphp

    <ul class="space-y-3">
        @foreach($items as $item)
            @php
                $customer = $item['customer'];
                $visit = $item['visit'];
                // Abierto a gestión: recurrencia sin fila todavía o visita pendiente.
                $open = $visit === null || $visit->status === 'pendiente';
                $badge = $visit !== null ? ($human[$visit->status] ?? $visit->status) : 'Programada';
                $done = $visit !== null && ! $open;
                $whatsappUrl = $item['whatsapp_url'] ?? null;
            @endphp
            <li class="rounded-xl border border-slate-200 bg-white p-4" data-visit-card data-status="{{ $visit?->status ?? 'pendiente' }}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="truncate text-base font-semibold text-slate-900">{{ $customer->name }}</h2>
                        <p class="mt-0.5 text-xs text-slate-500">Visita: {{ \Carbon\Carbon::parse($item['date'])->translatedFormat('d/m/Y') }}@if($item['date'] === $todayStr) · Hoy @endif</p>
                        @if($customer->phone || $customer->mobile)
                            <p class="mt-0.5 text-xs text-slate-500">{{ $customer->phone ?: $customer->mobile }}</p>
                        @endif
                    </div>
                    <span data-visit-badge class="rounded-full px-3 py-1 text-xs font-semibold {{ $done ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">{{ $badge }}</span>
                </div>

                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm text-slate-600">
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Saldo</dt>
                        <dd class="font-semibold text-slate-900">@include('cobros.components.money', ['amount' => $item['balance'], 'currency' => $company->currency])</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Ruta</dt>
                        <dd class="truncate font-semibold">{{ $item['route'] ?: '—' }}</dd></div>
                </dl>

                @if($open)
                    {{-- Una decisión = una pantalla: 4 botones claros, nada de inputs --}}
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button type="button" data-action="visitado"
                                class="cobros-action-btn min-h-[48px] rounded-xl bg-green-600 px-2 py-2.5 text-base font-bold text-white shadow-sm hover:bg-green-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-green-300 active:scale-[0.98] cursor-pointer">✓ Visitado</button>
                        <button type="button" data-action="no_localizado"
                                class="cobros-action-btn min-h-[48px] rounded-xl bg-slate-600 px-2 py-2.5 text-base font-bold text-white shadow-sm hover:bg-slate-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-slate-300 active:scale-[0.98] cursor-pointer">✕ No estaba</button>
                        <button type="button" data-action="promesa_pago"
                                class="cobros-action-btn min-h-[48px] rounded-xl bg-primary px-2 py-2.5 text-base font-bold text-white shadow-sm hover:bg-primary-hover focus:outline-none focus-visible:ring-4 focus-visible:ring-amber-300 active:scale-[0.98] cursor-pointer">🤝 Promesa</button>
                        <button type="button" data-action="reprogramada"
                                class="cobros-action-btn min-h-[48px] rounded-xl bg-sky-700 px-2 py-2.5 text-base font-bold text-white shadow-sm hover:bg-sky-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-sky-300 active:scale-[0.98] cursor-pointer">📅 Otro día</button>
                    </div>

                    @php $formAction = $visit !== null ? route('cobros.visits.update', $visit) : route('cobros.visits.manage', $customer); @endphp

                    {{-- Observación opcional para gestión directa (Visitado / No estaba) --}}
                    <form method="POST" action="{{ $formAction }}" data-panel="direct" class="mt-3 hidden space-y-2">
                        @csrf @if($visit !== null) @method('PATCH') @endif
                        <input type="hidden" name="status" value="">
                        <label class="block text-xs font-medium text-slate-500">Observación (opcional)
                            <textarea name="notes" rows="2" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"></textarea>
                        </label>
                        <button type="submit" class="min-h-[48px] w-full rounded-xl bg-primary px-4 py-2.5 text-base font-bold text-white hover:bg-primary-hover cursor-pointer">Guardar</button>
                        <button type="button" data-cancel class="min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer">Cancelar</button>
                    </form>

                    {{-- Promesa: fecha obligatoria + monto opcional + observación opcional --}}
                    <form method="POST" action="{{ $formAction }}" data-panel="promesa_pago" class="mt-3 hidden space-y-2">
                        @csrf @if($visit !== null) @method('PATCH') @endif
                        <input type="hidden" name="status" value="promesa_pago">
                        <label class="block text-xs font-medium text-slate-500">¿Para cuándo prometió pagar? (obligatorio)
                            <input type="date" name="promise_date" required class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-2">
                        </label>
                        <label class="block text-xs font-medium text-slate-500">¿Cuánto prometió? (opcional)
                            <input type="text" inputmode="decimal" name="promise_amount" placeholder="0.00" class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-2 text-right">
                        </label>
                        <label class="block text-xs font-medium text-slate-500">Observación (opcional)
                            <textarea name="notes" rows="2" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"></textarea>
                        </label>
                        <button type="submit" class="min-h-[48px] w-full rounded-xl bg-primary px-4 py-2.5 text-base font-bold text-white hover:bg-primary-hover cursor-pointer">Guardar promesa</button>
                        <button type="button" data-cancel class="min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer">Cancelar</button>
                    </form>

                    {{-- Otro día: nueva fecha obligatoria + observación opcional --}}
                    <form method="POST" action="{{ $formAction }}" data-panel="reprogramada" class="mt-3 hidden space-y-2">
                        @csrf @if($visit !== null) @method('PATCH') @endif
                        <input type="hidden" name="status" value="reprogramada">
                        <label class="block text-xs font-medium text-slate-500">¿Para qué día lo deja? (obligatorio)
                            <input type="date" name="scheduled_for" required class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-2">
                        </label>
                        <label class="block text-xs font-medium text-slate-500">Observación (opcional)
                            <textarea name="notes" rows="2" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"></textarea>
                        </label>
                        <button type="submit" class="min-h-[48px] w-full rounded-xl bg-primary px-4 py-2.5 text-base font-bold text-white hover:bg-primary-hover cursor-pointer">Guardar nueva fecha</button>
                        <button type="button" data-cancel class="min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer">Cancelar</button>
                    </form>
                @endif

                <a href="{{ route('cobros.visits.customer', $customer) }}" class="mt-2 inline-flex min-h-[44px] items-center text-sm font-semibold text-slate-700 hover:text-slate-900">Historial del cliente →</a>

                {{-- Acciones rápidas del día: >=48px, sin IDs internos, sin Sucursal. --}}
                <div class="mt-3 grid grid-cols-2 gap-2">
                    @if($open)
                        <button type="button" data-action="reprogramada"
                                class="flex min-h-[48px] items-center justify-center rounded-xl bg-sky-700 px-3 text-sm font-bold text-white hover:bg-sky-800 cursor-pointer">REPROGRAMAR</button>
                    @else
                        <details class="col-span-2 rounded-xl border border-slate-300 bg-white">
                            <summary class="flex min-h-[48px] cursor-pointer list-none items-center justify-center rounded-xl bg-sky-700 px-3 text-sm font-bold text-white hover:bg-sky-800">REPROGRAMAR</summary>
                            <form method="POST" action="{{ route('cobros.visits.agenda.reschedule', $customer) }}" class="space-y-3 p-3">
                                @csrf
                                <input type="hidden" name="origin_date" value="{{ $item['date'] }}">
                                <label class="block text-xs font-medium text-slate-500">Nueva fecha (obligatorio)
                                    <input type="date" name="scheduled_for" required class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-2">
                                </label>
                                <button type="submit" class="min-h-[48px] w-full rounded-xl bg-sky-700 px-4 text-base font-bold text-white hover:bg-sky-800 cursor-pointer">CONFIRMAR REPROGRAMACIÓN</button>
                            </form>
                        </details>
                    @endif
                    @if($customer->phone || $customer->mobile)
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $customer->phone ?: $customer->mobile) }}"
                           class="flex min-h-[48px] items-center justify-center rounded-xl border-2 border-primary bg-white px-3 text-sm font-bold text-slate-900 hover:bg-primary/10">📞 LLAMAR</a>
                    @endif
                    @if($whatsappUrl)
                        <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener"
                           class="flex min-h-[48px] items-center justify-center rounded-xl border-2 border-primary bg-white px-3 text-sm font-bold text-slate-900 hover:bg-primary/10">🟢 WHATSAPP</a>
                    @endif
                    @if($customer->google_maps_url)
                        <a href="{{ $customer->google_maps_url }}" target="_blank" rel="noopener"
                           class="flex min-h-[48px] items-center justify-center rounded-xl border-2 border-primary bg-white px-3 text-sm font-bold text-slate-900 hover:bg-primary/10">🧭 IR AL CLIENTE</a>
                    @endif
                    <a href="{{ route('cobros.collector.portfolio.show', $customer) }}"
                       class="flex min-h-[48px] items-center justify-center rounded-xl border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">VER CLIENTE</a>
                    {{-- 3A: entrada al Gate desde el evento, solo si el Gate lo permite. --}}
                    @if(isset($chargeableAccounts[$customer->id]) && (!$visit || !$visit->account_receivable_id || (int) $visit->account_receivable_id === $chargeableAccounts[$customer->id]))
                        <a href="{{ $visit ? route('cobros.collector.payments.create', ['account' => $chargeableAccounts[$customer->id], 'visit' => $visit->id]) : route('cobros.collector.payments.create', $chargeableAccounts[$customer->id]) }}"
                           class="col-span-2 flex min-h-[48px] w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-3 text-base font-bold text-slate-950 hover:bg-primary-hover">💰 COBRAR</a>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</div>

<script>
    // Una decisión a la vez: muestra SOLO el panel de la opción elegida.
    document.querySelectorAll('[data-visit-card]').forEach(function (card) {
        var direct = card.querySelector('[data-panel="direct"]');
        var panels = { promesa_pago: card.querySelector('[data-panel="promesa_pago"]'), reprogramada: card.querySelector('[data-panel="reprogramada"]') };
        var hiddenInput = direct ? direct.querySelector('input[name="status"]') : null;

        function openPanel(name) {
            if (direct) { direct.classList.toggle('hidden', name !== 'visitado' && name !== 'no_localizado'); if (hiddenInput) { hiddenInput.value = name; } }
            if (panels.promesa_pago) { panels.promesa_pago.classList.toggle('hidden', name !== 'promesa_pago'); }
            if (panels.reprogramada) { panels.reprogramada.classList.toggle('hidden', name !== 'reprogramada'); }
        }

        card.querySelectorAll('[data-action]').forEach(function (btn) {
            btn.addEventListener('click', function () { openPanel(btn.getAttribute('data-action')); });
        });
        card.querySelectorAll('[data-cancel]').forEach(function (btn) {
            btn.addEventListener('click', function () { openPanel(null); });
        });

        // 2C: snapshot GPS opcional en la gestión; si falla, el formulario igual se envía.
        card.querySelectorAll('form[data-panel]').forEach(function (form) {
            form.addEventListener('submit', function onSubmit(e) {
                if (form.querySelector('input[name="latitude"]')) { return; }
                var latIn = document.createElement('input');
                latIn.type = 'hidden'; latIn.name = 'latitude'; latIn.value = '';
                var lngIn = document.createElement('input');
                lngIn.type = 'hidden'; lngIn.name = 'longitude'; lngIn.value = '';
                form.appendChild(latIn); form.appendChild(lngIn);

                if (!navigator.geolocation) { return; }
                e.preventDefault();
                e.stopImmediatePropagation();
                form.removeEventListener('submit', onSubmit, true);
                navigator.geolocation.getCurrentPosition(function (pos) {
                    latIn.value = String(pos.coords.latitude);
                    lngIn.value = String(pos.coords.longitude);
                    form.submit();
                }, function () {
                    // Fallo GPS opcional: la gestión se envía igual (snapshot vacío).
                    latIn.value = ''; lngIn.value = '';
                    form.submit();
                }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 });
            }, true);
        });
    });
</script>
@endsection
