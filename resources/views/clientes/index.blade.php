@extends('layouts.app')

@section('title', 'Clientes')

@section('description', 'Administración de clientes')

@section('content')

<div class="space-y-6">

    <div class="flex justify-end">

        <a href="{{ route('clientes.create') }}">

            <x-button>
                + Nuevo Cliente
            </x-button>

        </a>

    </div>

    {{-- Estadísticas --}}

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <x-card>

            <p class="text-sm text-slate-500">
                Total Clientes
            </p>

            <h2 class="mt-2 text-4xl font-bold text-slate-800">
                {{ $stats['total'] }}
            </h2>

        </x-card>

        <x-card>

            <p class="text-sm text-slate-500">
                Clientes Activos
            </p>

            <h2 class="mt-2 text-4xl font-bold text-green-600">
                {{ $stats['active'] }}
            </h2>

        </x-card>

        <x-card>

            <p class="text-sm text-slate-500">
                Empresas
            </p>

            <h2 class="mt-2 text-4xl font-bold text-blue-600">
                {{ $stats['companies'] }}
            </h2>

        </x-card>

        <x-card>

            <p class="text-sm text-slate-500">
                Personas
            </p>

            <h2 class="mt-2 text-4xl font-bold text-amber-500">
                {{ $stats['individuals'] }}
            </h2>

        </x-card>

    </div>

   {{-- Buscador --}}

<div class="relative">

    <input
        type="text"
        id="customer-search"
        value="{{ $search }}"
        placeholder="Buscar por nombre, cédula, teléfono, celular o correo..."
        autocomplete="off"
        class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-100"
    >

    <div
        id="customer-suggestions"
        class="absolute left-0 right-0 top-full z-50 mt-1 hidden overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
    </div>

</div>

<script>
const searchInput = document.getElementById('customer-search');
const suggestionsBox = document.getElementById('customer-suggestions');

let searchTimer;

searchInput.addEventListener('input', function () {

    clearTimeout(searchTimer);

    const search = this.value.trim();

    if (search.length < 2) {
        suggestionsBox.innerHTML = '';
        suggestionsBox.classList.add('hidden');
        return;
    }

    searchTimer = setTimeout(function () {

        fetch('/clientes-buscar?search=' + encodeURIComponent(search))
            .then(response => response.json())
            .then(customers => {

                suggestionsBox.innerHTML = '';

                if (customers.length === 0) {

                    suggestionsBox.innerHTML = `
                        <div class="px-4 py-3 text-sm text-slate-500">
                            No se encontraron clientes
                        </div>
                    `;

                    suggestionsBox.classList.remove('hidden');

                    return;
                }

                customers.forEach(customer => {

                    const link = document.createElement('a');

                    link.href = '/clientes/' + customer.id;

                    link.className =
                        'block border-b border-slate-100 px-4 py-3 hover:bg-amber-50';

                    link.innerHTML = `
                        <div class="font-semibold text-slate-800">
                            ${customer.name}
                        </div>

                        <div class="mt-1 text-xs text-slate-500">
                            ${customer.identification ?? 'Sin identificación'}
                            ·
                            ${customer.mobile ?? customer.phone ?? 'Sin teléfono'}
                        </div>
                    `;

                    suggestionsBox.appendChild(link);

                });

                suggestionsBox.classList.remove('hidden');

            });

    }, 250);

});
</script>

{{-- Filtros --}}

<form method="GET" class="grid grid-cols-1 gap-3 md:grid-cols-3">

    <input type="hidden" name="search" value="{{ $search }}">

    <select
        name="status"
        onchange="this.form.submit()"
        class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-amber-400">

        <option value="">Todos los estados</option>

        <option value="1" @selected($status === '1')>
            Activos
        </option>

        <option value="0" @selected($status === '0')>
            Inactivos
        </option>

    </select>

    <select
        name="type"
        onchange="this.form.submit()"
        class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-amber-400">

        <option value="">Todos los tipos</option>

        <option value="individual" @selected($type === 'individual')>
            Personas
        </option>

        <option value="company" @selected($type === 'company')>
            Empresas
        </option>

    </select>

    <a
        href="{{ route('clientes.index') }}"
        class="flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-600 hover:bg-slate-50">

        Limpiar filtros

    </a>

</form>

    {{-- Tabla --}}

    <x-table>

        <x-table-header>

            <x-th>Identificación</x-th>
            <x-th>Nombre</x-th>
            <x-th>Teléfono</x-th>
            <x-th>Correo</x-th>
            <x-th>Puntos</x-th>
            <x-th>Estado</x-th>
            <x-th>Acciones</x-th>

        </x-table-header>

        <x-table-body>

            @forelse($customers as $customer)

                <tr class="border-t hover:bg-slate-50">

                    <td class="px-4 py-3">
                        {{ $customer->identification ?: '-' }}
                    </td>

                    <td class="px-4 py-3 font-medium">
                        {{ $customer->name }}
                    </td>

                    <td class="px-4 py-3">
                        {{ $customer->mobile ?: ($customer->phone ?: '-') }}
                    </td>

                    <td class="px-4 py-3">
                        {{ $customer->email ?: '-' }}
                    </td>

                    <td class="px-4 py-3 text-center">
                        {{ number_format($customer->points) }}
                    </td>

                    <td class="px-4 py-3 text-center">

                        @if($customer->is_active)

                            <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-700">
                                Activo
                            </span>

                        @else

                            <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-medium text-red-700">
                                Inactivo
                            </span>

                        @endif

                    </td>

                    <td class="px-4 py-3">

                        <div class="flex items-center justify-center gap-2 whitespace-nowrap">

    <a
        href="{{ route('clientes.show', $customer) }}"
        class="rounded-lg bg-slate-600 px-3 py-1.5 text-sm text-white hover:bg-slate-700">
        Ver
    </a>

    <a
        href="{{ route('clientes.edit', $customer) }}"
        class="rounded-lg bg-amber-500 px-3 py-1.5 text-sm text-white hover:bg-amber-600">
        Editar
    </a>

    <form
        action="{{ route('clientes.toggle-status', ['cliente' => $customer->id]) }}"
        method="POST">

        @csrf
        @method('PATCH')

        <button
            type="submit"
            class="rounded-lg px-3 py-1.5 text-sm text-white
            {{ $customer->is_active
                ? 'bg-slate-500 hover:bg-slate-600'
                : 'bg-green-600 hover:bg-green-700' }}">

            {{ $customer->is_active ? 'Inactivar' : 'Activar' }}

        </button>

    </form>

    <form
        action="{{ route('clientes.destroy', ['cliente' => $customer->id]) }}"
        method="POST"
        onsubmit="return confirm('¿Está seguro de eliminar este cliente?');">

        @csrf
        @method('DELETE')

        <button
            type="submit"
            class="rounded-lg bg-red-600 px-3 py-1.5 text-sm text-white hover:bg-red-700">
            Eliminar
        </button>

    </form>

</div>
                    </td>

                </tr>

            @empty

                <tr>

                    <td colspan="7" class="py-10 text-center text-slate-500">

                        No hay clientes registrados.

                    </td>

                </tr>

            @endforelse

        </x-table-body>

    </x-table>

    {{ $customers->links() }}

</div>

@endsection