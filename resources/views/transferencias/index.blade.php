@extends('layouts.app')

@section('title', 'Transferencias')

@section('content')

<div class="space-y-6">

    <div class="flex items-center justify-between">

        <div>
            <h1 class="text-2xl font-bold text-slate-800">
                Transferencias de Inventario
            </h1>

            <p class="mt-1 text-sm text-slate-500">
                Movimientos de inventario entre sucursales.
            </p>
        </div>

        <a
            href="{{ route('transferencias.create') }}"
            class="rounded-xl bg-amber-500 px-5 py-3 font-semibold text-white hover:bg-amber-600">

            + Nueva Transferencia

        </a>

    </div>

    @if(session('success'))

        <div class="rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-green-700">
            {{ session('success') }}
        </div>

    @endif

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

        <div class="overflow-x-auto">

            <table class="min-w-full">

                <thead class="bg-slate-50">

                    <tr>
                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">
                            Número
                        </th>

                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">
                            Fecha
                        </th>

                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">
                            Origen
                        </th>

                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">
                            Destino
                        </th>

                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">
                            Productos
                        </th>

                        <th class="px-5 py-4 text-left text-sm font-semibold text-slate-600">
                            Usuario
                        </th>

                        <th class="px-5 py-4 text-center text-sm font-semibold text-slate-600">
                            Estado
                        </th>
                    </tr>

                </thead>

                <tbody class="divide-y divide-slate-200">

                    @forelse($transfers as $transfer)

                        <tr class="hover:bg-slate-50">

                            <td class="whitespace-nowrap px-5 py-4 font-semibold text-slate-800">
                                {{ $transfer->transfer_number }}
                            </td>

                            <td class="whitespace-nowrap px-5 py-4 text-sm text-slate-600">

                                {{ $transfer->transferred_at
                                    ? $transfer->transferred_at->format('d/m/Y H:i')
                                    : '-' }}

                            </td>

                            <td class="px-5 py-4 text-slate-700">
                                {{ $transfer->fromBranch->name ?? '-' }}
                            </td>

                            <td class="px-5 py-4 text-slate-700">
                                {{ $transfer->toBranch->name ?? '-' }}
                            </td>

                            <td class="px-5 py-4">

                                @foreach($transfer->items as $item)

                                    <div class="mb-1 text-sm">

                                        <span class="font-semibold text-slate-800">
                                            {{ $item->product->name ?? 'Producto eliminado' }}
                                        </span>

                                        <span class="text-slate-500">
                                            × {{ number_format($item->quantity, 2) }}
                                        </span>

                                    </div>

                                @endforeach

                            </td>

                            <td class="px-5 py-4 text-sm text-slate-600">
                                {{ $transfer->user->name ?? 'Sistema' }}
                            </td>

                            <td class="px-5 py-4 text-center">

                                @if($transfer->status === 'completed')

                                    <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700">
                                        Completada
                                    </span>

                                @else

                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                                        {{ ucfirst($transfer->status) }}
                                    </span>

                                @endif

                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td
                                colspan="7"
                                class="px-6 py-12 text-center text-slate-400">

                                No existen transferencias registradas.

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

    </div>

    {{ $transfers->links() }}

</div>

@endsection