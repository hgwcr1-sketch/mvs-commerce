@extends('layouts.app')

@section('title', 'Cotización ' . $quote->quote_number)

@section('content')

<div class="space-y-6">

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-slate-800">
                Cotización {{ $quote->quote_number }}
            </h2>

            <p class="text-sm text-slate-500">
                Creada por {{ $quote->user?->name }} el {{ optional($quote->created_at)->format('d/m/Y H:i') }}
            </p>
        </div>

        <div class="flex gap-2">
            <a
                href="{{ route('cotizaciones.index') }}"
                class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
                Volver
            </a>

            <a
                href="{{ route('cotizaciones.print', $quote) }}"
                target="_blank"
                class="rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-800">
                Imprimir
            </a>
        </div>
    </div>

    <x-card>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">

            <div>
                <p class="text-xs uppercase text-slate-500">Estado</p>
                <p class="font-semibold text-slate-800">
                    @if($quote->isExpired())
                        Vencida
                    @elseif($quote->isConverted())
                        Convertida
                    @elseif($quote->isCancelled())
                        Cancelada
                    @else
                        Activa
                    @endif
                </p>
            </div>

            <div>
                <p class="text-xs uppercase text-slate-500">Cliente</p>
                <p class="font-semibold text-slate-800">{{ $quote->customer?->name ?? 'Consumidor Final' }}</p>
            </div>

            <div>
                <p class="text-xs uppercase text-slate-500">Vence</p>
                <p class="font-semibold text-slate-800">{{ $quote->expires_at ? optional($quote->expires_at)->format('d/m/Y') : 'Sin vencimiento' }}</p>
            </div>

        </div>

        @if($quote->cancelled)

            <div class="mt-4 rounded-lg bg-red-50 p-4 text-sm text-red-700">
                <p class="font-semibold">Cancelada el {{ optional($quote->cancelled_at)->format('d/m/Y H:i') }} por {{ $quote->cancelledBy?->name }}</p>
                <p>Motivo: {{ $quote->cancellation_reason }}</p>
            </div>

        @elseif($quote->converted)

            <div class="mt-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">
                <p class="font-semibold">Convertida en la venta {{ $quote->convertedSale?->sale_number }} el {{ optional($quote->converted_at)->format('d/m/Y H:i') }}</p>
            </div>

        @endif

        @if($quote->notes)
            <div class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-700">
                {{ $quote->notes }}
            </div>
        @endif

    </x-card>

    <x-card>

        <div class="overflow-x-auto">

            <table class="min-w-full divide-y divide-slate-200">

                <thead class="bg-slate-100 text-xs uppercase text-slate-600">
                    <tr>
                        <th class="px-4 py-3 text-left">Producto</th>
                        <th class="px-4 py-3 text-right">Cantidad</th>
                        <th class="px-4 py-3 text-right">Precio</th>
                        <th class="px-4 py-3 text-right">Descuento</th>
                        <th class="px-4 py-3 text-right">Impuesto</th>
                        <th class="px-4 py-3 text-right">Total</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">

                    @foreach($quote->items as $item)

                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-semibold text-slate-800">{{ $item->description }}</p>
                                <p class="text-xs text-slate-500">Código: {{ $item->product_code ?? '—' }}</p>
                            </td>
                            <td class="px-4 py-3 text-right">{{ number_format((float) $item->quantity, 4, '.', '') }}</td>
                            <td class="px-4 py-3 text-right">₡{{ number_format((float) $item->unit_price, 2, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right">₡{{ number_format((float) $item->discount_total, 2, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right">₡{{ number_format((float) $item->tax_total, 2, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-800">₡{{ number_format((float) $item->total, 2, ',', '.') }}</td>
                        </tr>

                    @endforeach

                </tbody>

            </table>

        </div>

        <div class="mt-5 flex flex-col items-end gap-1 border-t border-slate-200 pt-4 text-sm">

            <div class="flex w-full max-w-xs justify-between">
                <span class="text-slate-500">Subtotal</span>
                <span>₡{{ number_format((float) $quote->subtotal, 2, ',', '.') }}</span>
            </div>

            <div class="flex w-full max-w-xs justify-between">
                <span class="text-slate-500">Descuentos</span>
                <span>₡{{ number_format((float) $quote->discount_total, 2, ',', '.') }}</span>
            </div>

            <div class="flex w-full max-w-xs justify-between">
                <span class="text-slate-500">Impuestos</span>
                <span>₡{{ number_format((float) $quote->tax_total, 2, ',', '.') }}</span>
            </div>

            <div class="flex w-full max-w-xs justify-between text-base font-bold text-slate-800">
                <span>Total</span>
                <span>₡{{ number_format((float) $quote->total, 2, ',', '.') }}</span>
            </div>

        </div>

    </x-card>

    @if($quote->canBeCancelled())

        <div class="flex justify-end">
            <a
                href="{{ route('cotizaciones.edit', $quote) }}"
                class="rounded-lg border border-red-300 px-4 py-2 font-semibold text-red-600 hover:bg-red-50">
                Cancelar cotización
            </a>
        </div>

    @endif

</div>

@endsection
