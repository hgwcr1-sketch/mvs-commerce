@extends('layouts.app')
@section('content')
<div class="mx-auto max-w-2xl space-y-5 pb-24 md:pb-8">
    <section class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-7">
        <p class="text-sm font-bold uppercase tracking-wide text-amber-700">{{ $wizardTitle }}</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-950">
            @switch($step)
                @case('datos') Paso 1 de 5 · Datos fiscales @break
                @case('conexion') Paso 2 de 5 · Conexión fiscal @break
                @case('verificar') Paso 3 de 5 · Verificar conexión @break
                @case('preferencias') Paso 4 de 5 · Preferencias @break
                @default Paso 5 de 5 · Revisar y finalizar
            @endswitch
        </h1>

        @if(session('status'))
            <div class="mt-4 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            @php($summaryErrors = collect($errors->messages())->except(['api_key', 'api_secret'])->flatten()->all())
            @if(count($summaryErrors) > 0)
                <div class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-800">
                    <ul class="list-disc pl-5">
                        @foreach($summaryErrors as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif

        @if($step === 'datos')
            <form method="POST" action="{{ route('fiscal.setup.store', ['step' => 'datos']) }}" class="mt-4 space-y-4">
                @csrf @method('PUT')
                <p class="text-sm font-bold uppercase tracking-wide text-amber-700">Datos fiscales de la empresa</p>
                <div>
                    <label class="text-sm font-bold" for="identification_type">Tipo de identificación</label>
                    <select id="identification_type" name="identification_type" required class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                        <option value="">Elegir…</option>
                        <option value="01" @selected((string) old('identification_type', $company->identification_type) === '01')>01 Cédula Física</option>
                        <option value="02" @selected((string) old('identification_type', $company->identification_type) === '02')>02 Cédula Jurídica</option>
                        <option value="03" @selected((string) old('identification_type', $company->identification_type) === '03')>03 DIMEX</option>
                        <option value="04" @selected((string) old('identification_type', $company->identification_type) === '04')>04 NITE</option>
                        <option value="05" @selected((string) old('identification_type', $company->identification_type) === '05')>05 Extranjero no domiciliado</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-bold" for="identification_number">Número de identificación</label>
                    <input id="identification_number" name="identification_number" value="{{ old('identification_number', $company->identification_number) }}" required class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                    <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                        <button type="button" id="fiscal_lookup_button" class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-amber-300 bg-white px-4 font-bold text-amber-800 hover:bg-amber-50">Consultar en Hacienda</button>
                        <p id="fiscal_identification_status" class="text-xs text-slate-500" role="status" aria-live="polite"></p>
                    </div>
                </div>
                <div>
                    <label class="text-sm font-bold" for="legal_name">Nombre fiscal</label>
                    <input id="legal_name" name="legal_name" value="{{ old('legal_name', $company->legal_name) }}" required class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
                <div>
                    <label class="text-sm font-bold" for="email">Correo fiscal</label>
                    <input id="email" type="email" name="email" value="{{ old('email', $company->email) }}" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
                <p class="text-sm font-bold uppercase tracking-wide text-amber-700">Actividad económica y ubicación fiscal</p>
                <div>
                    <label class="text-sm font-bold" for="economic_activity">Actividad económica (código)</label>
                    <input id="economic_activity" name="economic_activity" value="{{ old('economic_activity', $config->economic_activity) }}" placeholder="Ej. 1071.9" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                    <p class="mt-1 text-xs text-slate-500">Use la actividad económica registrada por su empresa ante Hacienda. Este campo no es el código CABYS de un producto.</p>
                    <div id="fiscal_activity_picker" class="mt-2 hidden">
                        <label class="text-xs font-bold text-slate-600" for="fiscal_activity_select">Actividades económicas registradas ante Hacienda</label>
                        <select id="fiscal_activity_select" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]"></select>
                        <p id="fiscal_activity_hint" class="mt-1 text-xs text-slate-500"></p>
                    </div>
                </div>
                <div>
                    <span class="text-sm font-bold">Ubicación fiscal</span>
                    <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label class="text-xs font-bold text-slate-600" for="province_id">Provincia</label>
                            <select id="province_id" name="province_id" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                                <option value="">Elegir…</option>
                                @foreach($provinces as $province)
                                    <option value="{{ $province->id }}" @selected((string) old('province_id', $company->province_id) === (string) $province->id)>{{ $province->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-600" for="canton_id">Cantón</label>
                            <select id="canton_id" name="canton_id" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                                <option value="">Elegir…</option>
                                @foreach($provinces as $province)
                                    <optgroup label="{{ $province->name }}">
                                        @foreach($cantons->where('province_id', $province->id) as $canton)
                                            <option value="{{ $canton->id }}" @selected((string) old('canton_id', $company->canton_id) === (string) $canton->id)>{{ $canton->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-600" for="district_id">Distrito</label>
                            <select id="district_id" name="district_id" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                                <option value="">Elegir…</option>
                                @foreach($cantons as $canton)
                                    <optgroup label="{{ $canton->name }}">
                                        @foreach($districts->where('canton_id', $canton->id) as $district)
                                            <option value="{{ $district->id }}" @selected((string) old('district_id', $company->district_id) === (string) $district->id)>{{ $district->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="text-sm font-bold" for="address">Otras señas</label>
                    <input id="address" name="address" value="{{ old('address', $company->address) }}" placeholder="Dirección exacta" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
                <p class="text-sm font-bold uppercase tracking-wide text-amber-700">Sucursal / terminal</p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-sm font-bold" for="fiscal_branch_code">Sucursal fiscal (3 dígitos)</label>
                        <input id="fiscal_branch_code" name="fiscal_branch_code" inputmode="numeric" value="{{ old('fiscal_branch_code', $config->fiscal_branch_code) }}" placeholder="001" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                    </div>
                    <div>
                        <label class="text-sm font-bold" for="fiscal_terminal_code">Terminal fiscal (5 dígitos)</label>
                        <input id="fiscal_terminal_code" name="fiscal_terminal_code" inputmode="numeric" value="{{ old('fiscal_terminal_code', $config->fiscal_terminal_code) }}" placeholder="00001" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                    </div>
                </div>
                <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black shadow-md hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">Guardar y continuar</button>
                <a href="{{ route('fiscal.index') }}" class="ml-2 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Volver al portal</a>
            </form>
            @push('scripts')
            <script>
                // El bundle de Vite entra como módulo (defer), así que el inline
                // del cuerpo se ejecuta ANTES de que exista window.MvsIdentification.
                // Por eso la inicialización espera a que el documento esté listo.
                (function () {
                    const endpoint = @json(route('fiscal.contribuyente'));
                    const typeInput = document.getElementById('identification_type');
                    const numberInput = document.getElementById('identification_number');
                    const legalNameInput = document.getElementById('legal_name');
                    const activityInput = document.getElementById('economic_activity');
                    const status = document.getElementById('fiscal_identification_status');
                    const picker = document.getElementById('fiscal_activity_picker');
                    const select = document.getElementById('fiscal_activity_select');
                    const hint = document.getElementById('fiscal_activity_hint');
                    const button = document.getElementById('fiscal_lookup_button');

                    if (!numberInput) return;

                    let timer = null;
                    let token = 0;

                    function setStatus(text, className) {
                        status.textContent = text || '';
                        status.className = 'text-xs ' + (className || 'text-slate-500');
                    }

                    function hidePicker() {
                        picker.classList.add('hidden');
                        select.innerHTML = '';
                        hint.textContent = '';
                    }

                    // Solo se ofrecen actividades realmente devueltas por Hacienda.
                    function renderActivities(activities, consulted) {
                        if (!consulted || !Array.isArray(activities) || activities.length === 0) {
                            hidePicker();
                            return;
                        }

                        const real = activities.filter((a) => a && a.code);

                        if (real.length === 0) {
                            hidePicker();
                            return;
                        }

                        select.innerHTML = '';

                        real.forEach((activity) => {
                            const option = document.createElement('option');
                            option.value = activity.code;
                            option.textContent = activity.code + ' — ' + (activity.description || 'Sin descripción');
                            option.dataset.description = activity.description || '';
                            select.appendChild(option);
                        });

                        if (real.length === 1) {
                            select.disabled = true;
                            hint.textContent = 'Hacienda registra una sola actividad; se seleccionó automáticamente.';
                        } else {
                            const empty = document.createElement('option');
                            empty.value = '';
                            empty.textContent = 'Elegir…';
                            select.insertBefore(empty, select.firstChild);
                            select.disabled = false;
                            hint.textContent = 'Seleccione la actividad económica principal de la empresa.';
                        }

                        activityInput.value = real[0].code;
                        picker.classList.remove('hidden');
                    }

                    select.addEventListener('change', function () {
                        if (select.value) activityInput.value = select.value;
                    });

                    function autofill(result) {
                        const name = result && result.name;

                        if (name && !legalNameInput.value.trim()) legalNameInput.value = name;

                        const activities = (result && result.activities) || [];
                        const consulted = !!(result && result.activities_queried);
                        renderActivities(activities, consulted);

                        if (!consulted && result && result.status !== 'not_found') {
                            hidePicker();
                        }
                    }

                    async function lookup() {
                        const M = window.MvsIdentification;

                        if (!M) {
                            setStatus('No fue posible consultar Hacienda. Puede continuar.');
                            return;
                        }

                        const type = M.resolveType(typeInput.value, numberInput.value);
                        const value = numberInput.value;

                        if (!type || !M.canLookup(type)) {
                            setStatus('La identificación no está completa para el tipo seleccionado.');
                            hidePicker();
                            return;
                        }

                        if (!M.transform(type, value)) {
                            setStatus('La identificación no está completa para el tipo seleccionado.');
                            hidePicker();
                            return;
                        }

                        const current = ++token;
                        setStatus(M.statusText('loading'), 'text-slate-500');

                        try {
                            const result = await M.consult(type, value, endpoint);

                            if (current !== token) return;

                            setStatus(M.statusText(result.status, result.name), M.statusClass(result.status));
                            autofill(result);
                        } catch (error) {
                            if (current !== token) return;

                            // Fallback manual: nunca se bloquea el paso 1.
                            setStatus(M.statusText('error'), M.statusClass('error'));
                            hidePicker();
                        }
                    }

                    function schedule() {
                        window.clearTimeout(timer);
                        timer = window.setTimeout(lookup, 450);
                    }

                    function start() {
                        // El módulo de identificación puede llegar después del DOM.
                        if (!window.MvsIdentification) {
                            window.setTimeout(start, 50);
                            return;
                        }

                        numberInput.addEventListener('input', schedule);
                        typeInput.addEventListener('change', schedule);

                        if (button) {
                            button.addEventListener('click', function () {
                                window.clearTimeout(timer);
                                lookup();
                            });
                        }
                    }

                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', start);
                    } else {
                        start();
                    }
                })();
            </script>
            @endpush
        @elseif($step === 'conexion')
            @if($config->hasPending())
                <div class="mt-4 rounded-xl bg-amber-100 p-4 text-sm font-bold text-amber-900">
                    Actualización requerida: hay cambios pendientes de verificación. La conexión actual sigue funcionando.
                </div>
                <form method="POST" action="{{ route('fiscal.connection.discard') }}" class="mt-2">
                    @csrf
                    <button type="submit" class="inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Cancelar cambios pendientes</button>
                </form>
            @endif
            <form method="POST" action="{{ route('fiscal.setup.store', ['step' => 'conexion']) }}" class="mt-4 space-y-4">
                @csrf @method('PUT')
                <p class="text-sm text-slate-600">Ingrese las credenciales de conexión para habilitar la facturación electrónica.</p>
                <div>
                    <span class="text-sm font-bold">Ambiente</span>
                    <div class="mt-2 space-y-2">
                        <label class="flex min-h-[44px] items-center gap-2 rounded-xl border border-slate-300 p-3">
                            <input type="radio" name="environment" value="sandbox" @checked(old('environment', $config->pending_environment ?? $config->environment) === 'sandbox')> Pruebas (sin validez fiscal)
                        </label>
                        <label class="flex min-h-[44px] items-center gap-2 rounded-xl border border-slate-300 p-3">
                            <input type="radio" name="environment" value="production" @checked(old('environment', $config->pending_environment ?? $config->environment) === 'production')> Producción (documentos reales)
                        </label>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Las credenciales de pruebas nunca se copian a producción.</p>
                </div>
                <label class="flex min-h-[44px] items-center gap-2 text-sm">
                    <input type="checkbox" name="production_confirm" value="1" class="h-5 w-5"> Entiendo que producción emite documentos con validez fiscal (obligatorio para producción)
                </label>
                <div>
                    <label class="text-sm font-bold" for="api_key">Llave de conexión {{ $config->maskedKey() ? '(registrada: ' . $config->maskedKey() . ')' : '' }}</label>
                    <input id="api_key" type="password" name="api_key" autocomplete="off" placeholder="Vacío = conservar la actual" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                    @error('api_key')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="text-sm font-bold" for="api_secret">Secreto de conexión {{ $config->maskedSecret() ? '(registrado)' : '' }}</label>
                    <input id="api_secret" type="password" name="api_secret" autocomplete="off" placeholder="Vacío = conservar el actual" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                    @error('api_secret')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black shadow-md hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">Guardar y continuar</button>
                <a href="{{ route('fiscal.setup', ['step' => 'datos']) }}" class="ml-2 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Anterior</a>
            </form>
            @if($config->hasCredentials())
                <section aria-label="Zona de seguridad" class="mt-6 rounded-2xl border-2 border-rose-300 bg-rose-50/50 p-4">
                    <h3 class="font-bold text-rose-900">Zona de seguridad</h3>
                    <p class="mt-1 text-sm text-rose-800">Retirar la conexión impide emitir hasta reconfigurar. El historial fiscal se conserva siempre.</p>
                    <form method="POST" action="{{ route('fiscal.disconnect') }}" class="mt-3" onsubmit="return confirm('¿Retirar la conexión fiscal? El historial se conserva.');">
                        @csrf
                        <label class="flex min-h-[44px] items-center gap-2 text-sm text-rose-900">
                            <input type="checkbox" name="disconnect_confirm" value="1" class="h-5 w-5"> Confirmo que quiero retirar la conexión
                        </label>
                        <button type="submit" class="mt-2 inline-flex min-h-[44px] items-center rounded-xl border border-rose-300 bg-white px-4 text-sm font-bold text-rose-700">Retirar conexión</button>
                    </form>
                </section>
            @endif
        @elseif($step === 'verificar')
            @if($config->hasPending())
                <div class="mt-4 rounded-xl bg-amber-100 p-4 text-sm font-bold text-amber-900">Hay cambios pendientes: al verificar con éxito se activan. Si falla, la conexión actual sigue intacta.</div>
            @endif
            <p class="mt-4 text-sm text-slate-600">Comprobamos que su conexión fiscal esté correctamente configurada, sin emitir ningún documento ni consumir cuota.</p>
            <form method="POST" action="{{ route('fiscal.verify') }}" class="mt-4">
                @csrf
                <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black shadow-md hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">Verificar conexión</button>
                <a href="{{ route('fiscal.setup', ['step' => 'conexion']) }}" class="ml-2 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Anterior</a>
                <a href="{{ route('fiscal.setup', ['step' => 'preferencias']) }}" class="ml-2 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Omitir por ahora</a>
            </form>
            @if($config->hasPending())
                <div class="mt-4 rounded-xl bg-amber-100 p-4 text-sm font-bold text-amber-900">Hay cambios pendientes de verificación: al verificar con éxito se activan. Si falla, la conexión actual sigue intacta.</div>
            @elseif($config->last_verified_at)
                <p class="mt-3 text-sm text-emerald-800">Última verificación: {{ $config->last_verified_at->format('d/m/Y H:i') }}.</p>
            @endif
        @elseif($step === 'preferencias')
            <form method="POST" action="{{ route('fiscal.setup.store', ['step' => 'preferencias']) }}" class="mt-4 space-y-4">
                @csrf @method('PUT')
                <div>
                    <span class="text-sm font-bold">Documento predeterminado</span>
                    <div class="mt-2 space-y-2">
                        <label class="flex min-h-[44px] items-center gap-2 rounded-xl border border-slate-300 p-3">
                            <input type="radio" name="default_document" value="01" @checked(old('default_document', $config->default_document) === '01')> Factura electrónica
                        </label>
                        <label class="flex min-h-[44px] items-center gap-2 rounded-xl border border-slate-300 p-3">
                            <input type="radio" name="default_document" value="04" @checked(old('default_document', $config->default_document) === '04')> Tiquete electrónico
                        </label>
                    </div>
                </div>
                <label class="flex min-h-[44px] items-center gap-2 text-sm">
                    <input type="checkbox" name="auto_emit_enabled" value="1" @checked(old('auto_emit_enabled', $config->auto_emit_enabled)) class="h-5 w-5"> Emitir automáticamente al completar una venta en el POS
                </label>
                <label class="flex min-h-[44px] items-center gap-2 text-sm">
                    <input type="checkbox" name="notify_receptor_email" value="1" @checked(old('notify_receptor_email', $config->notify_receptor_email)) class="h-5 w-5"> Avisar por correo al receptor cuando esté disponible
                </label>
                <p class="text-xs text-slate-500">El tiquete interno nunca sale a Hacienda ni consume cuota.</p>
                <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black shadow-md hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">Guardar y continuar</button>
                <a href="{{ route('fiscal.setup', ['step' => 'verificar']) }}" class="ml-2 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Anterior</a>
            </form>
        @else
            <p class="mt-4 text-sm text-slate-600">Revise que todo esté correcto antes de finalizar. Puede volver a cualquier paso sin perder datos.</p>
            <dl class="mt-4 space-y-2 text-sm text-slate-700">
                <div class="flex justify-between gap-3"><dt>Empresa</dt><dd class="font-bold text-right">{{ $company->legal_name ?: $company->trade_name }} <a href="{{ route('fiscal.setup', ['step' => 'datos']) }}" class="font-bold text-amber-700">Editar</a></dd></div>
                <div class="flex justify-between gap-3"><dt>Actividad económica</dt><dd class="font-bold text-right">{{ $config->economic_activity ?: 'Pendiente' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Ubicación fiscal</dt><dd class="font-bold text-right">{{ $locationComplete ? 'Completa' : 'Pendiente' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Sucursal / Terminal</dt><dd class="font-bold text-right">{{ ($config->fiscal_branch_code ?: '—') . ' / ' . ($config->fiscal_terminal_code ?: '—') }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Ambiente</dt><dd class="font-bold text-right">{{ $config->isProduction() ? 'Producción' : 'Pruebas' }} <a href="{{ route('fiscal.setup', ['step' => 'conexion']) }}" class="font-bold text-amber-700">Editar</a></dd></div>
                <div class="flex justify-between gap-3"><dt>Conexión fiscal</dt><dd class="font-bold text-right">{{ $config->last_error_code !== null ? 'Requiere atención' : (($config->last_verified_at && ! $config->hasPending()) ? 'Verificada' : ($config->hasCredentials() ? 'Credenciales registradas' : 'Pendiente')) }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Verificación</dt><dd class="font-bold text-right">{{ $config->last_verified_at && !$config->hasPending() ? $config->last_verified_at->format('d/m/Y H:i') : 'Pendiente' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Documento</dt><dd class="font-bold text-right">{{ $config->default_document === '04' ? 'Tiquete electrónico' : 'Factura electrónica' }} <a href="{{ route('fiscal.setup', ['step' => 'preferencias']) }}" class="font-bold text-amber-700">Editar</a></dd></div>
                <div class="flex justify-between gap-3"><dt>Estado</dt><dd class="font-bold text-right">{{ $statusLabel }}</dd></div>
            </dl>
            <form method="POST" action="{{ route('fiscal.setup.finish') }}" class="mt-5">
                @csrf
                <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black shadow-md hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">Confirmar y finalizar</button>
                <a href="{{ route('fiscal.setup', ['step' => 'preferencias']) }}" class="ml-2 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Anterior</a>
            </form>
        @endif
    </section>
</div>
@endsection
