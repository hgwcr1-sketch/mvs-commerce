@extends('cobros.layout')

@section('title', 'Agenda de hoy — MVS CobrOS')

@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Agenda de hoy</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $company->trade_name }} · {{ now()->translatedFormat('d/m/Y') }}</p>
        </div>
        <a href="{{ route('cobros.collector.portfolio') }}" class="inline-flex min-h-[44px] items-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Mi cartera</a>
    </div>

    @if($visits->isEmpty())
        <div class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-600">
            Hoy no tienes visitas programadas. Desde la ficha de un cliente puedes programar una visita.
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
    @endphp

    <ul class="space-y-3">
        @foreach($visits as $visit)
            @php $done = $visit->status !== 'pendiente'; @endphp
            <li class="rounded-xl border border-slate-200 bg-white p-4" data-visit-card data-status="{{ $visit->status }}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="truncate text-base font-semibold text-slate-900">{{ $visit->customer->name }}</h2>
                        <p class="mt-0.5 text-xs text-slate-500">{{ $visit->branch?->name ?: $visit->customer->phone ?: $visit->customer->mobile ?: '—' }}</p>
                    </div>
                    <span data-visit-badge class="rounded-full px-3 py-1 text-xs font-semibold {{ $done ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">{{ $human[$visit->status] }}</span>
                </div>

                @if(! $done)
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

                    {{-- Observación opcional para gestión directa (Visitado / No estaba) --}}
                    <form method="POST" action="{{ route('cobros.visits.update', $visit) }}" data-panel="direct" class="mt-3 hidden space-y-2">
                        @csrf @method('PATCH')
                        <input type="hidden" name="status" value="">
                        <label class="block text-xs font-medium text-slate-500">Observación (opcional)
                            <textarea name="notes" rows="2" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"></textarea>
                        </label>
                        <button type="submit" class="min-h-[48px] w-full rounded-xl bg-primary px-4 py-2.5 text-base font-bold text-white hover:bg-primary-hover cursor-pointer">Guardar</button>
                        <button type="button" data-cancel class="min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer">Cancelar</button>
                    </form>

                    {{-- Promesa: fecha obligatoria + monto opcional + observación opcional --}}
                    <form method="POST" action="{{ route('cobros.visits.update', $visit) }}" data-panel="promesa_pago" class="mt-3 hidden space-y-2">
                        @csrf @method('PATCH')
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
                    <form method="POST" action="{{ route('cobros.visits.update', $visit) }}" data-panel="reprogramada" class="mt-3 hidden space-y-2">
                        @csrf @method('PATCH')
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

                <a href="{{ route('cobros.visits.customer', $visit->customer) }}" class="mt-2 inline-flex min-h-[44px] items-center text-sm font-semibold text-slate-700 hover:text-slate-900">Historial del cliente →</a>
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
