@extends('layouts.app')

@section('title', 'Mis Empresas')

@section('description', 'Administración de empresas')

@section('content')

<div class="space-y-6">

    {{-- Botón nueva empresa --}}

    <div class="flex justify-end">

        @if($availableCompanies > 0)

            <a href="{{ route('empresa.create') }}">
                <x-button>
                    + Nueva Empresa
                </x-button>
            </a>

        @else

            <button
                type="button"
                disabled
                class="cursor-not-allowed rounded-xl bg-slate-300 px-4 py-2 font-medium text-slate-500">
                + Nueva Empresa
            </button>

        @endif

    </div>

    {{-- Estadísticas de autorización --}}

    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">

        <x-card>

            <p class="text-sm text-slate-500">
                Empresas Autorizadas
            </p>

            <h2 class="mt-2 text-4xl font-bold text-slate-800">
                {{ $allowedCompanies }}
            </h2>

        </x-card>

        <x-card>

            <p class="text-sm text-slate-500">
                Empresas Utilizadas
            </p>

            <h2 class="mt-2 text-4xl font-bold text-slate-800">
                {{ $usedCompanies }}
            </h2>

        </x-card>

        <x-card>

            <p class="text-sm text-slate-500">
                Cupos Disponibles
            </p>

            <h2 class="mt-2 text-4xl font-bold text-amber-500">
                {{ $availableCompanies }}
            </h2>

        </x-card>

    </div>

    {{-- Mensajes --}}

    @if(session('success'))

        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('success') }}
        </div>

    @endif

    @if(session('error'))

        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ session('error') }}
        </div>

    @endif

    {{-- Tabla de empresas --}}

    <x-table>

        <x-table-header>

            <x-th>Empresa</x-th>
            <x-th>Identificación</x-th>
            <x-th>Teléfono</x-th>
            <x-th>Correo</x-th>
            <x-th>Estado</x-th>
            <x-th>Acciones</x-th>

        </x-table-header>

        <x-table-body>

            @forelse($companies as $company)

                <tr class="border-t hover:bg-slate-50">

                    <td class="px-4 py-3 font-medium">
                        {{ $company->trade_name }}
                    </td>

                    <td class="px-4 py-3">
                        {{ $company->identification_number ?: '-' }}
                    </td>

                    <td class="px-4 py-3">
                        {{ $company->phone ?: '-' }}
                    </td>

                    <td class="px-4 py-3">
                        {{ $company->email ?: '-' }}
                    </td>

                    <td class="px-4 py-3 text-center">

                        @if($company->is_active)

                            <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-700">
                                Activa
                            </span>

                        @else

                            <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-medium text-red-700">
                                Suspendida
                            </span>

                        @endif

                    </td>

                    <td class="px-4 py-3">

                        <div class="flex items-center justify-center gap-2 whitespace-nowrap">

                            <a
                                href="{{ route('empresa.show', $company) }}"
                                class="rounded-lg bg-slate-600 px-3 py-1.5 text-sm text-white hover:bg-slate-700">
                                Ver
                            </a>

                            <a
                                href="{{ route('empresa.edit', $company) }}"
                                class="rounded-lg bg-amber-500 px-3 py-1.5 text-sm text-white hover:bg-amber-600">
                                Editar
                            </a>

                        </div>

                    </td>

                </tr>

            @empty

                <tr>

                    <td colspan="6" class="py-10 text-center text-slate-500">
                        Todavía no tiene empresas registradas.
                    </td>

                </tr>

            @endforelse

        </x-table-body>

    </x-table>

    {{ $companies->links() }}

</div>

@endsection