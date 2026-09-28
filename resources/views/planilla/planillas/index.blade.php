@extends('layouts.app')

@section('title', 'Planillas')

@section('description', 'Listado de planillas')

@section('content')

<div class="space-y-6">

    <div class="flex justify-between items-center">
        <h1 class="text-2xl font-bold text-slate-800">Planillas</h1>
    </div>

    {{-- Tabla --}}
    <x-table>

        <x-table-header>

            <x-th>Número</x-th>
            <x-th>Periodo</x-th>
            <x-th>Frecuencia</x-th>
            <x-th>Estado</x-th>

        </x-table-header>

        <x-table-body>

@if($payrolls->count())

    @foreach($payrolls as $payroll)

    <tr class="border-t hover:bg-slate-50">

        <td class="px-4 py-3">
            {{ $payroll->payroll_number }}
        </td>

        <td class="px-4 py-3">
            {{ $payroll->period_start->format('d/m/Y') }} - {{ $payroll->period_end->format('d/m/Y') }}
        </td>

        <td class="px-4 py-3 text-capitalize">
            {{ $payroll->frequency }}
        </td>

        <td class="px-4 py-3 text-center">

            @if($payroll->status === 'borrador')
                <span class="rounded-full bg-amber-100 px-3 py-1 text-xs text-amber-700">
                    Borrador
                </span>
            @elseif($payroll->status === 'calculada')
                <span class="rounded-full bg-blue-100 px-3 py-1 text-xs text-blue-700">
                    Calculada
                </span>
            @elseif($payroll->status === 'cerrada')
                <span class="rounded-full bg-green-100 px-3 py-1 text-xs text-green-700">
                    Cerrada
                </span>
            @else
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-700">
                    {{ $payroll->status }}
                </span>
            @endif

        </td>

    </tr>

    @endforeach

@else

<tr>

    <td colspan="4"
        class="py-10 text-center text-slate-500">

        No hay planillas registradas.

    </td>

</tr>

@endif

</x-table-body>

</x-table>

</div>

@endsection