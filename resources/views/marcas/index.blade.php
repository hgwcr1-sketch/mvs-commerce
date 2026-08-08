@extends('layouts.app')

@section('title', 'Marcas')

@section('content')

<div class="space-y-6">

    <!-- Encabezado -->
    <div class="flex items-center justify-between">

    <div>
        <h2 class="text-xl font-semibold text-slate-800">
            Listado de Marcas
        </h2>

        <p class="text-sm text-slate-500">
            Consulta, crea y administra las marcas registradas.
        </p>
    </div>

    <div class="flex items-center gap-3">

    <a
        href="{{ route('productos.index') }}"
        class="rounded-lg border border-slate-300 px-4 py-2.5 font-medium text-slate-700 hover:bg-slate-100">
        Volver
    </a>

    <a href="{{ route('marcas.create') }}"
       class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 font-medium text-white shadow hover:bg-blue-700 transition">

        <svg xmlns="http://www.w3.org/2000/svg"
             class="h-5 w-5"
             fill="none"
             viewBox="0 0 24 24"
             stroke="currentColor"
             stroke-width="2">

            <path stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M12 4v16m8-8H4"/>

        </svg>

        Nueva Marca

    </a>

    </div>
    
</div>
    <!-- Mensaje -->
    @if(session('success'))
        <div class="bg-green-100 border border-green-300 text-green-700 px-4 py-3 rounded-lg">
            {{ session('success') }}
        </div>
    @endif

    <!-- Tarjetas -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        <div class="bg-white border rounded-xl shadow-sm p-5">
            <p class="text-gray-500 text-sm">
                Total de Marcas
            </p>

            <h2 class="text-4xl font-bold text-slate-800 mt-2">
                {{ $totalMarcas }}
            </h2>
        </div>

        <div class="bg-white border rounded-xl shadow-sm p-5">
            <p class="text-gray-500 text-sm">
                Marcas Activas
            </p>

            <h2 class="text-4xl font-bold text-green-600 mt-2">
                {{ $marcasActivas }}
            </h2>
        </div>

        <div class="bg-white border rounded-xl shadow-sm p-5">
            <p class="text-gray-500 text-sm">
                Marcas Inactivas
            </p>

            <h2 class="text-4xl font-bold text-red-600 mt-2">
                {{ $marcasInactivas }}
            </h2>
        </div>

    </div>

    <!-- Buscador -->
    <div class="bg-white border rounded-xl shadow-sm p-4">

        <form method="GET" class="flex flex-col md:flex-row gap-3">

            <input
                type="text"
                name="search"
                value="{{ $search ?? '' }}"
                placeholder="Buscar marca..."
                class="border rounded-lg px-4 py-2 flex-1 focus:outline-none focus:ring-2 focus:ring-blue-500">

            <button
                type="submit"
                class="bg-slate-700 hover:bg-slate-800 text-white px-6 py-2 rounded-lg">
                Buscar
            </button>

        </form>

    </div>

    <!-- Tabla -->
    <div class="bg-white border rounded-xl shadow-sm overflow-hidden">

        <table class="min-w-full">

            <thead class="bg-slate-100">

                <tr>

                    <th class="px-4 py-3 text-left font-semibold">
                        Nombre
                    </th>

                    <th class="px-4 py-3 text-center font-semibold">
                        Estado
                    </th>

                    <th class="px-4 py-3 text-center font-semibold">
                        Acciones
                    </th>

                </tr>

            </thead>

            <tbody>

            @forelse($marcas as $marca)

                <tr class="border-t hover:bg-slate-50">

                    <td class="px-4 py-3">
                        {{ $marca->name }}
                    </td>

                    <td class="px-4 py-3 text-center">

                        @if($marca->is_active)

                            <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-sm">
                                Activa
                            </span>

                        @else

                            <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-sm">
                                Inactiva
                            </span>

                        @endif

                    </td>

                    <td class="px-4 py-3 text-center">

                        <a href="{{ route('marcas.edit', $marca) }}"
                           class="text-amber-600 hover:text-amber-800 font-medium">
                            Editar
                        </a>

                    </td>

                </tr>

            @empty

                <tr>

                    <td colspan="3"
                        class="text-center py-8 text-gray-500">

                        No hay marcas registradas.

                    </td>

                </tr>

            @endforelse

            </tbody>

        </table>

    </div>

    <!-- Paginación -->

    <div>
        {{ $marcas->links() }}
    </div>

</div>

@endsection