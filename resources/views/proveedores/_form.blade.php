@csrf

@if ($errors->any())
    <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4">
        <ul class="list-disc space-y-1 pl-5 text-sm text-red-700">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="grid grid-cols-1 gap-6 md:grid-cols-2">

    <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">
            Tipo de proveedor *
        </label>

        <select
            name="supplier_type"
            class="w-full rounded-xl border border-slate-300 px-4 py-3"
            required>

            <option value="company"
                @selected(old('supplier_type', $supplier->supplier_type ?: 'company') === 'company')>
                Empresa
            </option>

            <option value="individual"
                @selected(old('supplier_type', $supplier->supplier_type) === 'individual')>
                Persona
            </option>

        </select>
    </div>

    <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">
            Tipo de identificación
        </label>

        <select
            name="identification_type"
            class="w-full rounded-xl border border-slate-300 px-4 py-3">

            <option value="">Seleccione</option>
            <option value="01" @selected(old('identification_type', $supplier->identification_type) === '01')>Cédula Física</option>
            <option value="02" @selected(old('identification_type', $supplier->identification_type) === '02')>Cédula Jurídica</option>
            <option value="03" @selected(old('identification_type', $supplier->identification_type) === '03')>DIMEX</option>
            <option value="04" @selected(old('identification_type', $supplier->identification_type) === '04')>NITE</option>
            <option value="05" @selected(old('identification_type', $supplier->identification_type) === '05')>Extranjero no domiciliado</option>

        </select>
    </div>

    <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">
            Identificación
        </label>

        <input
            type="text"
            name="identification"
            value="{{ old('identification', $supplier->identification) }}"
            class="w-full rounded-xl border border-slate-300 px-4 py-3">
    </div>

    <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">
            Nombre / Razón Social *
        </label>

        <input
            type="text"
            name="name"
            value="{{ old('name', $supplier->name) }}"
            class="w-full rounded-xl border border-slate-300 px-4 py-3"
            required>
    </div>

    <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">
            Nombre comercial
        </label>

        <input
            type="text"
            name="commercial_name"
            value="{{ old('commercial_name', $supplier->commercial_name) }}"
            class="w-full rounded-xl border border-slate-300 px-4 py-3">
    </div>

    <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">
            Persona de contacto
        </label>

        <input
            type="text"
            name="contact_name"
            value="{{ old('contact_name', $supplier->contact_name) }}"
            class="w-full rounded-xl border border-slate-300 px-4 py-3">
    </div>

    <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">
            Teléfono
        </label>

        <input
            type="text"
            name="phone"
            value="{{ old('phone', $supplier->phone) }}"
            class="w-full rounded-xl border border-slate-300 px-4 py-3">
    </div>

    <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">
            Celular
        </label>

        <input
            type="text"
            name="mobile"
            value="{{ old('mobile', $supplier->mobile) }}"
            class="w-full rounded-xl border border-slate-300 px-4 py-3">
    </div>

    <div class="md:col-span-2">
        <label class="mb-2 block text-sm font-medium text-slate-700">
            Correo electrónico
        </label>

        <input
            type="email"
            name="email"
            value="{{ old('email', $supplier->email) }}"
            class="w-full rounded-xl border border-slate-300 px-4 py-3">
    </div>

</div>

<hr class="my-8 border-slate-200">

<h4 class="mb-5 text-base font-semibold text-slate-800">
    Ubicación
</h4>

<div class="grid grid-cols-1 gap-6 md:grid-cols-2">

    <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">País</label>

        <select
    id="country_id"
    name="country_id"
    class="w-full rounded-xl border border-slate-300 px-4 py-3">
            <option value="">Seleccione</option>

            @foreach($countries as $country)
                <option
                    value="{{ $country->id }}"
                    @selected(old('country_id', $supplier->country_id) == $country->id)>
                    {{ $country->name }}
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Provincia</label>

        <select
    id="province_id"
    name="province_id"
    class="w-full rounded-xl border border-slate-300 px-4 py-3">
            <option value="">Seleccione</option>

            @foreach($provinces as $province)
                <option
                    value="{{ $province->id }}"
                    @selected(old('province_id', $supplier->province_id) == $province->id)>
                    {{ $province->name }}
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Cantón</label>

        <select
    id="canton_id"
    name="canton_id"
    class="w-full rounded-xl border border-slate-300 px-4 py-3">
            <option value="">Seleccione</option>

            @foreach($cantons as $canton)
                <option
                    value="{{ $canton->id }}"
                    @selected(old('canton_id', $supplier->canton_id) == $canton->id)>
                    {{ $canton->name }}
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Distrito</label>

        <select
    id="district_id"
    name="district_id"
    class="w-full rounded-xl border border-slate-300 px-4 py-3">
            <option value="">Seleccione</option>

            @foreach($districts as $district)
                <option
                    value="{{ $district->id }}"
                    @selected(old('district_id', $supplier->district_id) == $district->id)>
                    {{ $district->name }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="md:col-span-2">
        <label class="mb-2 block text-sm font-medium text-slate-700">
            Dirección
        </label>

        <textarea
            name="address"
            rows="3"
            class="w-full rounded-xl border border-slate-300 px-4 py-3">{{ old('address', $supplier->address) }}</textarea>
    </div>

</div>

<hr class="my-8 border-slate-200">

<h4 class="mb-5 text-base font-semibold text-slate-800">
    Condiciones comerciales
</h4>

<div class="grid grid-cols-1 gap-6 md:grid-cols-2">

    <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">
            Días de crédito
        </label>

        <input
            type="number"
            min="0"
            name="credit_days"
            value="{{ old('credit_days', $supplier->credit_days ?? 0) }}"
            class="w-full rounded-xl border border-slate-300 px-4 py-3">
    </div>

    <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">
            Límite de crédito
        </label>

        <input
            type="number"
            min="0"
            step="1"
            name="credit_limit"
            value="{{ old('credit_limit', $supplier->credit_limit ?? 0) }}"
            class="w-full rounded-xl border border-slate-300 px-4 py-3">
    </div>

    <div class="md:col-span-2">
        <label class="mb-2 block text-sm font-medium text-slate-700">
            Notas
        </label>

        <textarea
            name="notes"
            rows="4"
            class="w-full rounded-xl border border-slate-300 px-4 py-3">{{ old('notes', $supplier->notes) }}</textarea>
    </div>

    <div class="md:col-span-2">
        <label class="flex items-center gap-3">

            <input
                type="checkbox"
                name="is_active"
                value="1"
                @checked(old('is_active', $supplier->exists ? $supplier->is_active : true))
                class="rounded border-slate-300">

            <span class="text-sm font-medium text-slate-700">
                Proveedor activo
            </span>

        </label>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const country = document.getElementById('country_id');
    const province = document.getElementById('province_id');
    const canton = document.getElementById('canton_id');
    const district = document.getElementById('district_id');

    function limpiar(select) {
        select.innerHTML = '<option value="">Seleccione</option>';
    }

    country.addEventListener('change', function () {

        limpiar(province);
        limpiar(canton);
        limpiar(district);

        if (!this.value) return;

        fetch(`/ubicaciones/provincias/${this.value}`)
            .then(response => response.json())
            .then(data => {

                data.forEach(item => {
                    province.innerHTML +=
                        `<option value="${item.id}">${item.name}</option>`;
                });

            });

    });

    province.addEventListener('change', function () {

        limpiar(canton);
        limpiar(district);

        if (!this.value) return;

        fetch(`/ubicaciones/cantones/${this.value}`)
            .then(response => response.json())
            .then(data => {

                data.forEach(item => {
                    canton.innerHTML +=
                        `<option value="${item.id}">${item.name}</option>`;
                });

            });

    });

    canton.addEventListener('change', function () {

        limpiar(district);

        if (!this.value) return;

        fetch(`/ubicaciones/distritos/${this.value}`)
            .then(response => response.json())
            .then(data => {

                data.forEach(item => {
                    district.innerHTML +=
                        `<option value="${item.id}">${item.name}</option>`;
                });

            });

    });

});
</script>
