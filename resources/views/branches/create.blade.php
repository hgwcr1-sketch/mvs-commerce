@extends('layouts.app')

@section('title', 'Nueva Sucursal')

@section('description', 'Registrar una nueva sucursal')

@section('content')

<div class="max-w-4xl mx-auto">

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">
            Nueva Sucursal
        </h1>

        <p class="text-sm text-slate-500 mt-1">
            Ingresa la información de la nueva sucursal.
        </p>
    </div>

    <form method="POST"
          action="{{ route('branches.store') }}"
          class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8">

        @csrf

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            <!-- NOMBRE -->
            <div>
                <label for="name"
                       class="block text-sm font-semibold text-slate-700 mb-2">
                    Nombre de la sucursal *
                </label>

                <input
                    id="name"
                    type="text"
                    name="name"
                    value="{{ old('name') }}"
                    placeholder="Ejemplo: Liberia"
                    required
                    class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500"
                >

                @error('name')
                    <p class="text-red-600 text-sm mt-2">
                        {{ $message }}
                    </p>
                @enderror
            </div>


            <!-- CÓDIGO -->
            <div>
                <label for="code"
                       class="block text-sm font-semibold text-slate-700 mb-2">
                    Código *
                </label>

                <input
                    id="code"
                    type="text"
                    name="code"
                    value="{{ old('code') }}"
                    placeholder="Ejemplo: LIB"
                    required
                    class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500"
                >

                @error('code')
                    <p class="text-red-600 text-sm mt-2">
                        {{ $message }}
                    </p>
                @enderror
            </div>


            <!-- TELÉFONO -->
            <div>
                <label for="phone"
                       class="block text-sm font-semibold text-slate-700 mb-2">
                    Teléfono
                </label>

                <input
                    id="phone"
                    type="text"
                    name="phone"
                    value="{{ old('phone') }}"
                    placeholder="Ejemplo: 7282-2553"
                    class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500"
                >
            </div>


            <!-- ESTADO -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">
                    Estado
                </label>

                <div class="w-full px-4 py-3 border border-slate-300 rounded-xl bg-slate-50">
                    <span class="inline-flex items-center gap-2 text-green-700 font-semibold">
                        ● Activa
                    </span>
                </div>
            </div>

        </div>


        <!-- DIRECCIÓN -->
        <div class="mt-6">

            <label for="address"
                   class="block text-sm font-semibold text-slate-700 mb-2">
                Dirección
            </label>

            <textarea
                id="address"
                name="address"
                rows="4"
                placeholder="Ejemplo: Liberia centro, 200 metros norte..."
                class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500"
            >{{ old('address') }}</textarea>

        </div>


        <!-- BOTONES -->
        <div class="flex items-center gap-3 mt-8 pt-6 border-t border-slate-200">

            <button
                type="submit"
                class="bg-amber-500 hover:bg-amber-600 text-white font-semibold px-6 py-3 rounded-xl transition">
                Guardar Sucursal
            </button>

            <a
                href="{{ route('branches.index') }}"
                class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold px-6 py-3 rounded-xl transition">
                Cancelar
            </a>

        </div>

    </form>

</div>

@endsection