@extends('layouts.app')

@section('title', 'Nuevo Rol')

@section('description', 'Crear un nuevo rol y asignar permisos')

@section('content')

<div class="space-y-6">

    <div class="flex items-center justify-between">

        <div>
            <h2 class="text-xl font-semibold text-slate-800">
                Nuevo Rol
            </h2>

            <p class="mt-1 text-sm text-slate-500">
                Defina el rol y seleccione los permisos que tendrá.
            </p>
        </div>

        <a
            href="{{ route('roles.index') }}"
            class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
            Volver
        </a>

    </div>

    @if ($errors->any())

        <div class="rounded-xl border border-red-200 bg-red-50 p-4">

            <p class="font-semibold text-red-700">
                Por favor revise la información:
            </p>

            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-600">

                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach

            </ul>

        </div>

    @endif

    <form method="POST" action="{{ route('roles.store') }}" class="space-y-6">

        @csrf

        <x-card>

            <x-slot:header>
                <h3 class="text-lg font-semibold">
                    Información del Rol
                </h3>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">

                <x-input
                    name="name"
                    label="Nombre del Rol"
                    :value="old('name')"
                    required />

                <div class="flex items-end pb-3">

                    <x-checkbox
                        name="is_active"
                        label="Rol Activo"
                        :checked="old('is_active', true)" />

                </div>

            </div>

            <div class="mt-6">

                <x-textarea
                    name="description"
                    label="Descripción"
                    rows="3"
                    :value="old('description')" />

            </div>

        </x-card>

        <x-card>

            <x-slot:header>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

                    <div>
                        <h3 class="text-lg font-semibold">
                            Permisos
                        </h3>

                        <p class="mt-1 text-sm font-normal text-slate-500">
                            Seleccione las funciones que podrá utilizar este rol.
                        </p>
                    </div>

                    <button
                        type="button"
                        id="toggle-all-permissions"
                        class="rounded-lg border border-amber-400 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-700 hover:bg-amber-100">
                        Seleccionar todos
                    </button>

                </div>

            </x-slot:header>

            <div class="space-y-6">

                @foreach($permissions as $module => $modulePermissions)

                    <div class="rounded-xl border border-slate-200">

                        <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-4 py-3">

                            <h4 class="font-semibold text-slate-800">
                                {{ $module }}
                            </h4>

                            <button
                                type="button"
                                class="toggle-module text-sm font-medium text-amber-600 hover:text-amber-700"
                                data-module="{{ $loop->index }}">
                                Seleccionar módulo
                            </button>

                        </div>

                        <div class="grid grid-cols-1 gap-3 p-4 md:grid-cols-2 lg:grid-cols-3">

                            @foreach($modulePermissions as $permission)

                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-amber-50">

                                    <input
                                        type="checkbox"
                                        name="permissions[]"
                                        value="{{ $permission->id }}"
                                        data-module="{{ $loop->parent->index }}"
                                        @checked(in_array($permission->id, old('permissions', [])))
                                        class="permission-checkbox mt-1 rounded border-slate-300 text-amber-500 focus:ring-amber-400">

                                    <span>

                                        <span class="block text-sm font-medium text-slate-700">
                                            {{ $permission->label }}
                                        </span>

                                        @if($permission->description)

                                            <span class="mt-1 block text-xs text-slate-500">
                                                {{ $permission->description }}
                                            </span>

                                        @endif

                                    </span>

                                </label>

                            @endforeach

                        </div>

                    </div>

                @endforeach

            </div>

        </x-card>

        <div class="flex justify-end gap-3">

            <a
                href="{{ route('roles.index') }}"
                class="rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">
                Cancelar
            </a>

            <x-button type="submit">
                Guardar Rol
            </x-button>

        </div>

    </form>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const permissions = Array.from(
        document.querySelectorAll('.permission-checkbox')
    );

    const toggleAll = document.getElementById('toggle-all-permissions');

    function updateToggleAllText() {

        const allSelected =
            permissions.length > 0 &&
            permissions.every(permission => permission.checked);

        toggleAll.textContent =
            allSelected ? 'Quitar todos' : 'Seleccionar todos';
    }

    toggleAll.addEventListener('click', function () {

        const shouldSelect =
            !permissions.every(permission => permission.checked);

        permissions.forEach(permission => {
            permission.checked = shouldSelect;
        });

        updateToggleAllText();
    });

    document.querySelectorAll('.toggle-module').forEach(button => {

        button.addEventListener('click', function () {

            const module = this.dataset.module;

            const modulePermissions = permissions.filter(
                permission => permission.dataset.module === module
            );

            const shouldSelect =
                !modulePermissions.every(permission => permission.checked);

            modulePermissions.forEach(permission => {
                permission.checked = shouldSelect;
            });

            this.textContent =
                shouldSelect ? 'Quitar módulo' : 'Seleccionar módulo';

            updateToggleAllText();
        });

    });

    permissions.forEach(permission => {
        permission.addEventListener('change', updateToggleAllText);
    });

    updateToggleAllText();

});
</script>

@endsection