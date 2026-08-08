@extends('layouts.app')

@section('title', 'Detalle de Compra')

@section('description', 'Detalle de la compra registrada.')

@section('content')

<div class="mb-6">

    <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">

        <div>
            <h2 class="text-xl font-semibold text-slate-800">
                Compra {{ $purchase->number }}
            </h2>

            <p class="text-sm text-slate-500">
                Registrada el {{ $purchase->purchase_date?->format('d/m/Y') }}
            </p>
        </div>


        <div class="flex flex-wrap gap-2">

    <a
        href="{{ route('compras.index') }}"
        class="inline-flex h-10 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-100">
        Volver
    </a>


    <a
    href="{{ route('compras.print', $purchase->id) }}"
    target="_blank"
    class="inline-flex h-10 items-center rounded-lg bg-slate-700 px-4 text-sm font-semibold text-white hover:bg-slate-800">
    Imprimir
</a>

    @if($purchase->status === 'posted')

        <a
            href="{{ route('compras.edit', $purchase->id) }}"
            class="inline-flex h-10 items-center rounded-lg bg-amber-500 px-4 text-sm font-semibold text-white hover:bg-amber-600">
            Editar
        </a>

    @endif


    <a
        href="{{ route('compras.pdf', $purchase->id) }}"
        class="inline-flex h-10 items-center rounded-lg bg-blue-500 px-4 text-sm font-semibold text-white hover:bg-blue-600">
        PDF
    </a>


    @if($purchase->status === 'posted')

        <form
            method="POST"
            action="{{ route('compras.destroy', $purchase->id) }}"
            onsubmit="return confirm('¿Está seguro de anular esta compra? El inventario será revertido.');">

            @csrf
            @method('DELETE')

            <button
                type="submit"
                class="inline-flex h-10 items-center rounded-lg bg-red-500 px-4 text-sm font-semibold text-white hover:bg-red-600">
                Anular compra
            </button>

        </form>

    @endif

</div>

    </div>

        <div class="mt-6 grid gap-4 md:grid-cols-3">

        <div class="rounded-xl border border-slate-200 bg-white p-4">

            <p class="text-xs uppercase text-slate-500">
                Proveedor
            </p>

            <p class="mt-1 font-semibold text-slate-800">
                {{ $purchase->supplier?->commercial_name ?: $purchase->supplier?->name }}
            </p>

        </div>


        <div class="rounded-xl border border-slate-200 bg-white p-4">

            <p class="text-xs uppercase text-slate-500">
                Factura proveedor
            </p>

            <p class="mt-1 font-semibold text-slate-800">
                {{ $purchase->supplier_invoice_number ?: '—' }}
            </p>

        </div>


        <div class="rounded-xl border border-slate-200 bg-white p-4">

            <p class="text-xs uppercase text-slate-500">
                Forma de pago
            </p>

            <p class="mt-1 font-semibold text-slate-800">
                {{ $purchase->payment_type === 'credit' ? 'Crédito' : 'Contado' }}
            </p>

        </div>

    </div>

</div>


<x-card>

    <x-slot:header>
        Productos
    </x-slot:header>

        <div class="overflow-x-auto">

        <table class="min-w-full">

            <thead class="bg-slate-50">

                <tr>

                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                        Producto
                    </th>


                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-500">
                        Cantidad
                    </th>


                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-500">
                        Costo
                    </th>


                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-500">
                        Total
                    </th>

                </tr>

            </thead>


            <tbody class="divide-y divide-slate-200">


                @foreach($purchase->items as $item)

                    <tr class="hover:bg-slate-50">


                        <td class="px-4 py-4 font-medium text-slate-800">

                            {{ $item->product?->name }}

                        </td>


                        <td class="px-4 py-4 text-right text-slate-600">

                            {{ $item->quantity }}

                        </td>


                        <td class="px-4 py-4 text-right text-slate-600">

                            ₡{{ number_format($item->unit_cost,2,',','.') }}

                        </td>


                        <td class="px-4 py-4 text-right font-semibold text-slate-800">

                            ₡{{ number_format($item->total,2,',','.') }}

                        </td>


                    </tr>


                @endforeach


            </tbody>


        </table>


    </div>

    </x-card>


<div class="mt-6 flex justify-end">

    <div class="text-right">

        <p class="text-sm text-slate-500">
            Total compra
        </p>


        <p class="text-2xl font-bold text-slate-800">

            ₡{{ number_format($purchase->total,2,',','.') }}

        </p>

    </div>

</div>


<style>

@media print {

    nav,
    aside,
    button,
    a {
        display: none !important;
    }


    body {
        background: white !important;
    }


    .shadow,
    .shadow-sm,
    .border {
        box-shadow: none !important;
    }

}

</style>


@endsection