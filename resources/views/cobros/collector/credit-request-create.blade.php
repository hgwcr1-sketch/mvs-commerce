@extends('cobros.layout')

@section('title', 'Solicitud de crédito — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <a href="{{ route('cobros.collector.portfolio') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Volver a mi cartera</a>

    <div>
        <h1 class="text-2xl font-bold sm:text-3xl">Solicitud de crédito</h1>
        <p class="mt-1 text-sm text-slate-500">Ruta {{ $route->name }} · {{ $company->trade_name }}. Revisará los datos antes de enviar.</p>
        @if($resume)
            <div class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm font-semibold text-amber-900" role="status">
                Corrección solicitada: {{ $resume->latestLog()?->notes ?? 'Sin motivo registrado.' }}
            </div>
            <p class="mt-2 text-xs text-slate-500">Corrección de la solicitud #{{ $resume->id }} · Los datos existentes siguen editables y las fotos se conservan si no selecciona nuevas.</p>
        @endif
    </div>

        <form id="cobros-credit-request-form" method="POST" action="{{ route('cobros.collector.credit-requests.store') }}" enctype="multipart/form-data"
          class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        @csrf
        <input type="hidden" name="resume_id" value="{{ old('resume_id', $resumeId ?? '') }}">

        @if($errors->any())
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <p class="font-semibold">Por favor revise la información:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2" role="group" aria-label="Tipo de solicitante">
            <button type="button" data-applicant="nuevo"
                    class="min-h-[56px] w-full rounded-xl border-2 px-4 py-3 text-base font-bold"
                    aria-pressed="false">CLIENTE NUEVO</button>
            <button type="button" data-applicant="existente"
                    class="min-h-[56px] w-full rounded-xl border-2 px-4 py-3 text-base font-bold"
                    aria-pressed="false">CLIENTE EXISTENTE</button>
            <input type="hidden" name="applicant_type" id="applicant_type" value="{{ old('applicant_type', 'nuevo') }}">
        </div>
        @error('applicant_type')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div data-block="existente" class="hidden md:col-span-2">
                <label for="customer-search" class="text-sm font-semibold">Buscar cliente existente</label>
                <input id="customer-search" type="search" inputmode="search" autocomplete="off" maxlength="60"
                       placeholder="Nombre o identificación"
                       class="mt-2 min-h-[48px] w-full rounded-xl border border-slate-300 px-4 text-base">
                <p data-customer-status class="mt-1 text-xs text-slate-500" role="status" aria-live="polite">Escriba para buscar en los clientes de esta empresa.</p>
                <ul data-customer-results class="mt-2 divide-y divide-slate-100 rounded-xl border border-slate-200"></ul>
                <div data-customer-selected class="mt-3 hidden rounded-xl border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-800">
                    <p class="font-semibold" data-customer-name></p>
                    <p data-customer-meta></p>
                    <button type="button" data-customer-clear class="mt-2 min-h-[44px] rounded-xl border border-emerald-300 bg-white px-4 text-sm font-semibold text-slate-700">Cambiar cliente</button>
                </div>
                <input type="hidden" name="customer_id" id="customer_id" value="{{ old('customer_id') }}">
                @error('customer_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div data-block="nuevo" class="rounded-xl border border-slate-200 bg-slate-50 p-4 md:col-span-2">
                <p class="text-sm font-bold text-slate-800">Autorización para consultar información</p>
                <p class="mt-1 text-xs leading-relaxed text-slate-600">
                    Para preparar este préstamo podemos consultar la identidad del cliente.
                    Solo confirmamos si existe; esa consulta no aprueba ni rechaza el préstamo.
                </p>
                <label class="mt-3 flex min-h-[48px] cursor-pointer items-start gap-3 text-sm font-semibold text-slate-800">
                    <input type="checkbox" id="buro-consent" name="buro_autorizacion" value="acepto"
                           class="mt-1 h-6 w-6 rounded border-slate-400">
                    <span>El cliente autoriza la consulta de su información.</span>
                </label>
            </div>

            <div data-block="nuevo">
                <label for="identification_type" class="text-sm font-semibold">Tipo de identificación</label>
                <select id="identification_type" name="identification_type"
                        class="mt-2 min-h-[48px] w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-base">
                    <option value="">Seleccionar…</option>
                    <option value="cedula" @selected(old('identification_type') === 'cedula')>Cédula</option>
                    <option value="dimex" @selected(old('identification_type') === 'dimex')>DIMEX</option>
                    <option value="nit" @selected(old('identification_type') === 'nit')>NIT</option>
                    <option value="pasaporte" @selected(old('identification_type') === 'pasaporte')>Pasaporte</option>
                    <option value="other" @selected(old('identification_type') === 'other')>Otro</option>
                </select>
                @error('identification_type')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div data-block="nuevo">
                <label for="identification" class="text-sm font-semibold">Identificación</label>
                <input id="identification" name="identification" type="text" maxlength="50" required
                       value="{{ old('identification') }}" autocomplete="off"
                       class="mt-2 min-h-[48px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Número de identificación">
                <p id="name-lookup-status" class="mt-1 text-xs text-slate-500" hidden aria-live="polite">Buscando nombre…</p>
                @error('identification')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            {{-- 5) Nombre SOLO para CLIENTE NUEVO: en EXISTENTE se muestra la
                 persona seleccionada (sin pedir dos veces la misma información). --}}
            <div data-block="nuevo" class="md:col-span-2">
                <label for="name" class="text-sm font-semibold">Nombre</label>
                <input id="name" name="name" type="text" maxlength="150" required autocomplete="name"
                       value="{{ old('name') }}"
                       class="mt-2 min-h-[48px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Nombre completo o negocio">
                @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div data-block="nuevo">
                <label for="phone" class="text-sm font-semibold">Teléfono</label>
                <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="30"
                       value="{{ old('phone') }}"
                       class="mt-2 min-h-[48px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Opcional">
                @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div data-block="nuevo">
                <label for="address" class="text-sm font-semibold">Dirección / referencia</label>
                <input id="address" name="address" type="text" maxlength="250"
                       value="{{ old('address') }}"
                       class="mt-2 min-h-[48px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Opcional">
                @error('address')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="md:col-span-2">
                <label for="amount_requested" class="text-sm font-semibold">Monto solicitado</label>
                <div class="mt-2 flex min-h-[48px] items-center rounded-xl border border-slate-300 px-3">
                    <span class="mr-2 text-slate-500">₡</span>
                    <input id="amount_requested" name="amount_requested" type="text" inputmode="decimal" maxlength="32" required
                           value="{{ old('amount_requested') }}"
                           class="min-h-[46px] w-full border-0 px-1 text-right text-base focus:ring-0" placeholder="0.0000">
                </div>
                @error('amount_requested')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <span class="text-sm font-semibold">Foto identificación — Frente</span>
                <div class="mt-2 overflow-hidden rounded-xl border-2 border-primary bg-white">
                    <div class="flex aspect-[4/3] w-full items-center justify-center bg-slate-50 p-2">
                        <img data-preview="front" alt="Vista previa de la foto del frente" class="hidden max-h-full max-w-full object-contain">
                        <span data-placeholder="front" class="px-2 text-center text-xs text-slate-500">Sin fotografía. Use TOMAR FOTO o GALERÍA.</span>
                    </div>
                </div>
                <input id="id_front" name="id_front" type="file" accept="image/*" class="sr-only">
                <input id="id_front_camera" type="file" accept="image/*" capture="environment" class="sr-only" tabindex="-1" aria-hidden="true">
                <div class="mt-2 grid grid-cols-2 gap-2">
                    <button type="button" data-camera="front" class="min-h-[44px] cursor-pointer rounded-xl border-2 border-primary bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-primary/10">📷 TOMAR FOTO</button>
                    <label for="id_front" class="flex min-h-[44px] cursor-pointer items-center justify-center rounded-xl border-2 border-primary bg-white px-3 py-2 text-center text-sm font-semibold text-slate-800 hover:bg-primary/10">🖼 GALERÍA</label>
                </div>
                @if($resume?->id_front_path)
                    <p class="mt-2 text-xs font-semibold text-emerald-700">Foto actual ✓ · Reemplazar foto (opcional)</p>
                @elseif($customerDocs['front'] ?? false)
                    <p class="mt-2 text-xs font-semibold text-emerald-700">Documento registrado ✓ · Reemplazar foto (opcional)</p>
                @endif
                <div class="mt-2 flex items-center justify-between gap-2">
                    <p data-front-name data-default="{{ ($resume?->id_front_path || ($customerDocs['front'] ?? false)) ? 'Documento registrado ✓ · Sin reemplazo (se conserva la evidencia).' : 'Obligatoria. Sin foto aún.' }}" class="min-w-0 text-xs text-slate-500">{{ ($resume?->id_front_path || ($customerDocs['front'] ?? false)) ? 'Documento registrado ✓ · Sin reemplazo (se conserva la evidencia).' : 'Obligatoria. Sin foto aún.' }}</p>
                    <button type="button" data-clear="front" class="hidden min-h-[44px] shrink-0 cursor-pointer rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100">Quitar foto</button>
                </div>
                @error('id_front')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <span class="text-sm font-semibold">Foto identificación — Reverso</span>
                <div class="mt-2 overflow-hidden rounded-xl border-2 border-primary bg-white">
                    <div class="flex aspect-[4/3] w-full items-center justify-center bg-slate-50 p-2">
                        <img data-preview="back" alt="Vista previa de la foto del reverso" class="hidden max-h-full max-w-full object-contain">
                        <span data-placeholder="back" class="px-2 text-center text-xs text-slate-500">Sin fotografía. Use TOMAR FOTO o GALERÍA.</span>
                    </div>
                </div>
                <input id="id_back" name="id_back" type="file" accept="image/*" class="sr-only">
                <input id="id_back_camera" type="file" accept="image/*" capture="environment" class="sr-only" tabindex="-1" aria-hidden="true">
                <div class="mt-2 grid grid-cols-2 gap-2">
                    <button type="button" data-camera="back" class="min-h-[44px] cursor-pointer rounded-xl border-2 border-primary bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-primary/10">📷 TOMAR FOTO</button>
                    <label for="id_back" class="flex min-h-[44px] cursor-pointer items-center justify-center rounded-xl border-2 border-primary bg-white px-3 py-2 text-center text-sm font-semibold text-slate-800 hover:bg-primary/10">🖼 GALERÍA</label>
                </div>
                @if($resume?->id_back_path)
                    <p class="mt-2 text-xs font-semibold text-emerald-700">Foto actual ✓ · Reemplazar foto (opcional)</p>
                @elseif($customerDocs['back'] ?? false)
                    <p class="mt-2 text-xs font-semibold text-emerald-700">Documento registrado ✓ · Reemplazar foto (opcional)</p>
                @endif
                <div class="mt-2 flex items-center justify-between gap-2">
                    <p data-back-name data-default="{{ ($resume?->id_back_path || ($customerDocs['back'] ?? false)) ? 'Documento registrado ✓ · Sin reemplazo (se conserva la evidencia).' : 'Obligatoria. Sin foto aún.' }}" class="min-w-0 text-xs text-slate-500">{{ ($resume?->id_back_path || ($customerDocs['back'] ?? false)) ? 'Documento registrado ✓ · Sin reemplazo (se conserva la evidencia).' : 'Obligatoria. Sin foto aún.' }}</p>
                    <button type="button" data-clear="back" class="hidden min-h-[44px] shrink-0 cursor-pointer rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100">Quitar foto</button>
                </div>
                @error('id_back')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="mt-4">
            <input type="hidden" name="latitude" value="{{ old('latitude') }}" data-credit-latitude>
            <input type="hidden" name="longitude" value="{{ old('longitude') }}" data-credit-longitude>
            <button type="button" data-credit-gps class="min-h-[48px] w-full rounded-xl border border-primary px-4 py-3 text-base font-semibold text-slate-800 hover:bg-primary/10">
                📍 UBICACIÓN ACTUAL (OPCIONAL)
            </button>
            <p data-credit-gps-message class="mt-2 text-sm text-slate-600" role="status" aria-live="polite">
                El GPS es opcional; puede enviar la solicitud sin ubicación.
            </p>
        </div>

        <div class="mt-4">
            <label for="notes" class="text-sm font-semibold">Notas</label>
            <textarea id="notes" name="notes" rows="3" maxlength="500"
                      class="mt-2 min-h-[48px] w-full rounded-xl border border-slate-300 px-4 py-2 text-base"
                      placeholder="Observaciones (opcional)">{{ old('notes') }}</textarea>
            @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
            <a href="{{ route('cobros.collector.portfolio') }}"
               class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 px-6 text-sm font-semibold hover:bg-slate-50">Cancelar</a>
            <button type="submit" class="min-h-[48px] rounded-xl bg-primary px-6 text-base font-bold text-white hover:bg-primary-hover">Revisar solicitud</button>
        </div>
    </form>
</div>
<script>
    // Tipo de solicitante: cliente nuevo (captura) vs existente (typeahead).
    (function () {
        const hidden = document.getElementById('applicant_type');
        const buttons = document.querySelectorAll('[data-applicant]');
        function apply(type) {
            hidden.value = type;
            buttons.forEach((b) => {
                const on = b.dataset.applicant === type;
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
                b.classList.toggle('bg-primary', on);
                b.classList.toggle('text-white', on);
                b.classList.toggle('border-primary', on);
                b.classList.toggle('bg-white', !on);
                b.classList.toggle('text-slate-700', !on);
                b.classList.toggle('border-slate-300', !on);
            });
            document.querySelectorAll('[data-block]').forEach((el) => {
                el.classList.toggle('hidden', el.dataset.block !== type);
            });
            // EXISTENTE no vuelve a pedir el nombre: se muestra la persona elegida.
            const nameInput = document.getElementById('name');
            if (nameInput) { nameInput.required = (type === 'nuevo'); }
        }
        buttons.forEach((b) => b.addEventListener('click', () => apply(b.dataset.applicant)));
        apply(hidden.value === 'existente' ? 'existente' : 'nuevo');
    })();

    // CLIENTE NUEVO: lookup de nombre por identificación (debounce, nunca bloquea).
    (function () {
        const typeEl = document.getElementById('identification_type');
        const idEl = document.getElementById('identification');
        const nameEl = document.getElementById('name');
        const statusEl = document.getElementById('name-lookup-status');
        if (!typeEl || !idEl || !nameEl) { return; }
        const endpoint = @json(route('cobros.customers.identification-name'));
        {{-- 9C.3: owner_required → el cobrador NO ejecuta la consulta (la hace la oficina). --}}
        const bureau = @json(($canConsultBureau ?? false) ? route('cobros.collector.credit-requests.bureau-check') : null);
        const consentEl = document.getElementById('buro-consent');
        let timer = null, seq = 0;
        function hide() { if (statusEl) { statusEl.hidden = true; } }
        function search() {
            const type = typeEl.value, id = idEl.value.trim();
            if ((type !== 'cedula' && type !== 'dimex') || id.length < 5 || nameEl.value.trim() !== '') { hide(); return; }
            // 9C: sin autorización registrada NO se consulta la identidad externa.
            const consented = !!(consentEl && consentEl.checked);
            if (!consented) { hide(); return; }
            clearTimeout(timer);
            timer = setTimeout(() => {
                const my = ++seq;
                if (statusEl) { statusEl.hidden = false; statusEl.textContent = 'Buscando nombre…'; }
                // Orden 7B: identidad externa -> lookup existente -> manual. Nunca bloquea.
                const first = bureau
                    ? fetch(bureau + '?identification_type=' + encodeURIComponent(type) + '&identification=' + encodeURIComponent(id) + '&autorizacion=acepto', { headers: { Accept: 'application/json' } })
                    : Promise.resolve(null);
                first
                    .then((r) => (r && r.ok ? r.json() : null))
                    .then((b) => {
                        const found = !!(b && b.found);
                        return fetch(endpoint + '?identification_type=' + encodeURIComponent(type) + '&identification=' + encodeURIComponent(id), { headers: { Accept: 'application/json' } })
                            .then((r2) => (r2.ok ? r2.json() : null))
                            .then((data) => ({ found, data }));
                    })
                    .then((res) => {
                        if (my !== seq || !res) { return; }
                        hide();
                        if (res.data && res.data.found && res.data.name && !nameEl.value.trim()) { nameEl.value = res.data.name; }
                        if (res.found && statusEl) { statusEl.hidden = false; statusEl.textContent = 'Identidad registrada'; }
                    })
                    .catch(() => { hide(); });
            }, 500);
        }
        idEl.addEventListener('input', search);
        typeEl.addEventListener('change', search);
    })();

    // CLIENTE EXISTENTE: typeahead SOLO de la empresa activa.
    (function () {
        const input = document.getElementById('customer-search');
        const results = document.querySelector('[data-customer-results]');
        const status = document.querySelector('[data-customer-status]');
        const selected = document.querySelector('[data-customer-selected]');
        const hiddenId = document.getElementById('customer_id');
        const idEl = document.getElementById('identification');
        const nameEl = document.getElementById('name');
        const phoneEl = document.getElementById('phone');
        if (!input) { return; }
        const endpoint = @json(route('cobros.collector.credit-requests.customers'));
        let timer = null, seq = 0;
        function clearSelection() {
            hiddenId.value = '';
            selected.classList.add('hidden');
            results.innerHTML = '';
            if (idEl) { idEl.value = ''; }
            if (nameEl) { nameEl.value = ''; }
            if (phoneEl) { phoneEl.value = ''; }
        }
        document.querySelector('[data-customer-clear]')?.addEventListener('click', clearSelection);
        input.addEventListener('input', () => {
            const q = input.value.trim();
            clearTimeout(timer);
            if (q.length < 2) { results.innerHTML = ''; return; }
            timer = setTimeout(() => {
                const my = ++seq;
                fetch(endpoint + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })
                    .then((r) => r.ok ? r.json() : null)
                    .then((payload) => {
                        if (my !== seq || !payload) { return; }
                        results.innerHTML = '';
                        const items = payload.customers || [];
                        status.textContent = items.length ? 'Seleccione el cliente:' : 'Sin coincidencias. Escriba los datos manualmente o elija CLIENTE NUEVO.';
                        items.forEach((c) => {
                            const li = document.createElement('li');
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'flex min-h-[48px] w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-slate-50';
                            btn.textContent = c.name + (c.identification ? ' · ' + c.identification : '');
                            btn.addEventListener('click', () => {
                                hiddenId.value = c.id;
                                document.getElementById('applicant_type').value = 'existente';
                                if (idEl) { idEl.value = c.identification || ''; }
                                if (nameEl) { nameEl.value = c.name || ''; }
                                if (phoneEl) { phoneEl.value = c.phone || ''; }
                                selected.classList.remove('hidden');
                                document.querySelector('[data-customer-name]').textContent = c.name;
                                document.querySelector('[data-customer-meta]').textContent = (c.identification || '') + (c.phone ? ' · ' + c.phone : '');
                                results.innerHTML = '';
                                input.value = '';
                                status.textContent = 'Cliente vinculado.';
                            });
                            li.appendChild(btn);
                            results.appendChild(li);
                        });
                    })
                    .catch(() => { status.textContent = 'Búsqueda no disponible. Intente de nuevo.'; });
            }, 350);
        });
    })();

    // 2) Preselección desde MIS CLIENTES (?customer=): reutiliza el mismo
    //    flujo CLIENTE EXISTENTE sin duplicar personas ni volver a pedir datos.
    (function () {
        const pre = {!! json_encode($preselect ? [
            'id' => (int) $preselect->id,
            'name' => $preselect->name,
            'identification' => $preselect->identification,
            'phone' => $preselect->phone,
        ] : null) !!};
        if (!pre) { return; }
        const hiddenId = document.getElementById('customer_id');
        if (hiddenId) { hiddenId.value = String(pre.id); }
        const nameEl = document.getElementById('name');
        if (nameEl) { nameEl.value = pre.name || ''; }
        const idEl = document.getElementById('identification');
        if (idEl) { idEl.value = pre.identification || ''; }
        const phoneEl = document.getElementById('phone');
        if (phoneEl) { phoneEl.value = pre.phone || ''; }
        const box = document.querySelector('[data-customer-selected]');
        if (box) {
            box.classList.remove('hidden');
            document.querySelector('[data-customer-name]').textContent = pre.name || '';
            document.querySelector('[data-customer-meta]').textContent =
                (pre.identification || '') + (pre.phone ? ' · ' + pre.phone : '');
        }
        const existBtn = document.querySelector('[data-applicant="existente"]');
        if (existBtn) { existBtn.click(); }
        const status = document.querySelector('[data-customer-status]');
        if (status) { status.textContent = 'Cliente vinculado.'; }
    })();

    // Fotos: recuadro con vista previa, TOMAR FOTO (cámara trasera) y GALERÍA.
    const documentCropper = (() => {
        const overlay = document.createElement('div');
        overlay.setAttribute('role', 'dialog'); overlay.setAttribute('aria-modal', 'true');
        overlay.style.cssText = 'display:none;position:fixed;inset:0;z-index:2147483000;background:rgba(2,6,23,.96);padding:16px;align-items:center;justify-content:center;';
        overlay.innerHTML = '<div style="width:min(100%,900px);background:#fff;border-radius:16px;padding:12px;color:#0f172a"><h2 style="font-weight:700;font-size:1.1rem">Encuadre de identificación</h2><p style="font-size:.85rem;margin:.25rem 0 .75rem">Ajuste los cuatro bordes del documento.</p><div style="position:relative;aspect-ratio:1.586/1;max-height:58vh;background:#0f172a;overflow:hidden"><canvas style="width:100%;height:100%;display:block;touch-action:none"></canvas><div style="position:absolute;inset:4%;border:3px solid #D4AF37;pointer-events:none"></div></div><div style="display:flex;gap:8px;margin-top:12px"><button type="button" data-crop-repeat style="flex:1;min-height:44px;background:#e5e7eb;color:#111827;border:1px solid #9ca3af;border-radius:10px;font-weight:700">REPETIR FOTO</button><button type="button" data-crop-cancel style="flex:1;min-height:44px;background:#374151;color:#fff;border:1px solid #4b5563;border-radius:10px;font-weight:700">CANCELAR</button><button type="button" data-crop-confirm style="flex:1;min-height:44px;background:#D4AF37;color:#111827;border:1px solid #B1922D;border-radius:10px;font-weight:700">CONFIRMAR RECORTE</button></div></div>';
        document.body.appendChild(overlay);
        const canvas = overlay.querySelector('canvas'), ctx = canvas.getContext('2d');
        let image = null, scale = 1, x = 0, y = 0, start = null, pinch = null, target = null, source = null, previous = null, confirmed = false;
        const confirmedFiles = new Map();
        const bounds = () => { const r = canvas.getBoundingClientRect(); const cw = r.width * .92, ch = r.height * .92; const minScale = Math.max(cw / image.width, ch / image.height); return { r, minScale }; };
        const clamp = () => { const { r, minScale } = bounds(); scale = Math.max(minScale, Math.min(5, scale)); const w=image.width*scale, h=image.height*scale; x=Math.min(r.width*.04, Math.max(r.width*.96-w, x)); y=Math.min(r.height*.04, Math.max(r.height*.96-h, y)); };
        const draw = () => { if (!image) return; const r=canvas.getBoundingClientRect(); ctx.clearRect(0,0,r.width,r.height); clamp(); ctx.drawImage(image,x,y,image.width*scale,image.height*scale); };
        const fit = () => { if (!image) return; const r=canvas.getBoundingClientRect(), ratio=window.devicePixelRatio||1; canvas.width=Math.round(r.width*ratio); canvas.height=Math.round(r.height*ratio); ctx.setTransform(ratio,0,0,ratio,0,0); const b=bounds(); scale=b.minScale; x=(r.width-image.width*scale)/2; y=(r.height-image.height*scale)/2; draw(); };
        const close = (restore) => { if (restore && target && previous) { const dt=new DataTransfer(); dt.items.add(previous); target.files=dt.files; target.dataset.cropConfirmed='true'; target.dispatchEvent(new Event('change',{bubbles:true})); } overlay.style.display='none'; document.body.style.overflow=''; image=null; target=null; source=null; previous=null; pinch=null; };
        const open = (file,input,camera) => { if (!file || !file.type.startsWith('image/')) return; target=input; source=camera; previous=confirmedFiles.get(input.id)||null; input.value=''; confirmed=false; const url=URL.createObjectURL(file); image=new Image(); image.onload=()=>{URL.revokeObjectURL(url);overlay.style.display='flex';document.body.style.overflow='hidden';fit();}; image.src=url; };
        const distance = (a,b) => Math.hypot(a.clientX-b.clientX,a.clientY-b.clientY);
        canvas.addEventListener('pointerdown',e=>{e.preventDefault();canvas.setPointerCapture?.(e.pointerId);canvas._pointers=canvas._pointers||new Map();if(e.pointerType==='touch'){canvas._pointers.set(e.pointerId,e);const values=[...canvas._pointers.values()];if(values.length>1){pinch={distance:distance(values[0],values[1]),scale};start=null;return;}}start={x:e.clientX,y:e.clientY,ox:x,oy:y};});
        canvas.addEventListener('pointermove',e=>{if(e.pointerType==='touch'&&canvas._pointers?.has(e.pointerId)){canvas._pointers.set(e.pointerId,e);const values=[...canvas._pointers.values()];if(values.length>1){scale=pinch.scale*(distance(values[0],values[1])/pinch.distance);draw();return;}}if(!start)return;e.preventDefault();x=start.ox+e.clientX-start.x;y=start.oy+e.clientY-start.y;draw();});
        ['pointerup','pointercancel','pointerleave'].forEach(n=>canvas.addEventListener(n,e=>{canvas._pointers?.delete(e.pointerId);if((canvas._pointers?.size||0)<2)pinch=null;start=null;}));
        canvas.addEventListener('wheel',e=>{e.preventDefault();scale*=e.deltaY<0?1.08:.92;draw();},{passive:false});
        overlay.querySelector('[data-crop-cancel]').onclick=()=>close(true); overlay.querySelector('[data-crop-repeat]').onclick=()=>{const next=source;close(true);next?.click();};
        overlay.querySelector('[data-crop-confirm]').onclick=()=>{if(!image||!target||confirmed)return;confirmed=true;const out=document.createElement('canvas');out.width=1600;out.height=1010;const o=out.getContext('2d'),r=canvas.getBoundingClientRect(),c={x:r.width*.04,y:r.height*.04,w:r.width*.92,h:r.height*.92};o.drawImage(canvas,c.x,c.y,c.w,c.h,0,0,out.width,out.height);out.toBlob(blob=>{if(!blob)return;const cropped=new File([blob],target.id+'-cropped.jpg',{type:'image/jpeg',lastModified:Date.now()});confirmedFiles.set(target.id,cropped);const dt=new DataTransfer();dt.items.add(cropped);target.files=dt.files;target.dataset.cropConfirmed='true';target.dispatchEvent(new Event('change',{bubbles:true}));close(false);},'image/jpeg',.9);}; window.addEventListener('resize',fit); return {open, forget: (input) => confirmedFiles.delete(input.id)};
    })();
    ['front', 'back'].forEach((side) => {
        const input = document.getElementById('id_' + side);
        const camera = document.getElementById('id_' + side + '_camera');
        const label = document.querySelector('[data-' + side + '-name]');
        const preview = document.querySelector('[data-preview="' + side + '"]');
        const placeholder = document.querySelector('[data-placeholder="' + side + '"]');
        const clearBtn = document.querySelector('[data-clear="' + side + '"]');
        if (!input) { return; }
        let objectUrl = null;
        function refresh() {
            const has = !!(input.files && input.files.length);
            if (label) {
                label.textContent = has
                    ? 'Adjunta: ' + input.files[0].name
                    : (label.dataset.default || 'Obligatoria. Sin foto aún.');
                label.classList.toggle('text-emerald-700', has);
            }
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            if (has && preview && placeholder) {
                objectUrl = URL.createObjectURL(input.files[0]);
                preview.src = objectUrl;
                preview.classList.remove('hidden');
                placeholder.classList.add('hidden');
            } else {
                if (preview) { preview.removeAttribute('src'); preview.classList.add('hidden'); }
                if (placeholder) { placeholder.classList.remove('hidden'); }
            }
            if (clearBtn) { clearBtn.classList.toggle('hidden', !has); }
        }
        function assign(file) {
            // Selector cancelado (file vacío): conserva la foto anterior.
            if (!file) { return; }
            if (['cedula', 'dimex'].includes(document.getElementById('identification_type')?.value)) { documentCropper.open(file, input, camera); return; }
            try {
                const dt = new DataTransfer();
                dt.items.add(file);
                input.files = dt.files;
            } catch (e) {
                if (label) { label.textContent = 'No se pudo importar la cámara. Use GALERÍA.'; }
                return;
            }
            refresh();
        }
        input.addEventListener('change', () => { if (input.dataset.cropConfirmed === 'true') { delete input.dataset.cropConfirmed; refresh(); return; } const file = input.files && input.files[0]; if (file && ['cedula', 'dimex'].includes(document.getElementById('identification_type')?.value)) { documentCropper.open(file, input, camera); } else { refresh(); } });
        camera?.addEventListener('change', () => {
            const file = camera.files && camera.files[0];
            camera.value = '';
            assign(file);
        });
        document.querySelector('[data-camera="' + side + '"]')?.addEventListener('click', () => {
            if (!camera || typeof DataTransfer === 'undefined') { input.click(); return; }
            camera.click();
        });
        clearBtn?.addEventListener('click', () => {
            documentCropper.forget(input);
            try { input.files = new DataTransfer().files; } catch (e) { input.value = ''; }
            refresh();
        });
        refresh();
    });

    // Reduce fotos tomadas desde celular antes del POST: máximo aproximado 2 MB
    // por imagen, manteniendo resolución suficiente para leer el documento.
    (() => {
        const form = document.getElementById('cobros-credit-request-form');
        if (!form || typeof createImageBitmap !== 'function' || typeof DataTransfer === 'undefined') { return; }
        const maxBytes = 2 * 1024 * 1024;
        const maxSide = 2200;
        const notify = (message) => {
            let box = form.querySelector('[data-photo-processing-error]');
            if (!box) {
                box = document.createElement('div');
                box.dataset.photoProcessingError = 'true';
                box.setAttribute('role', 'alert');
                box.className = 'mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700';
                form.prepend(box);
            }
            box.textContent = message;
        };
        const compress = async (input) => {
            const file = input.files?.[0];
            if (!file || !file.type.startsWith('image/') || file.size <= maxBytes) { return; }
            const bitmap = await createImageBitmap(file);
            const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(bitmap.width * scale));
            canvas.height = Math.max(1, Math.round(bitmap.height * scale));
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            bitmap.close();
            let quality = 0.86;
            let blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
            while (blob && blob.size > maxBytes && quality > 0.5) {
                quality -= 0.08;
                blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
            }
            if (!blob || blob.size > maxBytes) { throw new Error('La imagen no pudo reducirse a un tamaño seguro.'); }
            const replacement = new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() });
            const dt = new DataTransfer();
            dt.items.add(replacement);
            input.files = dt.files;
        };
        form.addEventListener('submit', async (event) => {
            if (form.dataset.photosReady === 'true') { return; }
            event.preventDefault();
            try {
                await Promise.all(['front', 'back'].map((side) => compress(document.getElementById('id_' + side))));
                form.dataset.photosReady = 'true';
                form.requestSubmit();
            } catch (error) {
                notify(error instanceof Error ? error.message : 'No se pudieron procesar las fotografías. Use imágenes más pequeñas.');
            }
        });
    })();

    document.querySelector('[data-credit-gps]')?.addEventListener('click', () => {
        const message = document.querySelector('[data-credit-gps-message]');
        if (!navigator.geolocation) {
            message.textContent = 'GPS no disponible. Puede enviar la solicitud sin ubicación.';
            return;
        }
        message.textContent = 'Obteniendo ubicación…';
        navigator.geolocation.getCurrentPosition(
            (position) => {
                document.querySelector('[data-credit-latitude]').value = position.coords.latitude;
                document.querySelector('[data-credit-longitude]').value = position.coords.longitude;
                message.textContent = 'Ubicación agregada.';
            },
            () => { message.textContent = 'No se pudo obtener GPS. Puede enviar la solicitud sin ubicación.'; },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        );
    });
</script>
@endsection
