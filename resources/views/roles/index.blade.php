@extends('layouts.app')

@section('title', 'Roles y Permisos')

@section('description', 'Administración de roles y permisos de usuarios')

@section('content')

<div class="space-y-6">

    {{-- Encabezado --}}

    <div class="flex items-center justify-between">

        <div>
            <h2 class="text-xl font-semibold text-slate-800">
                Roles de Usuario
            </h2>

            <p class="mt-1 text-sm text-slate-500">
                Administre los niveles de acceso de los usuarios de la empresa.
            </p>
        </div>

        <a href="{{ route('roles.create') }}">
            <x-button>
                + Nuevo Rol
            </x-button>
        </a>

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

    {{-- Resumen --}}

    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">

        <x-card>

            <p class="text-sm text-slate-500">
                Total Roles
            </p>

            <h2 class="mt-2 text-4xl font-bold text-slate-800">
                {{ $roles->count() }}
            </h2>

        </x-card>

        <x-card>

            <p class="text-sm text-slate-500">
                Roles Activos
            </p>

            <h2 class="mt-2 text-4xl font-bold text-green-600">
                {{ $roles->where('is_active', true)->count() }}
            </h2>

        </x-card>

        <x-card>

            <p class="text-sm text-slate-500">
                Usuarios Asignados
            </p>

            <h2 class="mt-2 text-4xl font-bold text-amber-500">
                {{ $roles->sum('users_count') }}
            </h2>

        </x-card>

    </div>

    {{-- Tabla --}}

    <x-table>

        <x-table-header>

            <x-th>Rol</x-th>
            <x-th>Descripción</x-th>
            <x-th>Usuarios</x-th>
            <x-th>Permisos</x-th>
            <x-th>Estado</x-th>
            <x-th>Acciones</x-th>

        </x-table-header>

        <x-table-body>

            @forelse($roles as $role)

                <tr class="border-t hover:bg-slate-50">

                    <td class="px-4 py-3">

                        <div class="font-semibold text-slate-800">
                            {{ $role->name }}
                        </div>

                    </td>

                    <td class="px-4 py-3 text-slate-600">

                        {{ $role->description ?: '-' }}

                    </td>

                    <td class="px-4 py-3 text-center">

                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">
                            {{ $role->users_count }}
                        </span>

                    </td>

                    <td class="px-4 py-3 text-center">

                        <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-700">
                            {{ $role->permissions_count }}
                        </span>

                    </td>

                    <td class="px-4 py-3 text-center">

                        @if($role->is_active)

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
                                href="{{ route('roles.show', $role) }}"
                                class="rounded-lg bg-slate-600 px-3 py-1.5 text-sm text-white hover:bg-slate-700">
                                Ver
                            </a>

                            <a
                                href="{{ route('roles.edit', $role) }}"
                                class="rounded-lg bg-amber-500 px-3 py-1.5 text-sm text-white hover:bg-amber-600">
                                Editar
                            </a>

                            <form
    action="{{ route('roles.destroy', $role) }}"
    method="POST"
    onsubmit="return confirm('¿Está seguro de eliminar este rol?');">

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

                    <td colspan="6" class="py-10 text-center text-slate-500">

                        No hay roles registrados para esta empresa.

                    </td>

                </tr>

            @endforelse

        </x-table-body>

    </x-table>

</div>

@endsection