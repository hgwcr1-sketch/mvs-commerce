@extends('layouts.app')

@section('title', 'Detalle del Cliente')

@section('description', 'Información completa del cliente.')

@section('content')

<div class="space-y-6">

    <x-card>

        <x-slot:header>

            <div class="flex items-center justify-between">

                <h3 class="text-lg font-semibold">
                    Información del Cliente
                </h3>

                <a
                    href="{{ route('clientes.edit', ['cliente' => $customer->id]) }}"
                    class="rounded-lg bg-amber-500 px-4 py-2 text-white hover:bg-amber-600">

                    Editar

                </a>

            </div>

        </x-slot:header>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

            <div>
                <label class="text-sm text-slate-500">Nombre</label>
                <p class="font-semibold">{{ $customer->name }}</p>
            </div>

            <div>
    <label class="text-sm text-slate-500">Nombre Comercial</label>
    <p>{{ $customer->commercial_name ?: '-' }}</p>
</div>

            <div>
                <label class="text-sm text-slate-500">Identificación</label>
                <p>{{ $customer->identification ?: '-' }}</p>
            </div>

            <div>
    <label class="text-sm text-slate-500">Tipo de Identificación</label>

    <p>
        @switch($customer->identification_type)
            @case('01')
                Cédula Física
                @break

            @case('02')
                Cédula Jurídica
                @break

            @case('03')
                DIMEX
                @break

            @case('04')
                NITE
                @break

            @case('05')
                Extranjero no domiciliado
                @break

            @default
                -
        @endswitch
    </p>
</div>

<div>
    <label class="text-sm text-slate-500">Nombre para Facturación</label>
    <p>{{ $customer->taxpayer_name ?: '-' }}</p>
</div>

<div>
    <label class="text-sm text-slate-500">Recibir Facturas por Correo</label>

    <p>
        {{ $customer->accepts_email_invoice ? 'Sí' : 'No' }}
    </p>
</div>
            <div>
                <label class="text-sm text-slate-500">Tipo</label>
                <p>{{ $customer->customer_type == 'company' ? 'Empresa' : 'Persona Física' }}</p>
            </div>

            <div>
                <label class="text-sm text-slate-500">Teléfono</label>
                <p>{{ $customer->phone ?: '-' }}</p>
            </div>

            <div>
                <label class="text-sm text-slate-500">Celular</label>
                <p>{{ $customer->mobile ?: '-' }}</p>
            </div>

            <div>
                <label class="text-sm text-slate-500">Correo</label>
                <p>{{ $customer->email ?: '-' }}</p>
            </div>

            <div>
                <label class="text-sm text-slate-500">Provincia</label>
                <p>{{ $customer->province?->name ?: '-' }}</p>
            </div>

            <div>
                <label class="text-sm text-slate-500">Cantón</label>
                <p>{{ $customer->canton?->name ?: '-' }}</p>
            </div>

            <div>
                <label class="text-sm text-slate-500">Distrito</label>
                <p>{{ $customer->district?->name ?: '-' }}</p>
            </div>

            <div class="md:col-span-2 lg:col-span-3">
                <label class="text-sm text-slate-500">Dirección</label>
                <p>{{ $customer->address ?: '-' }}</p>
            </div>

            <div class="md:col-span-2 lg:col-span-3">
    <label class="text-sm text-slate-500">Observaciones</label>
    <p class="whitespace-pre-line">{{ $customer->notes ?: '-' }}</p>
</div>

            <div>
                <label class="text-sm text-slate-500">Límite de Crédito</label>
                <p>₡ {{ number_format($customer->credit_limit,2) }}</p>
            </div>

            <div>
                <label class="text-sm text-slate-500">Días de Crédito</label>
                <p>{{ $customer->credit_days }}</p>
            </div>

            <div>
                <label class="text-sm text-slate-500">Puntos</label>
                <p>{{ $customer->points }}</p>
            </div>

            <div>
    <label class="text-sm text-slate-500">Fecha de Nacimiento</label>

    <p>
        {{ $customer->birth_date
            ? $customer->birth_date->format('d/m/Y')
            : '-' }}
    </p>
</div>

            <div>
                <label class="text-sm text-slate-500">Estado</label>

                @if($customer->is_active)

                    <span class="rounded-full bg-green-100 px-3 py-1 text-xs text-green-700">
                        Activo
                    </span>

                @else

                    <span class="rounded-full bg-red-100 px-3 py-1 text-xs text-red-700">
                        Inactivo
                    </span>

                @endif

            </div>

        </div>

        <x-slot:footer>

            <div class="flex justify-end">

                <a
                    href="{{ route('clientes.index') }}"
                    class="rounded-lg border border-slate-300 px-5 py-2 hover:bg-slate-100">

                    Volver

                </a>

            </div>

        </x-slot:footer>

    </x-card>

</div>

<x-card>

    <x-slot:header>
        <h3 class="text-lg font-semibold">
            Contactos
        </h3>
    </x-slot:header>

    <form
    action="{{ route('clientes.contactos.store', ['cliente' => $customer->id]) }}"
    method="POST">

    @csrf

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

        <x-input
            name="name"
            label="Nombre del Contacto"
            required />

        <x-input
            name="position"
            label="Cargo" />

        <x-input
            name="phone"
            label="Teléfono" />

        <x-input
            name="mobile"
            label="Celular" />

        <x-input
            type="email"
            name="email"
            label="Correo Electrónico" />

        <x-checkbox
            name="is_primary"
            label="Contacto Principal" />

    </div>

    <div class="mt-4">

        <x-textarea
            name="notes"
            label="Observaciones"
            rows="2" />

    </div>

    <div class="mt-4 flex justify-end">

        <button
            type="submit"
            class="rounded-lg bg-amber-500 px-5 py-2 font-semibold text-white hover:bg-amber-600">
            Agregar Contacto
        </button>

    </div>

</form>
    @if($customer->contacts->count())

    <div class="mt-6 border-t border-slate-200 pt-6">

        <h4 class="mb-4 font-semibold text-slate-700">
            Contactos Registrados
        </h4>

        <div class="space-y-3">

            @foreach($customer->contacts as $contact)

    <div class="rounded-lg border border-slate-200 p-4">

        <div class="flex items-start justify-between gap-4">

            <div class="min-w-0 flex-1">

                <p class="font-semibold text-slate-800">
                    {{ $contact->name }}
                </p>

                <p class="text-sm text-slate-500">
                    {{ $contact->position ?: 'Sin cargo' }}
                </p>

                <p class="mt-2 text-sm">
                    Celular: {{ $contact->mobile ?: '-' }}
                    · Teléfono: {{ $contact->phone ?: '-' }}
                    · Correo: {{ $contact->email ?: '-' }}
                </p>

                @if($contact->is_primary)

    <span class="mt-2 inline-block rounded-full bg-green-100 px-3 py-1 text-xs text-green-700">
        Contacto Principal
    </span>

@else

    <form
        class="mt-2"
        action="{{ route('clientes.contactos.principal', [
            'cliente' => $customer->id,
            'contacto' => $contact->id
        ]) }}"
        method="POST">

        @csrf
        @method('PATCH')

        <button
            type="submit"
            class="text-sm font-medium text-amber-600 hover:text-amber-700">
            Hacer Principal
        </button>

    </form>

@endif

            </div>

            <form
                class="shrink-0"
                action="{{ route('clientes.contactos.destroy', [
                    'cliente' => $customer->id,
                    'contacto' => $contact->id
                ]) }}"
                method="POST"
                onsubmit="return confirm('¿Está seguro de eliminar este contacto?');">

                @csrf
                @method('DELETE')

                <button
                    type="submit"
                    class="rounded-lg bg-red-600 px-3 py-1.5 text-sm text-white hover:bg-red-700">
                    Eliminar
                </button>

            </form>

        </div>

    </div>

@endforeach

        </div>

    </div>

@endif

</x-card>

<x-card class="mt-6">

    <x-slot:header>
        <h3 class="text-lg font-semibold">
            Direcciones Adicionales
        </h3>
    </x-slot:header>

    <form
        action="{{ route('clientes.direcciones.store', ['cliente' => $customer->id]) }}"
        method="POST">

        @csrf

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            <x-input
                name="name"
                label="Nombre de la Dirección"
                placeholder="Ej: Oficina, Bodega, Entrega"
                required />

            <x-checkbox
                name="is_primary"
                label="Dirección Principal" />

        </div>

        <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">

    <x-select
        name="country_id"
        label="País">

        <option value="">Seleccione...</option>

        @foreach($countries as $country)
            <option value="{{ $country->id }}">
                {{ $country->name }}
            </option>
        @endforeach

    </x-select>

    <x-select
        name="province_id"
        label="Provincia">

        <option value="">Seleccione...</option>

        @foreach($provinces as $province)
            <option value="{{ $province->id }}">
                {{ $province->name }}
            </option>
        @endforeach

    </x-select>

    <x-select
        name="canton_id"
        label="Cantón">

        <option value="">Seleccione...</option>

        @foreach($cantons as $canton)
            <option value="{{ $canton->id }}">
                {{ $canton->name }}
            </option>
        @endforeach

    </x-select>

    <x-select
        name="district_id"
        label="Distrito">

        <option value="">Seleccione...</option>

        @foreach($districts as $district)
            <option value="{{ $district->id }}">
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
                required />

        </div>

        <div class="mt-6">

            <x-textarea
                name="notes"
                label="Observaciones"
                rows="2" />

        </div>

        <div class="mt-4 flex justify-end">

            <button
                type="submit"
                class="rounded-lg bg-amber-500 px-5 py-2 font-semibold text-white hover:bg-amber-600">

                Agregar Dirección

            </button>

        </div>

    </form>

    @if($customer->addresses->count())

    <div class="mt-6 border-t border-slate-200 pt-6">

        <h4 class="mb-4 font-semibold text-slate-700">
            Direcciones Registradas
        </h4>

        <div class="space-y-3">

           @foreach($customer->addresses as $address)

    <div class="rounded-lg border border-slate-200 p-4">

        <div class="flex items-start justify-between gap-4">

            <div class="min-w-0 flex-1">

                <p class="font-semibold text-slate-800">
                    {{ $address->name }}
                </p>

                <p class="mt-1 text-sm text-slate-600">
                    {{ $address->country?->name ?: '-' }}
                    · {{ $address->province?->name ?: '-' }}
                    · {{ $address->canton?->name ?: '-' }}
                    · {{ $address->district?->name ?: '-' }}
                </p>

                <p class="mt-2 text-sm text-slate-700">
                    {{ $address->address }}
                </p>

                @if($address->notes)

                    <p class="mt-2 text-sm text-slate-500">
                        {{ $address->notes }}
                    </p>

                @endif

                @if($address->is_primary)

                    <span class="mt-2 inline-block rounded-full bg-green-100 px-3 py-1 text-xs text-green-700">
                        Dirección Principal
                    </span>

                @else

                    <form
                        class="mt-2"
                        action="{{ route('clientes.direcciones.principal', [
                            'cliente' => $customer->id,
                            'direccion' => $address->id
                        ]) }}"
                        method="POST">

                        @csrf
                        @method('PATCH')

                        <button
                            type="submit"
                            class="text-sm font-medium text-amber-600 hover:text-amber-700">

                            Hacer Principal

                        </button>

                    </form>

                @endif

            </div>

            <form
                class="shrink-0"
                action="{{ route('clientes.direcciones.destroy', [
                    'cliente' => $customer->id,
                    'direccion' => $address->id
                ]) }}"
                method="POST"
                onsubmit="return confirm('¿Está seguro de eliminar esta dirección?');">

                @csrf
                @method('DELETE')

                <button
                    type="submit"
                    class="rounded-lg bg-red-600 px-3 py-1.5 text-sm text-white hover:bg-red-700">

                    Eliminar

                </button>

            </form>

        </div>

    </div>

@endforeach

        </div>

    </div>

@endif

</x-card>   

@endsection