@extends('layouts.app')

@section('title', 'Unidades de Medida')

@section('content')

<div class="space-y-6">

    {{-- Encabezado --}}
    <div class="flex items-center justify-between">

        <div>
            <h1 class="text-2xl font-bold text-slate-800">
                Unidades de Medida
            </h1>

            <p class="mt-1 text-sm text-slate-500">
                Administra las unidades utilizadas en los productos.
            </p>
        </div>

        <div class="flex items-center gap-3">

            <a
                href="{{ route('productos.index') }}"
                class="rounded-lg border border-slate-300 px-4 py-3 font-medium text-slate-700 hover:bg-slate-100">
                Volver
            </a>

            <a
                href="{{ route('unidades.create') }}"
                class="rounded-xl bg-amber-500 px-5 py-3 font-semibold text-white hover:bg-amber-600">
                + Nueva Unidad
            </a>

        </div>

    </div>

    {{-- Mensajes --}}
    @if(session('success'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-green-700">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-red-700">
            {{ session('error') }}
        </div>
    @endif

    {{-- Tabla --}}
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

        <table class="min-w-full">

            <thead class="bg-slate-50">
                <tr>
                    <th class="px-6 py-4 text-left text-sm font-semibold text-slate-600">
                        Nombre
                    </th>

                    <th class="px-6 py-4 text-left text-sm font-semibold text-slate-600">
                        Abreviatura
                    </th>

                    <th class="px-6 py-4 text-left text-sm font-semibold text-slate-600">
                        Decimales
                    </th>

                    <th class="px-6 py-4 text-left text-sm font-semibold text-slate-600">
                        Estado
                    </th>

                    <th class="px-6 py-4 text-right text-sm font-semibold text-slate-600">
                        Acciones
                    </th>
                </tr>
            </thead>

            <tbody class="divide-y divide-slate-200">

                @forelse($units as $unit)

                    <tr>

                        <td class="px-6 py-4 font-semibold text-slate-800">
                            {{ $unit->name }}
                        </td>

                        <td class="px-6 py-4 text-slate-600">
                            {{ $unit->abbreviation }}
                        </td>

                        <td class="px-6 py-4 text-slate-600">
                            {{ $unit->allows_decimals ? 'Sí' : 'No' }}
                        </td>

                        <td class="px-6 py-4">
                            {{ $unit->is_active ? 'Activa' : 'Inactiva' }}
                        </td>

                        <td class="px-6 py-4 text-right">
                            <a
                                href="{{ route('unidades.edit', $unit) }}"
                                class="font-semibold text-amber-600 hover:text-amber-700">
                                Editar
                            </a>
                        </td>

                    </tr>

                @empty

                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-slate-400">
                            No existen unidades de medida.
                        </td>
                    </tr>

                @endforelse

            </tbody>

        </table>

    </div>

    {{ $units->links() }}

</div>

@endsection