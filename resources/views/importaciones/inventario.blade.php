@extends('layouts.app')

@section('title', 'Importar Inventario')

@section('description', 'Importar movimientos de inventario')

@section('content')

<div class="space-y-6">

    {{-- BARRA DE ACCIONES --}}
<div class="flex flex-wrap justify-between items-center gap-4">

    <div class="flex flex-wrap gap-3">

        {{-- Plantilla oficial --}}
        <a
            href="{{ route('importaciones.inventario.template') }}"
            class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
            ↓ Plantilla Excel
        </a>


        {{-- Ejemplo --}}
        <a
            href="{{ route('importaciones.inventario.example') }}"
            class="inline-flex items-center rounded-xl bg-slate-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
            ↓ Ejemplo de llenado
        </a>


        {{-- Instrucciones --}}
        
        <a
    href="{{ route('importaciones.inventario.instructions') }}"
    class="inline-flex items-center rounded-xl border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-700 shadow-sm hover:bg-amber-100">
    ? Instrucciones
</a>

    </div>


    <a
        href="{{ route('importaciones.index') }}"
        class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
        ← Volver
    </a>

</div>

    {{-- ERRORES --}}
    @if ($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-4">
            <p class="font-semibold text-red-700">
                No se pudo revisar el archivo.
            </p>

            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-600">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- FORMULARIO --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">

        <form
            action="{{ route('importaciones.inventario.preview') }}"
            method="POST"
            enctype="multipart/form-data">

            @csrf

            <div class="space-y-6">

                {{-- SUCURSAL --}}
                <div>
                    <label
                        for="branch_id"
                        class="mb-2 block text-sm font-semibold text-slate-700">
                        Sucursal
                    </label>

                    <select
                        id="branch_id"
                        name="branch_id"
                        required
                        class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">

                        @foreach($branches as $branch)
                            <option
                                value="{{ $branch->id }}"
                                @selected(old('branch_id', $branchId) == $branch->id)>
                                {{ $branch->name }}
                            </option>
                        @endforeach

                    </select>

                    <p class="mt-2 text-xs text-slate-500">
                        Solo aparecen sucursales pertenecientes a la empresa activa.
                    </p>
                </div>

                {{-- TIPO DE MOVIMIENTO --}}
                <div>
                    <label
                        for="movement_type"
                        class="mb-2 block text-sm font-semibold text-slate-700">
                        Tipo de movimiento
                    </label>

                    <select
                        id="movement_type"
                        name="movement_type"
                        required
                        class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">

                        <option
                            value="entry"
                            @selected(old('movement_type') === 'entry')>
                            Entrada de inventario
                        </option>

                        <option
                            value="exit"
                            @selected(old('movement_type') === 'exit')>
                            Salida de inventario
                        </option>

                    </select>

                    <p class="mt-2 text-xs text-slate-500">
                        Entrada suma cantidades al inventario. Salida resta cantidades del inventario.
                    </p>
                </div>

                {{-- ARCHIVO --}}
                <div>
                    <label
                        for="inventory_file"
                        class="mb-2 block text-sm font-semibold text-slate-700">
                        Archivo de inventario
                    </label>

                    <input
                        type="file"
                        id="inventory_file"
                        name="inventory_file"
                        accept=".xlsx,.xls,.csv"
                        required
                        class="block w-full rounded-xl border border-slate-300 p-3 text-sm">

                    <p class="mt-2 text-xs text-slate-500">
                        Formatos permitidos: Excel (.xlsx, .xls) o CSV.
                    </p>
                </div>

                {{-- INFORMACIÓN --}}
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">

                    <p class="text-sm font-semibold text-slate-700">
                        Importación masiva de movimientos
                    </p>

                    <p class="mt-2 text-sm text-slate-600">
                        Las cantidades del archivo se sumarán o restarán según el tipo de movimiento seleccionado.
                    </p>

                    <p class="mt-2 text-sm text-slate-600">
                        Código interno o código de barras · Cantidad · Stock mínimo · Stock máximo
                    </p>

                </div>

                {{-- BOTÓN --}}
                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="rounded-xl bg-slate-800 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-700">
                        Revisar archivo
                    </button>
                </div>

            </div>

        </form>

    </div>

</div>

@endsection