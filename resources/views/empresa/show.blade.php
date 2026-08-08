@extends('layouts.app')

@section('title', 'Detalle de Empresa')

@section('description', $company->trade_name)

@section('content')

<div class="space-y-6">

    {{-- Acciones --}}

    <div class="flex justify-end gap-3">

        <a
            href="{{ route('empresa.index') }}"
            class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
            Volver
        </a>

        <a
            href="{{ route('empresa.edit', $company) }}"
            class="rounded-xl bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600">
            Editar Empresa
        </a>

    </div>

    {{-- Información General --}}

    <x-card>

        <x-slot:header>

            <div class="flex items-center justify-between">

                <h3 class="text-lg font-semibold">
                    Información General
                </h3>

                @if($company->is_active)

                    <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-700">
                        Activa
                    </span>

                @else

                    <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-medium text-red-700">
                        Suspendida
                    </span>

                @endif

            </div>

        </x-slot:header>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">

            <div>
                <p class="text-sm text-slate-500">Nombre Comercial</p>
                <p class="mt-1 font-medium text-slate-800">
                    {{ $company->trade_name }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Razón Social</p>
                <p class="mt-1 font-medium text-slate-800">
                    {{ $company->legal_name ?: '-' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Tipo de Identificación</p>

                <p class="mt-1 font-medium text-slate-800">
                    @switch($company->identification_type)
                        @case('01')
                            Cédula Física
                            @break
                        @case('02')
                            Cédula Jurídica
                            @break
                        @case('03')
                            DIMEX
                            @break
                        @case('04')
                            NITE
                            @break
                        @case('05')
                            Extranjero no domiciliado
                            @break
                        @default
                            -
                    @endswitch
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Número de Identificación</p>
                <p class="mt-1 font-medium text-slate-800">
                    {{ $company->identification_number ?: '-' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Teléfono</p>
                <p class="mt-1 font-medium text-slate-800">
                    {{ $company->phone ?: '-' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Correo Electrónico</p>
                <p class="mt-1 font-medium text-slate-800">
                    {{ $company->email ?: '-' }}
                </p>
            </div>

        </div>

    </x-card>

    {{-- Dirección --}}

    <x-card>

        <x-slot:header>
            <h3 class="text-lg font-semibold">
                Dirección
            </h3>
        </x-slot:header>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">

            <div>
                <p class="text-sm text-slate-500">País</p>
                <p class="mt-1 font-medium text-slate-800">
                    {{ $company->country?->name ?: '-' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Provincia</p>
                <p class="mt-1 font-medium text-slate-800">
                    {{ $company->province?->name ?: '-' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Cantón</p>
                <p class="mt-1 font-medium text-slate-800">
                    {{ $company->canton?->name ?: '-' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Distrito</p>
                <p class="mt-1 font-medium text-slate-800">
                    {{ $company->district?->name ?: '-' }}
                </p>
            </div>

            <div class="md:col-span-2">
                <p class="text-sm text-slate-500">Dirección Exacta</p>
                <p class="mt-1 font-medium text-slate-800">
                    {{ $company->address ?: '-' }}
                </p>
            </div>

        </div>

    </x-card>

    {{-- Configuración --}}

    <x-card>

        <x-slot:header>
            <h3 class="text-lg font-semibold">
                Configuración
            </h3>
        </x-slot:header>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">

            <div>
                <p class="text-sm text-slate-500">Moneda Principal</p>
                <p class="mt-1 font-medium text-slate-800">
                    {{ $company->currency === 'USD'
                        ? 'USD - Dólar estadounidense ($)'
                        : 'CRC - Colón costarricense (₡)' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Zona Horaria</p>
                <p class="mt-1 font-medium text-slate-800">
                    {{ $company->timezone ?: 'America/Costa_Rica' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Propietario</p>
                <p class="mt-1 font-medium text-slate-800">
                    {{ $company->owner?->name ?: '-' }}
                </p>
            </div>

        </div>

    </x-card>

</div>

@endsection