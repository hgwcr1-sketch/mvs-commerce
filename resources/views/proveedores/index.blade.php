@extends('layouts.app')

@section('title', 'Proveedores')

@section('description', 'Administración de proveedores')

@section('content')

<div class="space-y-6">

    {{-- Botones superiores --}}
    <div class="flex items-center justify-between">

        <a
            href="{{ url()->previous() }}"
            class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
            ← Volver
        </a>

        @can('proveedores.crear')

    <a href="{{ route('proveedores.create') }}">
        <x-button>
            + Nuevo Proveedor
        </x-button>
    </a>

@endcan

    </div>

    {{-- Estadísticas --}}
    <div class="grid grid-cols-1 gap-6 md:grid-cols-4">

        <x-card>
            <p class="text-sm text-slate-500">
                Total Proveedores
            </p>

            <h2 class="mt-2 text-4xl font-bold text-slate-800">
                {{ $stats['total'] }}
            </h2>
        </x-card>

        <x-card>
            <p class="text-sm text-slate-500">
                Proveedores Activos
            </p>

            <h2 class="mt-2 text-4xl font-bold text-green-600">
                {{ $stats['active'] }}
            </h2>
        </x-card>

        <x-card>
            <p class="text-sm text-slate-500">
                Empresas
            </p>

            <h2 class="mt-2 text-4xl font-bold text-slate-700">
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

    {{-- Buscador rápido --}}
    <div class="relative">

        <input
            type="text"
            id="supplier-search"
            value="{{ $search }}"
            placeholder="Buscar por nombre, identificación, contacto, teléfono o correo..."
            autocomplete="off"
            class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-100"
        >

        <div
            id="supplier-suggestions"
            class="absolute left-0 right-0 top-full z-50 mt-1 hidden overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
        </div>

    </div>

    {{-- Filtros --}}
    <form
        method="GET"
        action="{{ route('proveedores.index') }}"
        class="grid grid-cols-1 gap-3 md:grid-cols-3">

        <input
            type="hidden"
            name="search"
            value="{{ $search }}">

        <select
            name="status"
            onchange="this.form.submit()"
            class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-amber-400">

            <option value="">
                Todos los estados
            </option>

            <option
                value="1"
                @selected($status === '1')>
                Activos
            </option>

            <option
                value="0"
                @selected($status === '0')>
                Inactivos
            </option>

        </select>

        <select
            name="type"
            onchange="this.form.submit()"
            class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-amber-400">

            <option value="">
                Todos los tipos
            </option>

            <option
                value="individual"
                @selected($type === 'individual')>
                Personas
            </option>

            <option
                value="company"
                @selected($type === 'company')>
                Empresas
            </option>

        </select>

        <a
            href="{{ route('proveedores.index') }}"
            class="flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-600 hover:bg-slate-50">
            Limpiar filtros
        </a>

    </form>

    {{-- Tabla --}}
    <x-table>

        <x-table-header>

            <x-th>Identificación</x-th>
            <x-th>Proveedor</x-th>
            <x-th>Contacto</x-th>
            <x-th>Teléfono</x-th>
            <x-th>Correo</x-th>
            <x-th>Estado</x-th>
            <x-th>Acciones</x-th>

        </x-table-header>

        <x-table-body>

            @forelse($suppliers as $supplier)

                <tr class="border-t hover:bg-slate-50">

                    <td class="px-4 py-3">
                        {{ $supplier->identification ?: '-' }}
                    </td>

                    <td class="px-4 py-3">

                        <div class="font-medium text-slate-800">
                            {{ $supplier->name }}
                        </div>

                        @if($supplier->commercial_name)

                            <div class="text-xs text-slate-500">
                                {{ $supplier->commercial_name }}
                            </div>

                        @endif

                    </td>

                    <td class="px-4 py-3">
                        {{ $supplier->contact_name ?: '-' }}
                    </td>

                    <td class="px-4 py-3">
                        {{ $supplier->mobile ?: ($supplier->phone ?: '-') }}
                    </td>

                    <td class="px-4 py-3">
                        {{ $supplier->email ?: '-' }}
                    </td>

                    <td class="px-4 py-3 text-center">

                        @if($supplier->is_active)

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

    @can('proveedores.ver')
        <a
            href="{{ route('proveedores.show', $supplier) }}"
            class="rounded-lg bg-slate-600 px-3 py-1.5 text-sm text-white hover:bg-slate-700">
            Ver
        </a>
    @endcan

    @can('proveedores.editar')

        <a
            href="{{ route('proveedores.edit', $supplier) }}"
            class="rounded-lg bg-amber-500 px-3 py-1.5 text-sm text-white hover:bg-amber-600">
            Editar
        </a>

        <form
    action="{{ route('proveedores.toggle-status', $supplier) }}"
    method="POST"
    class="inline-block">

    @csrf
    @method('PATCH')

   <button
    type="submit"
    class="min-w-[80px] rounded-lg bg-slate-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">

    {{ $supplier->is_active ? 'Inactivar' : 'Activar' }}

</button>

</button>

</form>

    @endcan

    @can('proveedores.eliminar')

        <form
            action="{{ route('proveedores.destroy', $supplier) }}"
            method="POST"
            onsubmit="return confirm('¿Está seguro de eliminar este proveedor?');">

            @csrf
            @method('DELETE')

            <button
                type="submit"
                class="rounded-lg bg-red-600 px-3 py-1.5 text-sm text-white hover:bg-red-700">
                Eliminar
            </button>

        </form>

    @endcan

</div>
                    </td>

                </tr>

            @empty

                <tr>

                    <td
                        colspan="7"
                        class="py-10 text-center text-slate-500">

                        No hay proveedores registrados.

                    </td>

                </tr>

            @endforelse

        </x-table-body>

    </x-table>

    {{ $suppliers->links() }}

</div>

{{-- Buscador automático --}}
<script>
document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('supplier-search');
    const suggestionsBox = document.getElementById('supplier-suggestions');

    if (!searchInput || !suggestionsBox) {
        return;
    }

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

            fetch('{{ route('proveedores.search') }}?search=' + encodeURIComponent(search), {
                headers: {
                    'Accept': 'application/json'
                }
            })
            .then(response => {

                if (!response.ok) {
                    throw new Error('Error al buscar proveedores');
                }

                return response.json();

            })
            .then(suppliers => {

                suggestionsBox.innerHTML = '';

                if (suppliers.length === 0) {

                    suggestionsBox.innerHTML = `
                        <div class="px-4 py-3 text-sm text-slate-500">
                            No se encontraron proveedores
                        </div>
                    `;

                    suggestionsBox.classList.remove('hidden');

                    return;
                }

                suppliers.forEach(supplier => {

                    const link = document.createElement('a');

                    link.href = '{{ url('/proveedores') }}/' + supplier.id;

                    link.className =
                        'block border-b border-slate-100 px-4 py-3 hover:bg-amber-50';

                    const identification =
                        supplier.identification ?? 'Sin identificación';

                    const phone =
                        supplier.mobile ??
                        supplier.phone ??
                        'Sin teléfono';

                    link.innerHTML = `
                        <div class="font-semibold text-slate-800">
                            ${supplier.name}
                        </div>

                        <div class="mt-1 text-xs text-slate-500">
                            ${identification} · ${phone}
                        </div>
                    `;

                    suggestionsBox.appendChild(link);

                });

                suggestionsBox.classList.remove('hidden');

            })
            .catch(error => {

                console.error(error);

                suggestionsBox.innerHTML = `
                    <div class="px-4 py-3 text-sm text-red-600">
                        No se pudo realizar la búsqueda
                    </div>
                `;

                suggestionsBox.classList.remove('hidden');

            });

        }, 250);

    });

    document.addEventListener('click', function (event) {

        if (
            !searchInput.contains(event.target) &&
            !suggestionsBox.contains(event.target)
        ) {
            suggestionsBox.classList.add('hidden');
        }

    });

});
</script>

@endsection