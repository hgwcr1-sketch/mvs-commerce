@extends('layouts.app')

@section('title', 'Cotizaciones')

@section('description', 'Consulta y gestión de cotizaciones de la sucursal activa.')

@section('content')

<div class="space-y-6">

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-slate-800">
                Cotizaciones
            </h2>

            <p class="text-sm text-slate-500">
                Las cotizaciones activas pueden convertirse en ventas desde el POS.
            </p>
        </div>

        <a
            href="{{ route('pos.index') }}"
            class="rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-800">
            Nueva cotización
        </a>
    </div>

    <x-card>

        <div class="overflow-x-auto">

            @if($quotes->isNotEmpty())

                <table class="min-w-full divide-y divide-slate-200">

                    <thead class="bg-slate-100 text-xs uppercase text-slate-600">

                        <tr>
                            <th class="px-4 py-3 text-left">Cotización</th>
                            <th class="px-4 py-3 text-left">Cliente</th>
                            <th class="px-4 py-3 text-right">Total</th>
                            <th class="px-4 py-3 text-left">Vence</th>
                            <th class="px-4 py-3 text-left">Estado</th>
                            <th class="px-4 py-3 text-center">Acciones</th>
                        </tr>

                    </thead>

                    <tbody class="divide-y divide-slate-100">

                        @foreach($quotes as $quote)

                            <tr>

                                <td class="px-4 py-3">
                                    <p class="font-semibold text-slate-800">
                                        {{ $quote->quote_number }}
                                    </p>

                                    <p class="text-xs text-slate-500">
                                        {{ optional($quote->created_at)->format('d/m/Y H:i') }}
                                    </p>
                                </td>

                                <td class="px-4 py-3">
                                    {{ $quote->customer?->name ?? 'Consumidor Final' }}
                                </td>

                                <td class="px-4 py-3 text-right font-semibold text-slate-800">
                                    ₡{{ number_format((float) $quote->total, 2, ',', '.') }}
                                </td>

                                <td class="px-4 py-3">
                                    {{ $quote->expires_at ? optional($quote->expires_at)->format('d/m/Y') : '—' }}
                                </td>

                                <td class="px-4 py-3">

                                    @if($quote->isActive() && $quote->isExpired())

                                        <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">
                                            Vencida
                                        </span>

                                    @elseif($quote->isConverted())

                                        <span class="inline-flex rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700">
                                            Convertida
                                        </span>

                                    @elseif($quote->isCancelled())

                                        <span class="inline-flex rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">
                                            Cancelada
                                        </span>

                                    @else

                                        <span class="inline-flex rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700">
                                            Activa
                                        </span>

                                    @endif

                                </td>

                                <td class="px-4 py-3">

                                    <div class="flex justify-center gap-2">

                                        <a
                                            href="{{ route('cotizaciones.show', $quote) }}"
                                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100">
                                            Ver
                                        </a>

                                        <a
                                            href="{{ route('cotizaciones.print', $quote) }}"
                                            target="_blank"
                                            class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                                            Imprimir
                                        </a>

                                        @if($quote->canBeConverted())

                                            <a
                                                href="{{ route('pos.index') }}?quote={{ $quote->id }}"
                                                class="rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-600">
                                                Cobrar
                                            </a>

                                        @endif

                                        @if($quote->canBeCancelled())

                                            <a
                                                href="{{ route('cotizaciones.edit', $quote) }}"
                                                class="rounded-lg border border-red-300 px-3 py-2 text-sm text-red-600 hover:bg-red-50">
                                                Cancelar
                                            </a>

                                        @endif

                                    </div>

                                </td>

                            </tr>

                        @endforeach

                    </tbody>

                </table>

                <div class="mt-5">
                    {{ $quotes->links() }}
                </div>

            @else

                <div class="py-10 text-center text-slate-500">
                    No hay cotizaciones registradas.
                </div>

            @endif

        </div>

    </x-card>

</div>

@endsection
