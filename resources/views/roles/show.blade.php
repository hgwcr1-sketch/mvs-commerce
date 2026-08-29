@extends('layouts.app')

@section('title', 'Detalle del Rol')

@section('description', 'Información y permisos asignados al rol')

@section('content')

<div class="space-y-6">

    {{-- Encabezado --}}

    <div class="flex items-center justify-between">

        <div>
            <h2 class="text-xl font-semibold text-slate-800">
                {{ $role->name }}
            </h2>

            <p class="mt-1 text-sm text-slate-500">
                Detalle del rol y permisos asignados.
            </p>
        </div>

        <div class="flex gap-3">

            <a
                href="{{ route('roles.index') }}"
                class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
                Volver
            </a>

            <a
                href="{{ route('roles.edit', $role) }}"
                class="inline-flex min-h-11 items-center rounded-xl bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600">
                Editar
            </a>

        </div>

    </div>

    @php
        $roleTabs = [
            ['id' => 'resumen', 'label' => 'Resumen'],
            ['id' => 'usuarios', 'label' => 'Usuarios', 'badge' => $role->users->count()],
            ['id' => 'permisos', 'label' => 'Permisos', 'badge' => $role->permissions->count()],
        ];
    @endphp
    <x-tabs :tabs="$roleTabs" active-tab="resumen" variant="pills" aria-label="Secciones del rol">
    <div id="panel-resumen" role="tabpanel" aria-labelledby="tab-resumen" x-show="activeTab === 'resumen'" class="space-y-6">

    {{-- Información general --}}

    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">

        <x-card>

            <p class="text-sm text-slate-500">
                Estado
            </p>

            <div class="mt-3">

                @if($role->is_active)

                    <span class="rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-700">
                        Activo
                    </span>

                @else

                    <span class="rounded-full bg-red-100 px-3 py-1 text-sm font-medium text-red-700">
                        Inactivo
                    </span>

                @endif

            </div>

        </x-card>

        <x-card>

            <p class="text-sm text-slate-500">
                Usuarios Asignados
            </p>

            <h2 class="mt-2 text-4xl font-bold text-slate-800">
                {{ $role->users->count() }}
            </h2>

        </x-card>

        <x-card>

            <p class="text-sm text-slate-500">
                Permisos Asignados
            </p>

            <h2 class="mt-2 text-4xl font-bold text-amber-500">
                {{ $role->permissions->count() }}
            </h2>

        </x-card>

    </div>

    {{-- Descripción --}}

    <x-card>

        <x-slot:header>

            <h3 class="text-lg font-semibold">
                Información del Rol
            </h3>

        </x-slot:header>

        <div>

            <p class="text-sm font-medium text-slate-500">
                Descripción
            </p>

            <p class="mt-2 text-slate-700">
                {{ $role->description ?: 'Sin descripción.' }}
            </p>

        </div>

    </x-card>

    </div>

    {{-- Usuarios --}}
    <div id="panel-usuarios" role="tabpanel" aria-labelledby="tab-usuarios" x-show="activeTab === 'usuarios'" x-cloak>

    <x-card>

        <x-slot:header>

            <h3 class="text-lg font-semibold">
                Usuarios Asignados
            </h3>

        </x-slot:header>

        @forelse($role->users as $user)

            <div class="flex items-center justify-between border-b border-slate-100 py-3 last:border-b-0">

                <div>

                    <p class="font-medium text-slate-800">
                        {{ $user->name }}
                    </p>

                    <p class="mt-1 text-sm text-slate-500">
                        {{ $user->email }}
                    </p>

                </div>

                @if($user->is_active)

                    <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-700">
                        Activo
                    </span>

                @else

                    <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-medium text-red-700">
                        Inactivo
                    </span>

                @endif

            </div>

        @empty

            <p class="py-4 text-sm text-slate-500">
                No hay usuarios asignados a este rol.
            </p>

        @endforelse

    </x-card>

    </div>

    {{-- Permisos --}}
    <div id="panel-permisos" role="tabpanel" aria-labelledby="tab-permisos" x-show="activeTab === 'permisos'" x-cloak>

    <x-card>

        <x-slot:header>

            <div>

                <h3 class="text-lg font-semibold">
                    Permisos Asignados
                </h3>

                <p class="mt-1 text-sm font-normal text-slate-500">
                    Funciones disponibles para los usuarios que tengan este rol.
                </p>

            </div>

        </x-slot:header>

        @if($permissionsByModule->isEmpty())

            <p class="py-4 text-sm text-slate-500">
                Este rol no tiene permisos asignados.
            </p>

        @else

            <div class="space-y-5">

                @foreach($permissionsByModule as $module => $modulePermissions)

                    <div class="overflow-hidden rounded-xl border border-slate-200">

                        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">

                            <div class="flex items-center justify-between">

                                <h4 class="font-semibold text-slate-800">
                                    {{ $module }}
                                </h4>

                                <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-700">
                                    {{ $modulePermissions->count() }}
                                </span>

                            </div>

                        </div>

                        <div class="grid grid-cols-1 gap-3 p-4 md:grid-cols-2 lg:grid-cols-3">

                            @foreach($modulePermissions as $permission)

                                <div class="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-3">

                                    <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-100 text-sm font-bold text-green-700">
                                        ✓
                                    </div>

                                    <span class="text-sm font-medium text-slate-700">
                                        {{ $permission->label }}
                                    </span>

                                </div>

                            @endforeach

                        </div>

                    </div>

                @endforeach

            </div>

        @endif

    </x-card>
    </div>
    </x-tabs>

</div>

@endsection
