@extends('layouts.app')

@section('title', 'Nuevo Proveedor')

@section('description', 'Registrar un nuevo proveedor.')

@section('content')

<div class="space-y-6">

    <div class="flex justify-end">

        <a
            href="{{ route('proveedores.index') }}"
            class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
            Volver
        </a>

    </div>

    <form
        action="{{ route('proveedores.store') }}"
        method="POST"
        novalidate>

        <x-card>

            <x-slot:header>
                <h3 class="text-lg font-semibold">
                    Información del Proveedor
                </h3>
            </x-slot:header>

            @include('proveedores._form')

            <x-slot:footer>

                <div class="flex justify-end gap-3">

                    <a
                        href="{{ route('proveedores.index') }}"
                        class="rounded-lg border border-slate-300 px-5 py-2 hover:bg-slate-100">
                        Cancelar
                    </a>

                    <button
                        type="submit"
                        class="rounded-lg bg-amber-500 px-5 py-2 font-semibold text-white hover:bg-amber-600">
                        Guardar Proveedor
                    </button>

                </div>

            </x-slot:footer>

        </x-card>

    </form>

</div>

@endsection