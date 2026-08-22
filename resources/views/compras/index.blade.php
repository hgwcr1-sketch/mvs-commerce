@extends('layouts.app')

@section('title', 'Compras')

@section('description', 'Administración y registro de compras.')

@section('content')

<div class="space-y-6">

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

        <div>
            <h2 class="text-xl font-semibold text-slate-800">
                Compras
            </h2>

            <p class="text-sm text-slate-500">
                Consulte y registre las compras de la sucursal activa.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-4">

            <a
                href="{{ route('dashboard') }}"
                class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
                Volver
            </a>

            <a
                href="{{ route('compras.create') }}"
                class="rounded-lg bg-amber-500 px-4 py-2 font-semibold text-white hover:bg-amber-600">
                + Nueva Compra
            </a>

            <a
                href="{{ route('compras.import.template') }}"
                class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700">
                ↓ Descargar plantilla
            </a>

            <form
                action="{{ route('compras.import.excel') }}"
                method="POST"
                enctype="multipart/form-data">

                @csrf

                <input
                    type="file"
                    name="file"
                    id="archivoExcel"
                    accept=".xlsx,.xls"
                    class="hidden"
                    onchange="if (this.files.length > 0) this.form.submit()">

                <label
                    for="archivoExcel"
                    class="cursor-pointer rounded-lg bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">
                    Importar Excel
                </label>

            </form>

            <form
                action="{{ route('compras.import.xml') }}"
                method="POST"
                enctype="multipart/form-data">

                @csrf

                <input
                    type="file"
                    name="file"
                    id="archivoXml"
                    accept=".xml,text/xml,application/xml"
                    class="hidden"
                    onchange="if (this.files.length > 0) this.form.submit()">

                <label
                    for="archivoXml"
                    class="cursor-pointer rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700">
                    Importar XML
                </label>

            </form>

        </div>

    </div>

    @if(session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800">
            {{ session('error') }}
        </div>
    @endif

    @error('file')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800">
            {{ $message }}
        </div>
    @enderror

    <x-card>

        @if($purchases->count())

            <div class="overflow-x-auto">

                <table class="min-w-full divide-y divide-slate-200">

                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                                Número
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                                Fecha
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                                Proveedor
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                                Factura proveedor
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                                Total
                            </th>

                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-600">
                                Estado
                            </th>

                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-600">
                                Acciones
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100 bg-white">

                        @foreach($purchases as $purchase)

                            <tr>

                                <td class="px-4 py-3 font-medium text-slate-800">
                                    {{ $purchase->number }}
                                </td>

                                <td class="px-4 py-3 text-sm text-slate-600">
                                    {{ $purchase->purchase_date?->format('d/m/Y') }}
                                </td>

                                <td class="px-4 py-3 text-sm text-slate-700">
                                    {{ $purchase->supplier?->commercial_name ?: $purchase->supplier?->name }}
                                </td>

                                <td class="px-4 py-3 text-sm text-slate-600">
                                    {{ $purchase->supplier_invoice_number ?: '—' }}
                                </td>

                                <td class="px-4 py-3 text-right font-semibold text-slate-800">
                                    ₡{{ number_format((float) $purchase->total, 0, ',', '.') }}
                                </td>

                                <td class="px-4 py-3 text-center text-sm">

                                    @if($purchase->status === 'posted')

                                        <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">
                                            Registrada
                                        </span>

                                    @elseif($purchase->status === 'cancelled')

                                        <span class="inline-flex rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">
                                            Anulada
                                        </span>

                                    @else

                                        <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                                            {{ ucfirst($purchase->status) }}
                                        </span>

                                    @endif

                                </td>

                                <td class="px-4 py-3 text-center">

                                    <div class="flex justify-center gap-2">

                                        <a
                                            href="{{ route('compras.show', $purchase->id) }}"
                                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100">
                                            Ver
                                        </a>

                                        @if($purchase->status === 'posted')

                                            <a
                                                href="{{ route('compras.edit', $purchase->id) }}"
                                                class="rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-600">
                                                Editar
                                            </a>

                                        @endif

                                    </div>

                                </td>

                            </tr>

                        @endforeach

                    </tbody>

                </table>

            </div>

            <div class="mt-5">
                {{ $purchases->links() }}
            </div>

        @else

            <div class="py-10 text-center">

                <p class="font-medium text-slate-700">
                    Todavía no hay compras registradas.
                </p>

                <p class="mt-1 text-sm text-slate-500">
                    Registre la primera compra para comenzar.
                </p>

            </div>

        @endif

    </x-card>

</div>

@endsection
