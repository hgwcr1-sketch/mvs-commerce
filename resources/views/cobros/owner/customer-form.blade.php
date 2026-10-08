@extends('cobros.layout')

@section('title', ($customer ? 'Editar cliente' : 'Nuevo cliente').' — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <a href="{{ $customer ? route('cobros.owner.customers.show', $customer) : route('cobros.owner.customers') }}"
       class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← {{ $customer ? 'Detalle del cliente' : 'Clientes' }}</a>

    <div>
        <h1 class="text-2xl font-bold sm:text-3xl">{{ $customer ? 'Editar cliente' : 'Nuevo cliente' }}</h1>
        <p class="mt-1 text-sm text-slate-500">Ficha de contacto y datos básicos. El crédito y el nivel de precio se administran en MVS Commerce.</p>
    </div>

    <form method="POST"
          action="{{ $customer ? route('cobros.owner.customers.update', $customer) : route('cobros.owner.customers.store') }}"
          class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        @csrf
        @if($customer)
            @method('PUT')
        @endif

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

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="customer_type" class="text-sm font-semibold">Tipo de cliente</label>
                <select id="customer_type" name="customer_type" class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 bg-white px-3 text-base">
                    <option value="individual" @selected(old('customer_type', $customer?->customer_type ?? 'individual') === 'individual')>Persona física</option>
                    <option value="company" @selected(old('customer_type', $customer?->customer_type ?? '') === 'company')>Empresa</option>
                </select>
                @error('customer_type')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="identification_type" class="text-sm font-semibold">Tipo de identificación</label>
                <select id="identification_type" name="identification_type" class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 bg-white px-3 text-base">
                    <option value="">Seleccione...</option>
                    <option value="01" @selected(old('identification_type', $customer?->identification_type ?? '') === '01')>Cédula física</option>
                    <option value="02" @selected(old('identification_type', $customer?->identification_type ?? '') === '02')>Cédula jurídica</option>
                    <option value="03" @selected(old('identification_type', $customer?->identification_type ?? '') === '03')>DIMEX</option>
                    <option value="04" @selected(old('identification_type', $customer?->identification_type ?? '') === '04')>NITE</option>
                    <option value="05" @selected(old('identification_type', $customer?->identification_type ?? '') === '05')>Extranjería</option>
                </select>
                @error('identification_type')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="identification" class="text-sm font-semibold">Identificación</label>
                <input id="identification" name="identification" type="text" maxlength="50" inputmode="text"
                       value="{{ old('identification', $customer?->identification ?? '') }}"
                       class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Opcional">
                @error('identification')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="name" class="text-sm font-semibold">Nombre</label>
                <input id="name" name="name" type="text" maxlength="150" required
                       value="{{ old('name', $customer?->name ?? '') }}"
                       class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Nombre completo o razón social">
                @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="commercial_name" class="text-sm font-semibold">Nombre comercial</label>
                <input id="commercial_name" name="commercial_name" type="text" maxlength="150"
                       value="{{ old('commercial_name', $customer?->commercial_name ?? '') }}"
                       class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Opcional">
                @error('commercial_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="phone" class="text-sm font-semibold">Teléfono</label>
                <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="15"
                       value="{{ old('phone', $customer?->phone ?? '') }}"
                       class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Ej. 88888888">
                @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="mobile" class="text-sm font-semibold">Celular</label>
                <input id="mobile" name="mobile" type="tel" inputmode="tel" maxlength="30"
                       value="{{ old('mobile', $customer?->mobile ?? '') }}"
                       class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Opcional">
                @error('mobile')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="email" class="text-sm font-semibold">Correo electrónico</label>
                <input id="email" name="email" type="email" inputmode="email" maxlength="150"
                       value="{{ old('email', $customer?->email ?? '') }}"
                       class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Opcional">
                @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="mt-4">
            <label for="address" class="text-sm font-semibold">Dirección</label>
            <textarea id="address" name="address" rows="2" maxlength="500"
                      class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2 text-base"
                      placeholder="Provincia, cantón, distrito y señas">{{ old('address', $customer?->address ?? '') }}</textarea>
            @error('address')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="mt-4">
            <label for="notes" class="text-sm font-semibold">Notas</label>
            <textarea id="notes" name="notes" rows="3"
                      class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2 text-base"
                      placeholder="Referencias, horarios, observaciones">{{ old('notes', $customer?->notes ?? '') }}</textarea>
            @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
            <a href="{{ $customer ? route('cobros.owner.customers.show', $customer) : route('cobros.owner.customers') }}"
               class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 px-6 text-sm font-semibold hover:bg-slate-50">Cancelar</a>
            <button type="submit" class="min-h-[44px] rounded-xl bg-primary px-6 text-sm font-bold text-white hover:bg-primary-hover">
                {{ $customer ? 'Guardar cambios' : 'Guardar cliente' }}
            </button>
        </div>
    </form>
</div>
@endsection
