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

        <x-input
            name="phone"
            label="Teléfono"
            :value="old('phone', $customer->phone ?? '')" />

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

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <x-input
    type="number"
    step="1"
    name="credit_limit"
    label="Límite de Crédito"
    :value="old('credit_limit', $customer->credit_limit ?? 0)"/>

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

        <x-input
            type="number"
            name="credit_days"
            label="Días de Crédito"
            :value="old('credit_days', $customer->credit_days ?? 0)" />

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
