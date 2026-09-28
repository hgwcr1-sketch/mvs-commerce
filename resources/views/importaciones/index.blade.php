@extends('layouts.app')

@section('title', 'Importar Datos')

@section('description', 'Migración de información hacia MVS Commerce')

@section('content')

<div class="space-y-6">

    {{-- ENCABEZADO --}}
    <div>
        <h1 class="text-2xl font-bold text-slate-800">
            Importar Datos
        </h1>

        <p class="mt-1 text-sm text-slate-500">
            Migra información desde otros sistemas hacia MVS Commerce.
        </p>
    </div>

    {{-- OPCIONES DE IMPORTACIÓN --}}
    <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">

        {{-- CLIENTES --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-800">
                Clientes
            </h2>

            <p class="mt-2 text-sm text-slate-500">
                Importar clientes mediante archivo Excel o CSV.
            </p>

            <button
                type="button"
                class="mt-5 rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">
                Importar clientes
            </button>
        </div>

        {{-- PRODUCTOS --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-800">
                Productos
            </h2>

            <p class="mt-2 text-sm text-slate-500">
                Importar productos, códigos y datos generales.
            </p>

            <button
                type="button"
                class="mt-5 rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">
                Importar productos
            </button>
        </div>

        {{-- INVENTARIO --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-800">
                Inventario
            </h2>

            <p class="mt-2 text-sm text-slate-500">
                Importar existencias iniciales para cada sucursal.
            </p>

            <a
                href="{{ route('importaciones.inventario-migracion') }}"
                class="mt-5 inline-block rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">
                Importar inventario inicial P36
            </a>
        </div>

    </div>

</div>

@endsection
