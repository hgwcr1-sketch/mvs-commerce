@extends('layouts.app')

@section('title', 'Devolver productos')

@section('description', 'Registrar la devolución de mercancía de una venta.')

@section('content')

<div class="space-y-6">

@if($errors->any())
    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800">
        {{ $errors->first() }}
    </div>
@endif

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

        <div>
            <h2 class="text-xl font-semibold text-slate-800">
                Devolución de la venta {{ $sale->sale_number }}
            </h2>

            <p class="text-sm text-slate-500">
                Seleccione los productos y las cantidades a devolver. Esta acción regresa el inventario a la sucursal sin generar reembolso de caja.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">

            <a
                href="{{ route('ventas.show', $sale) }}"
                class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
                Volver al detalle
            </a>

        </div>

    </div>

    <form
        method="POST"
        action="{{ route('ventas.return.store', $sale) }}"
        onsubmit="return confirm('¿Confirma el registro de esta devolución de mercancía?');">

        @csrf

        <x-card>

            <x-slot:header>
                <h3 class="text-lg font-semibold text-slate-800">
                    Productos a devolver
                </h3>
            </x-slot:header>

            <div class="overflow-x-auto">

                <table class="min-w-full divide-y divide-slate-200">

                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Producto</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-500">Vendido</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-500">Ya devuelto</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-500">Pendiente</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-500">Cantidad a devolver</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200">

                        @forelse($lines as $line)
<tr>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-slate-800">
                                        {{ $line['item']->description }}
                                    </p>
                                    <p class="text-xs text-slate-500">
                                        {{ $line['item']->product_code }}
                                    </p>
                                </td>

                                <td class="px-4 py-3 text-right text-sm text-slate-700">
                                    {{ rtrim(rtrim(number_format($line['sold'], 4, ',', '.'), '0'), ',') }}
                                </td>

                                <td class="px-4 py-3 text-right text-sm text-slate-700">
                                    {{ rtrim(rtrim(number_format($line['returned'], 4, ',', '.'), '0'), ',') }}
                                </td>

                                <td class="px-4 py-3 text-right text-sm font-semibold text-slate-800">
                                    {{ rtrim(rtrim(number_format($line['pending'], 4, ',', '.'), '0'), ',') }}
                                </td>

                                <td class="px-4 py-3 text-right">
                                    @if($line['pending'] > 0)

                                        <input
                                            type="number"
                                            name="items[{{ $loop->index }}][quantity]"
                                            min="{{ $line['allows_decimals'] ? '0.0001' : '1' }}"
                                            max="{{ $line['pending'] }}"
                                            step="{{ $line['allows_decimals'] ? '0.0001' : '1' }}"
                                            value=""
                                            placeholder="0"
                                            class="w-28 rounded-lg border border-slate-300 px-3 py-1 text-right">

                                        <input
                                            type="hidden"
                                            name="items[{{ $loop->index }}][sale_item_id]"
                                            value="{{ $line['item']->id }}">

                                    @else

                                        <span class="text-xs text-slate-400">Sin stock pendiente</span>

                                    @endif
                                </td>
                            </tr>

                        @empty

                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-slate-500">
                                    Esta venta ya no tiene productos disponibles para devolver.
                                </td>
                            </tr>

                        @endforelse

                    </tbody>

                </table>

            </div>

        </x-card>

        <x-card>

            <div class="space-y-4">

                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700">
                        Motivo de la devolución <span class="text-red-600">*</span>
                    </label>

                    <textarea
                        name="reason"
                        required
                        minlength="3"
                        maxlength="255"
                        rows="3"
                        placeholder="Describa el motivo de la devolución"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"></textarea>
                </div>

                <button
                    type="submit"
                    class="rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-800">
                    Registrar devolución
                </button>

            </div>

        </x-card>

    </form>

</div>

@endsection
