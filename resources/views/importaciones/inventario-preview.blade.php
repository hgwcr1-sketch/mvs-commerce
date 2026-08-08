@extends('layouts.app')

@section('title', 'Revisar Inventario')

@section('description', 'Vista previa de movimiento de inventario')

@section('content')

<div class="space-y-6">

    {{-- BARRA SUPERIOR --}}
    <div class="flex items-center justify-between gap-4">

        <div class="flex flex-wrap items-center gap-4">

            <div>
                <span class="text-sm font-semibold text-slate-700">
                    Sucursal:
                </span>

                <span class="ml-1 text-sm text-slate-600">
                    {{ $branch->name }}
                </span>
            </div>

            <div>
                <span class="text-sm font-semibold text-slate-700">
                    Movimiento:
                </span>

                @if($movementType === 'entry')
                    <span class="ml-1 inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                        Entrada
                    </span>
                @else
                    <span class="ml-1 inline-flex rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">
                        Salida
                    </span>
                @endif
            </div>

        </div>

        <a
            href="{{ route('importaciones.inventario') }}"
            class="shrink-0 inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
            ← Volver
        </a>

    </div>

    {{-- AVISO --}}
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">

        <p class="text-sm font-semibold text-slate-700">
            Vista previa del movimiento
        </p>

        <p class="mt-1 text-sm text-slate-600">
            @if($movementType === 'entry')
                Las cantidades del archivo se sumarán a las existencias actuales.
            @else
                Las cantidades del archivo se restarán de las existencias actuales.
            @endif
            Todavía no se ha modificado el inventario.
        </p>

    </div>

    {{-- TABLA --}}
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

        <div class="overflow-x-auto">

            <table class="min-w-full divide-y divide-slate-200">

                <thead class="bg-slate-50">
                    <tr>

                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                            Código
                        </th>

                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                            Producto
                        </th>

                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                            Stock actual
                        </th>

                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                            Cantidad
                        </th>

                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                            Resultado
                        </th>

                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                            Mínimo
                        </th>

                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                            Máximo
                        </th>

                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                            Estado
                        </th>

                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100 bg-white">

                    @foreach($rows as $row)

                        @php
                            $resultado = $movementType === 'entry'
                                ? (float) $row['current_stock'] + (float) $row['quantity']
                                : (float) $row['current_stock'] - (float) $row['quantity'];
                        @endphp

                        <tr>

                            <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-700">
                                {{ $row['code'] }}
                            </td>

                            <td class="px-4 py-3 text-sm text-slate-700">
                                {{ $row['product_name'] ?? '—' }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-slate-700">
                                {{ number_format((float) $row['current_stock'], 2) }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-slate-800">
                                @if($movementType === 'entry')
                                    +
                                @else
                                    −
                                @endif

                                {{ number_format((float) $row['quantity'], 2) }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-bold text-slate-800">
                                {{ number_format($resultado, 2) }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-slate-700">
                                {{ number_format((float) $row['minimum'], 2) }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-slate-700">
                                {{ number_format((float) $row['maximum'], 2) }}
                            </td>

                            <td class="px-4 py-3 text-sm">

                                @if($row['valid'])

                                    <span class="inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                                        Listo para importar
                                    </span>

                                @else

                                    <span class="inline-flex rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">
                                        Error
                                    </span>

                                    <div class="mt-2 text-xs text-red-600">
                                        {{ implode(' ', $row['errors']) }}
                                    </div>

                                @endif

                            </td>

                        </tr>

                    @endforeach

                </tbody>

            </table>

        </div>

    </div>

    {{-- RESUMEN Y CONFIRMACIÓN --}}
    <div class="flex flex-wrap items-center justify-between gap-4">

        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600 shadow-sm">

            Registros:
            <strong class="text-slate-800">
                {{ count($rows) }}
            </strong>

            &nbsp; · &nbsp;

            Listos:
            <strong class="text-emerald-700">
                {{ collect($rows)->where('valid', true)->count() }}
            </strong>

            &nbsp; · &nbsp;

            Con errores:
            <strong class="text-red-700">
                {{ collect($rows)->where('valid', false)->count() }}
            </strong>

        </div>

        @if(
            count($rows) > 0 &&
            collect($rows)->where('valid', false)->count() === 0
        )

            <form
                action="{{ route('importaciones.inventario.import') }}"
                method="POST"
                onsubmit="return confirm('{{ $movementType === 'entry'
                    ? '¿Confirmar la entrada? Las cantidades serán sumadas al inventario actual.'
                    : '¿Confirmar la salida? Las cantidades serán restadas del inventario actual.'
                }}');">

                @csrf

                <button
                    type="submit"
                    class="rounded-xl bg-slate-800 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-700">

                    {{ $movementType === 'entry'
                        ? 'Confirmar entrada'
                        : 'Confirmar salida'
                    }}

                </button>

            </form>

        @endif

    </div>

</div>

@endsection