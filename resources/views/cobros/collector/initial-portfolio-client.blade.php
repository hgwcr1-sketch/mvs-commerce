@extends('cobros.layout')

@section('title', 'Agregar cliente — Cargar mi cartera actual')

@section('content')
{{-- Bloque 5B: alta rápida uno por uno, solo con la migración 5A abierta. --}}
<div class="mx-auto max-w-2xl space-y-4">
    <a href="{{ route('cobros.initial-portfolio') }}" class="inline-flex min-h-[44px] items-center gap-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
        ← CARGAR MI CARTERA ACTUAL
    </a>

    <div class="rounded-xl border-2 border-primary bg-slate-900 p-4 text-white">
        <h1 class="text-2xl font-bold">Agregar cliente</h1>
        <p class="mt-1 text-sm text-slate-200">Uno por uno. Solo datos que usted conoce de su cartera anterior.</p>
    </div>

    @if($errors->any())
        <div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4">
            <ul class="list-inside list-disc space-y-1 text-sm font-semibold text-red-700">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('cobros.initial-portfolio.client.store') }}" class="space-y-5 rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        @csrf

        <section class="space-y-3">
            <h2 class="text-lg font-semibold text-slate-900">Datos personales</h2>
            <div>
                <label for="identification_type" class="block text-sm font-semibold text-slate-700">Tipo de identificación</label>
                <select id="identification_type" name="identification_type" required
                        class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-base">
                    <option value="cedula">Cédula nacional</option>
                    <option value="dimex">DIMEX</option>
                    <option value="pasaporte">Pasaporte</option>
                </select>
            </div>
            <div>
                <label for="identification" class="block text-sm font-semibold text-slate-700">Identificación</label>
                <input id="identification" name="identification" type="text" inputmode="numeric" required maxlength="20"
                       value="{{ old('identification') }}" class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-base">
            </div>
            <div>
                <label for="name" class="block text-sm font-semibold text-slate-700">Nombre</label>
                <input id="name" name="name" type="text" required maxlength="150" value="{{ old('name') }}"
                       class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-base">
                <p id="name-lookup-status" class="mt-1 text-xs text-slate-500" hidden aria-live="polite">Buscando…</p>
            </div>
            <div>
                <label for="phone" class="block text-sm font-semibold text-slate-700">Teléfono</label>
                <input id="phone" name="phone" type="tel" inputmode="tel" required maxlength="30" value="{{ old('phone') }}"
                       class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-base">
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="alt_contact_name" class="block text-sm font-semibold text-slate-700">Contacto alternativo (opcional)</label>
                    <input id="alt_contact_name" name="alt_contact_name" type="text" maxlength="120" value="{{ old('alt_contact_name') }}"
                           class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-base">
                </div>
                <div>
                    <label for="alt_contact_phone" class="block text-sm font-semibold text-slate-700">Teléfono del contacto</label>
                    <input id="alt_contact_phone" name="alt_contact_phone" type="tel" inputmode="tel" maxlength="30" value="{{ old('alt_contact_phone') }}"
                           class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-base">
                </div>
            </div>
            <div>
                <label for="address" class="block text-sm font-semibold text-slate-700">Dirección (opcional)</label>
                <textarea id="address" name="address" rows="2" maxlength="250" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3 text-base">{{ old('address') }}</textarea>
            </div>

            <input type="hidden" name="latitude" value="{{ old('latitude') }}" data-loc-lat>
            <input type="hidden" name="longitude" value="{{ old('longitude') }}" data-loc-lng>
            <button type="button" data-geo-btn
                    class="flex min-h-[48px] w-full items-center justify-center rounded-xl border-2 border-dashed border-slate-300 px-4 py-3 text-base font-semibold text-slate-700 hover:border-primary cursor-pointer">
                📍 USAR MI UBICACIÓN (GPS opcional)
            </button>
            <p data-loc-msg class="text-xs text-slate-500" hidden></p>
            <p class="text-xs text-slate-500">Sin GPS queda <strong>Ubicación pendiente</strong>; no bloquea el guardado.</p>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold text-slate-900">Crédito actual (migrado)</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="original_amount" class="block text-sm font-semibold text-slate-700">Monto original (si lo conoce)</label>
                    <input id="original_amount" name="original_amount" type="text" inputmode="decimal" value="{{ old('original_amount') }}"
                           class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-right text-lg font-semibold">
                </div>
                <div>
                    <label for="balance" class="block text-sm font-semibold text-slate-700">Saldo actual (obligatorio)</label>
                    <input id="balance" name="balance" type="text" inputmode="decimal" required value="{{ old('balance') }}"
                           class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-right text-lg font-semibold">
                </div>
                <div>
                    <label for="installment" class="block text-sm font-semibold text-slate-700">Cuota actual (obligatoria)</label>
                    <input id="installment" name="installment" type="text" inputmode="decimal" required value="{{ old('installment') }}"
                           class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-right text-lg font-semibold">
                </div>
                <div>
                    <label for="frequency" class="block text-sm font-semibold text-slate-700">Frecuencia</label>
                    <select id="frequency" name="frequency" required
                            class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-base">
                        <option value="diario">Diario</option>
                        <option value="semanal">Semanal</option>
                        <option value="quincenal">Quincenal</option>
                        <option value="mensual">Mensual</option>
                    </select>
                </div>
            </div>
            <div>
                <label for="next_charge_date" class="block text-sm font-semibold text-slate-700">Próxima fecha de cobro (obligatoria)</label>
                <input id="next_charge_date" name="next_charge_date" type="date" required value="{{ old('next_charge_date') }}"
                       class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-base">
                <p class="mt-1 text-xs text-slate-500">Será el ancla del calendario futuro. No se asume un día fijo.</p>
            </div>
        </section>

        <section class="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-3">
            <h2 class="text-base font-bold uppercase tracking-wide text-amber-900">INFORMACIÓN DECLARADA AL INGRESAR A COBROS</h2>
            <p class="text-xs text-amber-900">Lo que registre aquí es su declaración, no historial verificado por CobrOS.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="prior_credits" class="block text-sm font-semibold text-slate-700">Créditos anteriores (aprox., opcional)</label>
                    <input id="prior_credits" name="prior_credits" type="number" min="0" max="999" inputmode="numeric" value="{{ old('prior_credits') }}"
                           class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-base">
                </div>
                <div>
                    <label for="declared_behavior" class="block text-sm font-semibold text-slate-700">Comportamiento declarado</label>
                    <select id="declared_behavior" name="declared_behavior" required
                            class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-base">
                        <option value="sin_informacion">Sin información</option>
                        <option value="bueno">Bueno</option>
                        <option value="regular">Regular</option>
                        <option value="malo">Malo</option>
                    </select>
                </div>
            </div>
            <div>
                <label for="historical_note" class="block text-sm font-semibold text-slate-700">Observación histórica (opcional)</label>
                <textarea id="historical_note" name="historical_note" rows="2" maxlength="500" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3 text-base">{{ old('historical_note') }}</textarea>
            </div>
        </section>

        <div class="grid gap-3 sm:grid-cols-2">
            <button type="submit" name="action" value="siguiente"
                    class="flex min-h-[56px] w-full items-center justify-center rounded-xl bg-primary px-4 py-4 text-base font-bold text-slate-950 hover:bg-primary-hover">
                GUARDAR Y AGREGAR SIGUIENTE
            </button>
            <button type="submit" name="action" value="volver"
                    class="flex min-h-[56px] w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-4 text-base font-bold text-slate-800 hover:bg-slate-50">
                GUARDAR Y VOLVER A CARTERA
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    // Autocompletar Nombre desde identificación (solo si el proveedor del país
    // lo soporta). Nunca bloquea: si no existe / no responde / no encuentra,
    // el Nombre queda editable manualmente. DIMEX/Pasaporte: manual.
    var typeEl = document.getElementById('identification_type');
    var idEl = document.getElementById('identification');
    var nameEl = document.getElementById('name');
    var statusEl = document.getElementById('name-lookup-status');
    if (!typeEl || !idEl || !nameEl) { return; }
    var endpoint = @json(route('cobros.customers.identification-name'));
    var timer = null, seq = 0;
    function hide() { if (statusEl) { statusEl.hidden = true; } }
    function search() {
        if (typeEl.value !== 'cedula') { hide(); return; }
        var id = idEl.value.trim();
        if (!/^\d{9}$/.test(id)) { hide(); return; }
        var my = ++seq;
        if (statusEl) { statusEl.hidden = false; statusEl.textContent = 'Buscando…'; }
        fetch(endpoint + '?identification_type=' + encodeURIComponent(typeEl.value) + '&identification=' + encodeURIComponent(id), {headers: {Accept: 'application/json'}})
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (my !== seq) { return; }
                hide();
                if (data && data.supported && data.found && data.name && !nameEl.value.trim()) {
                    nameEl.value = data.name;
                }
            })
            .catch(function () { if (my === seq) { hide(); } }); // no bloquea
    }
    idEl.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(search, 400);
    });
    typeEl.addEventListener('change', function () { clearTimeout(timer); hide(); });
})();
</script>
<script>
(function () {
    var btn = document.querySelector('[data-geo-btn]');
    if (!btn) { return; }
    var lat = document.querySelector('[data-loc-lat]');
    var lng = document.querySelector('[data-loc-lng]');
    var msg = document.querySelector('[data-loc-msg]');
    btn.addEventListener('click', function () {
        if (!navigator.geolocation) { if (msg) { msg.hidden = false; msg.textContent = 'Sin GPS: quedará Ubicación pendiente.'; } return; }
        navigator.geolocation.getCurrentPosition(function (pos) {
            lat.value = String(pos.coords.latitude);
            lng.value = String(pos.coords.longitude);
            if (msg) { msg.hidden = false; msg.textContent = 'Ubicación capturada.'; }
        }, function () {
            if (msg) { msg.hidden = false; msg.textContent = 'No se obtuvo GPS: quedará Ubicación pendiente (no bloquea).'; }
        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
    });
})();
</script>
@endsection
