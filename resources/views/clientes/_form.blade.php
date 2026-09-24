@csrf

@if ($errors->any())

    <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4">

        <p class="font-semibold text-red-700">
            Por favor revise la información:
        </p>

        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-600">

            @foreach ($errors->all() as $error)

                <li>{{ $error }}</li>

            @endforeach

        </ul>

    </div>

@endif

<x-card>

    <x-slot:header>
        <h3 class="text-lg font-semibold">
            Información General
        </h3>
    </x-slot:header>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <x-select
            name="customer_type"
            label="Tipo de Cliente">

            <option value="individual" @selected(old('customer_type', $customer->customer_type ?? 'individual') == 'individual')>
                Persona Física
            </option>

            <option value="company" @selected(old('customer_type', $customer->customer_type ?? '') == 'company')>
                Empresa
            </option>

        </x-select>

        <x-select
            name="identification_type"
            label="Tipo de Identificación">

            <option value="">Seleccione...</option>

            <option value="01" @selected(old('identification_type', $customer->identification_type ?? '')=='01')>
                Cédula Física
            </option>

            <option value="02" @selected(old('identification_type', $customer->identification_type ?? '')=='02')>
                Cédula Jurídica
            </option>

            <option value="03" @selected(old('identification_type', $customer->identification_type ?? '')=='03')>
                DIMEX
            </option>

            <option value="04" @selected(old('identification_type', $customer->identification_type ?? '')=='04')>
                NITE
            </option>

            <option value="05" @selected(old('identification_type', $customer->identification_type ?? '')=='05')>
                Extranjero no domiciliado
            </option>

        </x-select>

        <x-input
    name="customer_code"
    label="Código Comercial"
    :value="old('customer_code', $customer->customer_code ?? '')"
    placeholder="000001 (se genera automático si vacío)" />

        <x-input
    name="identification"
    label="Número de Identificación"
    :value="old('identification', $customer->identification ?? '')" />

        <x-input
            name="name"
            label="Nombre"
            :value="old('name', $customer->name ?? '')"
            required />

        <x-input
            name="commercial_name"
            label="Nombre Comercial"
            :value="old('commercial_name', $customer->commercial_name ?? '')" />

        <x-input
            name="taxpayer_name"
            label="Nombre para Facturación"
            :value="old('taxpayer_name', $customer->taxpayer_name ?? '')" />

        <div>
            <label class="form-label">Teléfono</label>
            <div class="grid grid-cols-[7rem_1fr] gap-2">
                <div>
                    <input name="phone_country_code" type="text" inputmode="tel" maxlength="5" placeholder="+506" aria-label="Código de país" value="{{ old('phone_country_code', $customer->phone_country_code ?? $defaultPhoneCountryCode ?? '') }}" class="form-input">
                    @error('phone_country_code')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <input name="phone" type="text" inputmode="tel" maxlength="30" placeholder="83526142" aria-label="Número de teléfono" value="{{ old('phone', $customer->phone ?? '') }}" class="form-input">
                    @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
            <p class="mt-1 text-xs text-slate-500">Código país | Número</p>
        </div>

        <x-input
            name="mobile"
            label="Celular"
            :value="old('mobile', $customer->mobile ?? '')" />

        <x-input
            type="email"
            name="email"
            label="Correo Electrónico"
            :value="old('email', $customer->email ?? '')" />

    </div>

</x-card>

<x-card class="mt-6">

    <x-slot:header>
        <h3 class="text-lg font-semibold">
            Dirección
        </h3>
    </x-slot:header>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <x-select
    name="country_id"
    label="País">

    <option value="">Seleccione...</option>

    @foreach($countries as $country)

        <option
            value="{{ $country->id }}"
            @selected(old('country_id', $customer->country_id ?? '') == $country->id)>

            {{ $country->name }}

        </option>

    @endforeach

</x-select>

        <x-select
    name="province_id"
    label="Provincia">

    <option value="">Seleccione...</option>

    @foreach($provinces as $province)

        <option
            value="{{ $province->id }}"
            @selected(old('province_id', $customer->province_id ?? '') == $province->id)>

            {{ $province->name }}

        </option>

    @endforeach

</x-select>

        <x-select
    name="canton_id"
    label="Cantón">

    <option value="">Seleccione...</option>

    @foreach($cantons as $canton)

        <option
            value="{{ $canton->id }}"
            @selected(old('canton_id', $customer->canton_id ?? '') == $canton->id)>

            {{ $canton->name }}

        </option>

    @endforeach

</x-select>

        <x-select
    name="district_id"
    label="Distrito">

    <option value="">Seleccione...</option>

    @foreach($districts as $district)

        <option
            value="{{ $district->id }}"
            @selected(old('district_id', $customer->district_id ?? '') == $district->id)>

            {{ $district->name }}

        </option>

    @endforeach

</x-select>

    </div>

    <div class="mt-6">

        <x-textarea
            name="address"
            label="Dirección Exacta"
            rows="3"
            :value="old('address', $customer->address ?? '')" />

            <div class="mt-6">

    <x-textarea
        name="notes"
        label="Observaciones"
        rows="4"
        :value="old('notes', $customer->notes ?? '')" />

</div>

    </div>

</x-card>

<x-card class="mt-6">

    <x-slot:header>
        <h3 class="text-lg font-semibold">

            Información Comercial
        </h3>
    </x-slot:header>

    @php
        // R01 RouteOS: el crédito solo lo administra routeos.credito.administrar.
        $routeosCompany = \App\Models\Company::find(session('active_company_id'));
        $canManageCredit = auth()->user()?->hasPermission('routeos.credito.administrar', $routeosCompany);
    @endphp

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <div>
            <label class="form-label" for="credit_limit">
                Límite de Crédito
            </label>

            <input
                type="number"
                step="1"
                id="credit_limit"
                name="credit_limit"
                value="{{ old('credit_limit', $customer->credit_limit ?? 0) }}"
                inputmode="decimal"
                @unless($canManageCredit) readonly @endunless
                class="form-input @unless($canManageCredit) bg-slate-100 text-slate-600 cursor-not-allowed @endunless" />

            @unless($canManageCredit)
                <p class="mt-1 text-xs text-slate-500">
                    El límite de crédito solo puede ser modificado por un usuario con permiso de crédito.
                </p>
            @endunless

            @error('credit_limit')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

    <x-select
    name="price_level"
    label="Nivel de Precio">

    <option value="normal"
        @selected(old('price_level', $customer->price_level ?? 'normal') === 'normal')>
        Normal
    </option>

    <option value="wholesale"
        @selected(old('price_level', $customer->price_level ?? 'normal') === 'wholesale')>
        Mayorista
    </option>

    <option value="a"
        @selected(old('price_level', $customer->price_level ?? 'normal') === 'a')>
        Precio A
    </option>

    <option value="b"
        @selected(old('price_level', $customer->price_level ?? 'normal') === 'b')>
        Precio B
    </option>

    <option value="c"
        @selected(old('price_level', $customer->price_level ?? 'normal') === 'c')>
        Precio C
    </option>

</x-select>

        <div>
            <label class="form-label" for="credit_days">
                Días de Crédito
            </label>

            <input
                type="number"
                id="credit_days"
                name="credit_days"
                value="{{ old('credit_days', $customer->credit_days ?? 0) }}"
                inputmode="numeric"
                @unless($canManageCredit) readonly @endunless
                class="form-input @unless($canManageCredit) bg-slate-100 text-slate-600 cursor-not-allowed @endunless" />

            @unless($canManageCredit)
                <p class="mt-1 text-xs text-slate-500">
                    El plazo de crédito solo puede ser modificado por un usuario con permiso de crédito.
                </p>
            @endunless

            @error('credit_days')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
    <label class="form-label">
        Puntos
    </label>

    <div class="form-input bg-slate-100 text-slate-600 cursor-not-allowed">
        {{ number_format($customer->points ?? 0) }}
    </div>

    <p class="mt-1 text-xs text-slate-500">
        Los puntos se administran automáticamente desde Fidelización.
    </p>
</div>
                
        <x-input
            type="date"
            name="birth_date"
            label="Fecha de Nacimiento"
            :value="isset($customer->birth_date) ? $customer->birth_date->format('Y-m-d') : ''" />

    </div>

</x-card>

<x-card class="mt-6">

    <x-slot:header>
        <h3 class="text-lg font-semibold">
            Ubicación
        </h3>
    </x-slot:header>

    @php
        $routeosHasLocation = $customer->exists && $customer->hasLocation();
        $routeosMapsUrl = $customer->exists ? $customer->google_maps_url : null;
        $routeosWazeUrl = $customer->exists ? $customer->waze_url : null;
    @endphp

    <p class="mb-4 text-sm text-slate-500">
        Coordenadas de visita para RouteOS. Use el botón para capturar su ubicación actual desde el dispositivo, o escríbalas manualmente.
    </p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <div>
            <label class="form-label" for="geo-latitude">Latitud</label>
            <input
                type="number"
                id="geo-latitude"
                name="latitude"
                value="{{ old('latitude', $customer->latitude ?? '') }}"
                step="0.0000001"
                min="-90"
                max="90"
                inputmode="decimal"
                placeholder="-9.9281"
                class="form-input" />
            @error('latitude')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="form-label" for="geo-longitude">Longitud</label>
            <input
                type="number"
                id="geo-longitude"
                name="longitude"
                value="{{ old('longitude', $customer->longitude ?? '') }}"
                step="0.0000001"
                min="-180"
                max="180"
                inputmode="decimal"
                placeholder="-84.0907"
                class="form-input" />
            @error('longitude')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="md:col-span-2">
            <label class="form-label" for="geo-reference">Referencia de ubicación</label>
            <input
                type="text"
                id="geo-reference"
                name="location_reference"
                value="{{ old('location_reference', $customer->location_reference ?? '') }}"
                maxlength="500"
                placeholder="Ej: 200 m oeste del parque, local con letrero azul"
                class="form-input" />
            @error('location_reference')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

    </div>

    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">

        <button
            type="button"
            id="geo-capture"
            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-slate-900 cursor-pointer hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-60">

            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0-5a4 4 0 100-8 4 4 0 000 8zm0-3.5a.5.5 0 100-1 .5.5 0 000 1z"/>
            </svg>

            <span id="geo-capture-label">Usar mi ubicación actual</span>
        </button>

        <p id="geo-status" class="hidden rounded-lg px-3 py-2 text-xs sm:text-sm"></p>

        @if ($routeosHasLocation)
            <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
                @if ($routeosMapsUrl)
                    <a href="{{ $routeosMapsUrl }}" target="_blank" rel="noopener"
                        class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 cursor-pointer hover:bg-slate-50">
                        Google Maps
                    </a>
                @endif
                @if ($routeosWazeUrl)
                    <a href="{{ $routeosWazeUrl }}" target="_blank" rel="noopener"
                        class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 cursor-pointer hover:bg-slate-50">
                        Waze
                    </a>
                @endif
            </div>
        @endif

    </div>

    @if ($routeosHasLocation && $customer->isLocationValidated())
        <p class="mt-3 text-xs text-slate-500">
            Ubicación validada
            {{ $customer->location_validated_at?->format('d/m/Y H:i') }}
            @if ($customer->locationValidatedBy)
                por {{ $customer->locationValidatedBy->name }}
            @endif
        </p>
    @endif

</x-card>

<x-card class="mt-6">

    <x-slot:header>
        <h3 class="text-lg font-semibold">
            Configuración
        </h3>
    </x-slot:header>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <x-checkbox
            name="accepts_email_invoice"
            label="Recibir Facturas por Correo"
            :checked="old('accepts_email_invoice', $customer->accepts_email_invoice ?? true)" />

        <x-checkbox
            name="is_active"
            label="Cliente Activo"
            :checked="old('is_active', $customer->is_active ?? true)" />

    </div>

</x-card>

<x-card class="mt-6">

    <x-slot:header>
        <h3 class="text-lg font-semibold">
            Acceso al Portal de Cliente
        </h3>
    </x-slot:header>

    <div>
        <label class="flex items-center gap-2">
            <input type="checkbox" name="create_portal_access" value="1" {{ old('create_portal_access') ? 'checked' : '' }} class="rounded border-slate-300">
            <span class="text-sm font-semibold">Crear acceso al Portal de Cliente</span>
        </label>
        <p class="mt-1 text-xs text-slate-500">Se usará teléfono normalizado o email como usuario. Si no hay teléfono ni email válido, no se creará acceso.</p>
        @error('create_portal_access')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

</x-card>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form');

    if (!form) return;

    form.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target.tagName !== 'TEXTAREA') {
            event.preventDefault();
        }
    });
});
</script>

<script>
// R01 MVS RouteOS — Captura de ubicación del cliente (no silenciosa: requiere clic).
(function () {
    var captureButton = document.getElementById('geo-capture');
    var statusElement = document.getElementById('geo-status');
    var labelElement = document.getElementById('geo-capture-label');
    var latitudeInput = document.getElementById('geo-latitude');
    var longitudeInput = document.getElementById('geo-longitude');

    if (!captureButton || !latitudeInput || !longitudeInput) return;

    function showStatus(message, kind) {
        if (!statusElement) return;
        statusElement.textContent = message;
        statusElement.classList.remove('hidden', 'bg-red-50', 'text-red-700', 'bg-green-50', 'text-green-800', 'bg-slate-100', 'text-slate-600');
        statusElement.classList.add(kind === 'error' ? 'bg-red-50' : (kind === 'success' ? 'bg-green-50' : 'bg-slate-100'),
            kind === 'error' ? 'text-red-700' : (kind === 'success' ? 'text-green-800' : 'text-slate-600'));
    }

    function setBusy(busy) {
        captureButton.disabled = busy;
        if (labelElement) {
            labelElement.textContent = busy ? 'Solicitando ubicación…' : 'Usar mi ubicación actual';
        }
    }

    function roundCoordinate(value) {
        return Math.round(value * 10000000) / 10000000;
    }

    function onSuccess(position) {
        setBusy(false);
        latitudeInput.value = roundCoordinate(position.coords.latitude);
        longitudeInput.value = roundCoordinate(position.coords.longitude);

        var accuracy = position.coords.accuracy
            ? ' (precisión aproximada ±' + Math.round(position.coords.accuracy) + ' m)'
            : '';
        showStatus('Ubicación obtenida. Recuerde guardar los cambios.' + accuracy, 'success');
    }

    function onError(error) {
        setBusy(false);
        if (error && error.code === error.PERMISSION_DENIED) {
            showStatus('Permiso de ubicación rechazado. Puede escribir las coordenadas manualmente o reintentar.', 'error');
        } else if (error && error.code === error.POSITION_UNAVAILABLE) {
            showStatus('Ubicación no disponible en este momento. Reintente o escríbala manualmente.', 'error');
        } else if (error && error.code === error.TIMEOUT) {
            showStatus('Tiempo agotado esperando la ubicación. Reintente.', 'error');
        } else {
            showStatus('No fue posible obtener la ubicación. Reintente o escríbala manualmente.', 'error');
        }
    }

    captureButton.addEventListener('click', function () {
        if (!navigator.geolocation) {
            showStatus('Este dispositivo no soporta geolocalización. Escriba las coordenadas manualmente.', 'error');
            return;
        }

        setBusy(true);
        showStatus('Solicitando ubicación al dispositivo…', 'info');

        navigator.geolocation.getCurrentPosition(onSuccess, onError, {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0,
        });
    });
})();
</script>
