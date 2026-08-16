@extends('layouts.app')

@section('title', 'Detalle de venta')

@section('description', 'Detalle completo de la venta seleccionada.')

@section('content')

<div class="space-y-6">

@if(session('success'))
    <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-green-800">
        {{ session('success') }}
    </div>
@endif

@if($errors->any())
    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800">
        {{ $errors->first() }}
    </div>
@endif

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

        <div>
            <h2 class="text-xl font-semibold text-slate-800">
                Venta {{ $sale->sale_number }}
            </h2>

            <p class="text-sm text-slate-500">
                Detalle completo de la venta registrada.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">

            <a
                href="{{ route('ventas.index') }}"
                class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
                Volver
            </a>

            <a
                href="{{ route('pos.receipt', $sale) }}"
                target="_blank"
                class="rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-800">
                Reimprimir comprobante
            </a>

        </div>

    </div>

    <x-card>

    @can('ventas.anular')
    @if($sale->status === \App\Models\Sale::STATUS_COMPLETED)

        <form
            method="POST"
            action="{{ route('ventas.void', $sale) }}"
            onsubmit="return confirm('¿Está seguro de anular esta venta? Esta acción devolverá el inventario y anulará los pagos.');">

            @csrf

            <input
                type="text"
                name="reason"
                required
                minlength="3"
                maxlength="255"
                placeholder="Motivo de anulación"
                class="rounded-lg border border-red-300 px-3 py-2 text-sm">

            <button
                type="submit"
                class="rounded-lg bg-red-600 px-4 py-2 font-semibold text-white hover:bg-red-700">
                Anular venta
            </button>

        </form>

    @endif
@endcan

        <x-slot:header>
            <h3 class="text-lg font-semibold text-slate-800">
                Información de la venta
            </h3>
        </x-slot:header>

        <div class="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-4">

            <div>
                <p class="text-xs font-semibold uppercase text-slate-500">
                    Número
                </p>
                <p class="mt-1 font-semibold text-slate-800">
                    {{ $sale->sale_number }}
                </p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase text-slate-500">
                    Fecha
                </p>
                <p class="mt-1 text-slate-800">
                    {{ $sale->completed_at?->format('d/m/Y H:i') ?: '—' }}
                </p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase text-slate-500">
                    Documento
                </p>
                <p class="mt-1 text-slate-800">
                    {{ $sale->document_type === \App\Models\Sale::DOCUMENT_ELECTRONIC_INVOICE
                        ? 'Factura electrónica'
                        : 'Tiquete electrónico' }}
                </p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase text-slate-500">
                    Estado
                </p>

                <p class="mt-1">
                    @if($sale->status === \App\Models\Sale::STATUS_COMPLETED)
                        <span class="inline-flex rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700">
                            Completada
                        </span>
                    @elseif($sale->status === \App\Models\Sale::STATUS_VOIDED)
                        <span class="inline-flex rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">
                            Anulada
                        </span>
                    @elseif($sale->status === \App\Models\Sale::STATUS_PARTIALLY_RETURNED)
                        <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">
                            Devolución parcial
                        </span>
                    @elseif($sale->status === \App\Models\Sale::STATUS_RETURNED)
                        <span class="inline-flex rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700">
                            Devuelta
                        </span>
                    @else
                        <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                            {{ $sale->status }}
                        </span>
                    @endif
                </p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase text-slate-500">
                    Cliente
                </p>
                <p class="mt-1 text-slate-800">
                    {{ $sale->customer?->name ?: 'Consumidor Final' }}
                </p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase text-slate-500">
                    Cajero
                </p>
                <p class="mt-1 text-slate-800">
                    {{ $sale->user?->name ?: '—' }}
                </p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase text-slate-500">
                    Sucursal
                </p>
                <p class="mt-1 text-slate-800">
                    {{ $sale->branch?->name ?: '—' }}
                </p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase text-slate-500">
                    Sesión de caja
                </p>
                <p class="mt-1 text-slate-800">
                    {{ $sale->cashSession?->session_number ?: 'Sin sesión de caja' }}
                </p>
            </div>

        </div>

    </x-card>

    <x-card>

        <x-slot:header>
            <h3 class="text-lg font-semibold text-slate-800">
                Productos
            </h3>
        </x-slot:header>

        <div class="overflow-x-auto">

            <table class="min-w-full divide-y divide-slate-200">

                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                            Producto
                        </th>

                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                            Cantidad
                        </th>

                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                            Precio
                        </th>

                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                            Descuento
                        </th>

                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                            Impuesto
                        </th>

                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                            Total
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100 bg-white">

                    @foreach($sale->items as $item)

                        <tr>

                            <td class="px-4 py-3 text-sm text-slate-800">
                                <div class="font-semibold">
                                    {{ $item->description }}
                                </div>

                                <div class="text-xs text-slate-500">
                                    {{ $item->product_code }}
                                </div>
                            </td>

                            <td class="px-4 py-3 text-right text-sm text-slate-700">
                                {{ rtrim(rtrim(number_format((float) $item->quantity, 4, ',', '.'), '0'), ',') }}
                            </td>

                            <td class="px-4 py-3 text-right text-sm text-slate-700">
                                ₡{{ number_format((float) $item->unit_price, 2, ',', '.') }}
                            </td>

                            <td class="px-4 py-3 text-right text-sm text-slate-700">
                                ₡{{ number_format((float) $item->discount_total, 2, ',', '.') }}
                            </td>

                            <td class="px-4 py-3 text-right text-sm text-slate-700">
                                ₡{{ number_format((float) $item->tax_total, 2, ',', '.') }}
                            </td>

                            <td class="px-4 py-3 text-right font-semibold text-slate-800">
                                ₡{{ number_format((float) $item->total, 2, ',', '.') }}
                            </td>

                        </tr>

                    @endforeach

                </tbody>

            </table>

        </div>

    </x-card>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        <x-card>

            <x-slot:header>
                <h3 class="text-lg font-semibold text-slate-800">
                    Formas de pago
                </h3>
            </x-slot:header>

            <div class="space-y-3">

                @foreach($sale->payments as $payment)

                    <div class="rounded-xl border border-slate-200 p-4">

                        <div class="flex items-center justify-between gap-4">
                            <span class="font-semibold text-slate-800">
                                {{ $payment->paymentMethod?->name ?: 'Método de pago' }}
                            </span>

                            <span class="font-semibold text-slate-800">
                                ₡{{ number_format((float) $payment->amount, 2, ',', '.') }}
                            </span>
                        </div>

                        @if($payment->reference)
                            <p class="mt-2 text-sm text-slate-500">
                                Referencia: {{ $payment->reference }}
                            </p>
                        @endif

                        @if($payment->paymentMethod?->allows_change)

                            <div class="mt-2 text-sm text-slate-500">
                                <p>
                                    Recibido:
                                    ₡{{ number_format((float) $payment->received_amount, 2, ',', '.') }}
                                </p>

                                <p>
                                    Vuelto:
                                    ₡{{ number_format((float) $payment->change_amount, 2, ',', '.') }}
                                </p>
                            </div>

                        @endif

                    </div>

                @endforeach

            </div>

        </x-card>

        <x-card>

            <x-slot:header>
                <h3 class="text-lg font-semibold text-slate-800">
                    Totales
                </h3>
            </x-slot:header>

            <div class="space-y-3">

                <div class="flex justify-between">
                    <span class="text-slate-600">
                        Subtotal
                    </span>

                    <strong class="text-slate-800">
                        ₡{{ number_format((float) $sale->subtotal, 2, ',', '.') }}
                    </strong>
                </div>

                @if((float) $sale->discount_total > 0)

                    <div class="flex justify-between">
                        <span class="text-slate-600">
                            Descuento
                        </span>

                        <strong class="text-amber-700">
                            -₡{{ number_format((float) $sale->discount_total, 2, ',', '.') }}
                        </strong>
                    </div>

                @endif

                <div class="flex justify-between">
                    <span class="text-slate-600">
                        Impuesto
                    </span>

                    <strong class="text-slate-800">
                        ₡{{ number_format((float) $sale->tax_total, 2, ',', '.') }}
                    </strong>
                </div>

                @if((float) $sale->rounding_total !== 0.0)

                    <div class="flex justify-between">
                        <span class="text-slate-600">
                            Redondeo
                        </span>

                        <strong class="text-slate-800">
                            ₡{{ number_format((float) $sale->rounding_total, 2, ',', '.') }}
                        </strong>
                    </div>

                @endif

                <div class="border-t border-slate-200 pt-3">

                    <div class="flex justify-between text-lg">
                        <span class="font-semibold text-slate-800">
                            Total
                        </span>

                        <strong class="text-xl text-slate-900">
                            ₡{{ number_format((float) $sale->total, 2, ',', '.') }}
                        </strong>
                    </div>

                </div>

            </div>

        </x-card>

    </div>

</div>

@endsection