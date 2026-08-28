@extends('layouts.app')

@section('title', 'Nueva Empresa')

@section('description', 'Registrar una nueva empresa')

@section('content')

<div class="space-y-6">

    <form
        method="POST"
        action="{{ route('empresa.store') }}"
        enctype="multipart/form-data">

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

        {{-- Información General --}}

        <x-card>

            <x-slot:header>
                <h3 class="text-lg font-semibold">
                    Información General
                </h3>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">

                <x-input
                    name="trade_name"
                    label="Nombre Comercial"
                    :value="old('trade_name')"
                    required />

                <x-input
                    name="legal_name"
                    label="Razón Social"
                    :value="old('legal_name')" />

                <x-select
                    name="identification_type"
                    label="Tipo de Identificación">

                    <option value="">Seleccione...</option>

                    <option value="01" @selected(old('identification_type') == '01')>
                        Cédula Física
                    </option>

                    <option value="02" @selected(old('identification_type') == '02')>
                        Cédula Jurídica
                    </option>

                    <option value="03" @selected(old('identification_type') == '03')>
                        DIMEX
                    </option>

                    <option value="04" @selected(old('identification_type') == '04')>
                        NITE
                    </option>

                    <option value="05" @selected(old('identification_type') == '05')>
                        Extranjero no domiciliado
                    </option>

                </x-select>

                <x-input
                    name="identification_number"
                    label="Número de Identificación"
                    :value="old('identification_number')" />

                <x-input
                    name="phone"
                    label="Teléfono"
                    :value="old('phone')" />

                <x-input
                    type="email"
                    name="email"
                    label="Correo Electrónico"
                    :value="old('email')" />

            </div>

        </x-card>

        {{-- Dirección --}}

        <x-card class="mt-6">

            <x-slot:header>
                <h3 class="text-lg font-semibold">
                    Dirección
                </h3>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">

                <x-select
                    name="country_id"
                    label="País">

                    <option value="">Seleccione...</option>

                    @foreach($countries as $country)

                        <option
                            value="{{ $country->id }}"
                            @selected(old('country_id') == $country->id)>

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
                            @selected(old('province_id') == $province->id)>

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
                            @selected(old('canton_id') == $canton->id)>

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
                            @selected(old('district_id') == $district->id)>

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
                    :value="old('address')" />

            </div>

        </x-card>

        {{-- Configuración --}}

        <x-card class="mt-6">

            <x-slot:header>
                <h3 class="text-lg font-semibold">
                    Configuración
                </h3>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">

                <x-select
    name="currency"
    label="Moneda Principal">

    <option value="CRC" @selected(old('currency', 'CRC') == 'CRC')>
        CRC - Colón costarricense (₡)
    </option>

    <option value="USD" @selected(old('currency') == 'USD')>
        USD - Dólar estadounidense ($)
    </option>

</x-select>
                <x-input
                    name="timezone"
                    label="Zona Horaria"
                    :value="old('timezone', 'America/Costa_Rica')" />

                <div class="md:col-span-2">

                    <label class="mb-2 block text-sm font-medium text-slate-700">
                        Logo de la Empresa
                    </label>

                    <input
                        type="file"
                        name="logo"
                        accept="image/*"
                        class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm">

                    <p class="mt-1 text-xs text-slate-500">
                        Imagen opcional. Máximo 2 MB.
                    </p>

                </div>

            </div>

        </x-card>

        {{-- Botones --}}

        <section class="mt-6 border-t border-slate-200 pt-6">
            <h2 class="text-lg font-semibold text-slate-800">Primera sucursal</h2>
            <p class="mt-1 text-sm text-slate-500">Esta sucursal quedará activa al finalizar el registro.</p>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="branch_name" class="mb-2 block text-sm font-semibold text-slate-700">Nombre *</label>
                    <input id="branch_name" name="branch_name" required value="{{ old('branch_name', 'Principal') }}" class="min-h-11 w-full rounded-xl border border-slate-300 px-4 py-3">
                    @error('branch_name')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="branch_code" class="mb-2 block text-sm font-semibold text-slate-700">Código *</label>
                    <input id="branch_code" name="branch_code" required value="{{ old('branch_code', 'PRINCIPAL') }}" class="min-h-11 w-full rounded-xl border border-slate-300 px-4 py-3">
                    @error('branch_code')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <div class="mt-6 flex justify-end gap-3">

            <a
                href="{{ route('empresa.index') }}"
                class="rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">
                Cancelar
            </a>

            <x-button type="submit">
                Guardar Empresa
            </x-button>

        </div>

    </form>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const country = document.querySelector('[name="country_id"]');
    const province = document.querySelector('[name="province_id"]');
    const canton = document.querySelector('[name="canton_id"]');
    const district = document.querySelector('[name="district_id"]');

    if (!country || !province || !canton || !district) {
        return;
    }

    function resetSelect(select, text = 'Seleccione...') {
        select.innerHTML = `<option value="">${text}</option>`;
    }

    function loadOptions(url, select, selectedValue = null) {

        fetch(url)
            .then(response => response.json())
            .then(items => {

                resetSelect(select);

                items.forEach(item => {

                    const option = document.createElement('option');

                    option.value = item.id;
                    option.textContent = item.name;

                    if (selectedValue && String(item.id) === String(selectedValue)) {
                        option.selected = true;
                    }

                    select.appendChild(option);
                });

            });
    }

    country.addEventListener('change', function () {

        resetSelect(province);
        resetSelect(canton);
        resetSelect(district);

        if (!this.value) return;

        loadOptions(
            `/ubicaciones/provincias/${this.value}`,
            province
        );
    });

    province.addEventListener('change', function () {

        resetSelect(canton);
        resetSelect(district);

        if (!this.value) return;

        loadOptions(
            `/ubicaciones/cantones/${this.value}`,
            canton
        );
    });

    canton.addEventListener('change', function () {

        resetSelect(district);

        if (!this.value) return;

        loadOptions(
            `/ubicaciones/distritos/${this.value}`,
            district
        );
    });

});
</script>

@endsection