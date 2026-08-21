@extends('layouts.app')

@section('title', 'Cancelar cotización ' . $quote->quote_number)

@section('content')

<div class="mx-auto max-w-2xl space-y-6">

    <div>
        <h2 class="text-xl font-semibold text-slate-800">
            Cancelar cotización {{ $quote->quote_number }}
        </h2>

        <p class="text-sm text-slate-500">
            La cancelación es permanente y no se puede revertir. No afecta inventario ni pagos.
        </p>
    </div>

    @if($quote->canBeCancelled())

        <x-card>

            <form
                method="POST"
                action="{{ route('cotizaciones.update', $quote) }}"
                x-data="{ submitting: false }"
                @submit="submitting = true">

                @csrf
                @method('PUT')

                <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-700">
                    <p class="font-semibold">Total de la cotización: ₡{{ number_format((float) $quote->total, 2, ',', '.') }}</p>
                    <p>Cliente: {{ $quote->customer?->name ?? 'Consumidor Final' }}</p>
                </div>

                <div>
                    <label for="cancellation_reason" class="mb-1 block text-sm font-semibold text-slate-700">
                        Motivo de cancelación *
                    </label>

                    <textarea
                        id="cancellation_reason"
                        name="cancellation_reason"
                        rows="3"
                        required
                        maxlength="255"
                        placeholder="Indique el motivo por el que se cancela esta cotización"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"></textarea>
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">

                    <a
                        href="{{ route('cotizaciones.show', $quote) }}"
                        class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
                        Volver
                    </a>

                    <button
                        type="submit"
                        :disabled="submitting"
                        class="rounded-lg bg-red-600 px-4 py-2 font-semibold text-white hover:bg-red-700 disabled:opacity-50">
                        Cancelar cotización
                    </button>

                </div>

            </form>

        </x-card>

    @else

        <x-card>

            <div class="py-8 text-center">

                <p class="font-semibold text-slate-800">
                    Esta cotización no puede cancelarse.
                </p>

                <p class="mt-2 text-sm text-slate-500">
                    @if($quote->isConverted())
                        Ya fue convertida en una venta.
                    @elseif($quote->isCancelled())
                        Ya se encuentra cancelada.
                    @else
                        Su estado actual no lo permite.
                    @endif
                </p>

                <a
                    href="{{ route('cotizaciones.show', $quote) }}"
                    class="mt-4 inline-block rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
                    Volver
                </a>

            </div>

        </x-card>

    @endif

</div>

@endsection
