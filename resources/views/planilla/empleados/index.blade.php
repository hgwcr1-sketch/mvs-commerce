@extends('layouts.app')

@section('title', 'Empleados de Planilla')

@section('description', 'Listado de empleados para planilla')

@section('content')

<div class="space-y-6">

    <div class="flex justify-between items-center">
        <h1 class="text-2xl font-bold text-slate-800">Empleados</h1>
    </div>

    {{-- Tabla --}}
    <x-table>

        <x-table-header>

            <x-th>Código</x-th>
            <x-th>Nombre</x-th>
            <x-th>Estado</x-th>

        </x-table-header>

        <x-table-body>

@if($employees->count())

    @foreach($employees as $employee)

    <tr class="border-t hover:bg-slate-50">

        <td class="px-4 py-3">
            {{ $employee->employee_code }}
        </td>

        <td class="px-4 py-3 font-medium">
            {{ $employee->first_name }} {{ $employee->last_name }}
        </td>

        <td class="px-4 py-3 text-center">

            @if($employee->status === 'active')

                <span class="rounded-full bg-green-100 px-3 py-1 text-xs text-green-700">
                    Activo
                </span>

            @else

                <span class="rounded-full bg-red-100 px-3 py-1 text-xs text-red-700">
                    Inactivo
                </span>

            @endif

        </td>

    </tr>

    @endforeach

@else

<tr>

    <td colspan="3"
        class="py-10 text-center text-slate-500">

        No hay empleados registrados.

    </td>

</tr>

@endif

</x-table-body>

</x-table>

</div>

@endsection