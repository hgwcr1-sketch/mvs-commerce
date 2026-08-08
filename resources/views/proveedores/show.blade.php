@extends('layouts.app')

@section('title', 'Detalle del Proveedor')

@section('description', 'Información del proveedor.')

@section('content')

<div class="space-y-6">

    <div class="flex items-center justify-between">

        <a
            href="{{ route('proveedores.index') }}"
            class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
            Volver
        </a>

        @can('proveedores.editar')

    <a
        href="{{ route('proveedores.edit', $supplier) }}"
        class="rounded-lg bg-amber-500 px-4 py-2 font-semibold text-white hover:bg-amber-600">
        Editar
    </a>

@endcan

    </div>

    <x-card>

        <x-slot:header>
            <h3 class="text-lg font-semibold">
                Información del Proveedor
            </h3>
        </x-slot:header>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">

            <div>
                <p class="text-sm text-slate-500">Tipo</p>
                <p class="font-medium">
                    {{ $supplier->supplier_type === 'company' ? 'Empresa' : 'Persona' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Identificación</p>
                <p class="font-medium">
                    {{ $supplier->identification ?: '-' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Nombre / Razón Social</p>
                <p class="font-medium">
                    {{ $supplier->name }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Nombre comercial</p>
                <p class="font-medium">
                    {{ $supplier->commercial_name ?: '-' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Persona de contacto</p>
                <p class="font-medium">
                    {{ $supplier->contact_name ?: '-' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Teléfono</p>
                <p class="font-medium">
                    {{ $supplier->phone ?: '-' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Celular</p>
                <p class="font-medium">
                    {{ $supplier->mobile ?: '-' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Correo</p>
                <p class="font-medium">
                    {{ $supplier->email ?: '-' }}
                </p>
            </div>

        </div>

    </x-card>

    <x-card>

        <x-slot:header>
            <h3 class="text-lg font-semibold">
                Ubicación
            </h3>
        </x-slot:header>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">

            <div>
                <p class="text-sm text-slate-500">País</p>
                <p class="font-medium">
                    {{ $supplier->country?->name ?: '-' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Provincia</p>
                <p class="font-medium">
                    {{ $supplier->province?->name ?: '-' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Cantón</p>
                <p class="font-medium">
                    {{ $supplier->canton?->name ?: '-' }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Distrito</p>
                <p class="font-medium">
                    {{ $supplier->district?->name ?: '-' }}
                </p>
            </div>

            <div class="md:col-span-2">
                <p class="text-sm text-slate-500">Dirección</p>
                <p class="font-medium">
                    {{ $supplier->address ?: '-' }}
                </p>
            </div>

        </div>

    </x-card>

    <x-card>

        <x-slot:header>
            <h3 class="text-lg font-semibold">
                Condiciones Comerciales
            </h3>
        </x-slot:header>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">

            <div>
                <p class="text-sm text-slate-500">Días de crédito</p>
                <p class="font-medium">
                    {{ $supplier->credit_days }} días
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Límite de crédito</p>
                <p class="font-medium">
                    ₡{{ number_format($supplier->credit_limit, 2) }}
                </p>
            </div>

            <div>
                <p class="text-sm text-slate-500">Estado</p>

                @if($supplier->is_active)
                    <span class="inline-block rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-700">
                        Activo
                    </span>
                @else
                    <span class="inline-block rounded-full bg-red-100 px-3 py-1 text-xs font-medium text-red-700">
                        Inactivo
                    </span>
                @endif
            </div>

            <div class="md:col-span-2">
                <p class="text-sm text-slate-500">Notas</p>
                <p class="font-medium">
                    {{ $supplier->notes ?: '-' }}
                </p>
            </div>

        </div>

    </x-card>

</div>

@endsection