@extends('layouts.app')

@section('title', 'Usuarios')

@section('description', 'Administración de usuarios del sistema')

@section('content')

<div class="space-y-6">

    @can('usuarios.crear')

<div class="mb-6 flex justify-end">

    <a href="{{ route('usuarios.create') }}">

        <x-button color="primary">
            + Nuevo Usuario
        </x-button>

    </a>

</div>

@endcan
    <x-card>

        {{-- Mensaje de éxito --}}
        @if(session('success'))

            <div class="mb-5 rounded-lg border border-green-200 bg-green-50 p-4 text-green-700">
                {{ session('success') }}
            </div>

        @endif

        {{-- Mensaje de error --}}
        @if(session('error'))

            <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-red-700">
                {{ session('error') }}
            </div>

        @endif

        {{-- Buscador --}}
        <x-search-box
            :action="route('usuarios.index')"
            placeholder="Buscar por nombre o correo..."
        />

        <x-table>

            <thead class="bg-slate-50">

                <tr>

                    <th class="px-4 py-3 text-left text-sm font-semibold">
                        Foto
                    </th>

                    <th class="px-4 py-3 text-left text-sm font-semibold">
                        Nombre
                    </th>

                    <th class="px-4 py-3 text-left text-sm font-semibold">
                        Correo
                    </th>

                    <th class="px-4 py-3 text-left text-sm font-semibold">
                        Teléfono
                    </th>

                    <th class="px-4 py-3 text-center text-sm font-semibold">
                        Rol
                    </th>

                    <th class="px-4 py-3 text-center text-sm font-semibold">
                        Estado
                    </th>

                    <th class="px-4 py-3 text-center text-sm font-semibold">
                        Último acceso
                    </th>

                    <th class="px-4 py-3 text-center text-sm font-semibold">
                        Acciones
                    </th>

                </tr>

            </thead>

            <tbody class="divide-y divide-slate-200">

                @forelse($users as $user)

                    <tr class="hover:bg-slate-50">

                        {{-- Foto --}}
                        <td class="px-4 py-3">

                            @if($user->photo)

                                <img
                                    src="{{ asset('storage/'.$user->photo) }}"
                                    class="h-12 w-12 rounded-full object-cover">

                            @else

                                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-200 font-bold">
                                    {{ strtoupper(substr($user->name, 0, 1)) }}
                                </div>

                            @endif

                        </td>

                        {{-- Nombre --}}
                        <td class="px-4 py-3 font-medium">
                            {{ $user->name }}
                        </td>

                        {{-- Correo --}}
                        <td class="px-4 py-3">
                            {{ $user->email }}
                        </td>

                        {{-- Teléfono --}}
                        <td class="px-4 py-3">
                            {{ $user->phone ?? '-' }}
                        </td>

                        {{-- Rol --}}
                        <td class="px-4 py-3 text-center">

                            @if($user->current_company_role)

                                <x-badge type="warning">
                                    {{ $user->current_company_role->name }}
                                </x-badge>

                            @else

                                <span class="text-sm text-slate-400">
                                    Sin rol
                                </span>

                            @endif

                        </td>

                        {{-- Estado --}}
                        <td class="px-4 py-3 text-center">

                            @if($user->is_active)

                                <x-badge type="success">
                                    Activo
                                </x-badge>

                            @else

                                <x-badge type="danger">
                                    Inactivo
                                </x-badge>

                            @endif

                        </td>

                        {{-- Último acceso --}}
                        <td class="px-4 py-3 text-center text-sm">

                            {{ $user->last_login_at?->format('d/m/Y H:i') ?? 'Nunca' }}

                        </td>

                        {{-- Acciones --}}
<td class="px-4 py-3">

    <div class="flex justify-center gap-2">

        <a href="{{ route('usuarios.show', $user) }}">

            <x-button color="primary">
                Ver
            </x-button>

        </a>


        @can('usuarios.editar')

        <a href="{{ route('usuarios.edit', $user) }}">

            <x-button color="secondary">
                Editar
            </x-button>

        </a>

        @endcan


        @can('usuarios.desactivar')

        <form
            action="{{ route('usuarios.destroy', $user) }}"
            method="POST"
            onsubmit="return confirm('¿Desea eliminar este usuario?')">

            @csrf
            @method('DELETE')

            <x-button
                color="danger"
                type="submit">

                Eliminar

            </x-button>

        </form>

        @endcan


    </div>

</td>
            
                    </tr>

                @empty

                    <tr>

                        <td
                            colspan="8"
                            class="px-4 py-8 text-center text-slate-500">

                            No hay usuarios registrados.

                        </td>

                    </tr>

                @endforelse

            </tbody>

        </x-table>

        <div class="mt-6">
            {{ $users->links() }}
        </div>

    </x-card>

</div>

@endsection