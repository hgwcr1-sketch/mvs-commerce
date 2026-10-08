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

    <form method="POST" action="{{ route('cobros.collector.credit-requests.review') }}" enctype="multipart/form-data"
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
                <label for="id_front" class="mt-2 flex min-h-[56px] w-full cursor-pointer items-center justify-center rounded-xl border-2 border-primary bg-white px-4 py-3 text-center text-base font-semibold text-slate-800 hover:bg-primary/10">
                    📷 Tomar/subir foto del frente
                </label>
                <input id="id_front" name="id_front" type="file" accept="image/*" class="sr-only">
                @if($resume?->id_front_path)
                    <p class="mt-1 text-xs font-semibold text-emerald-700">Foto actual ✓ · Reemplazar foto (opcional)</p>
                @elseif($customerDocs['front'] ?? false)
                    <p class="mt-1 text-xs font-semibold text-emerald-700">Documento registrado ✓ · Reemplazar foto (opcional)</p>
                @endif
                <p data-front-name data-default="{{ ($resume?->id_front_path || ($customerDocs['front'] ?? false)) ? 'Documento registrado ✓ · Sin reemplazo (se conserva la evidencia).' : 'Obligatoria. Sin foto aún.' }}" class="mt-1 text-xs text-slate-500">{{ ($resume?->id_front_path || ($customerDocs['front'] ?? false)) ? 'Documento registrado ✓ · Sin reemplazo (se conserva la evidencia).' : 'Obligatoria. Sin foto aún.' }}</p>
                @error('id_front')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <span class="text-sm font-semibold">Foto identificación — Reverso</span>
                <label for="id_back" class="mt-2 flex min-h-[56px] w-full cursor-pointer items-center justify-center rounded-xl border-2 border-primary bg-white px-4 py-3 text-center text-base font-semibold text-slate-800 hover:bg-primary/10">
                    📷 Tomar/subir foto del reverso
                </label>
                <input id="id_back" name="id_back" type="file" accept="image/*" class="sr-only">
                @if($resume?->id_back_path)
                    <p class="mt-1 text-xs font-semibold text-emerald-700">Foto actual ✓ · Reemplazar foto (opcional)</p>
                @elseif($customerDocs['back'] ?? false)
                    <p class="mt-1 text-xs font-semibold text-emerald-700">Documento registrado ✓ · Reemplazar foto (opcional)</p>
                @endif
                <p data-back-name data-default="{{ ($resume?->id_back_path || ($customerDocs['back'] ?? false)) ? 'Documento registrado ✓ · Sin reemplazo (se conserva la evidencia).' : 'Obligatoria. Sin foto aún.' }}" class="mt-1 text-xs text-slate-500">{{ ($resume?->id_back_path || ($customerDocs['back'] ?? false)) ? 'Documento registrado ✓ · Sin reemplazo (se conserva la evidencia).' : 'Obligatoria. Sin foto aún.' }}</p>
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
            if (type !== 'cedula' || id.length < 5 || nameEl.value.trim() !== '') { hide(); return; }
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

    // Nombres de archivo en los botones de foto.
    ['front', 'back'].forEach((side) => {
        const input = document.getElementById('id_' + side);
        input?.addEventListener('change', () => {
            const label = document.querySelector('[data-' + side + '-name]');
            if (!label) { return; }
            label.textContent = input.files && input.files.length
                ? 'Adjunta: ' + input.files[0].name
                : (label.dataset.default || 'Obligatoria. Sin foto aún.');
            label.classList.toggle('text-emerald-700', !!(input.files && input.files.length));
        });
    });

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
