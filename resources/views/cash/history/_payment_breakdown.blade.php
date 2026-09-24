@php
    $moneyCrc = fn ($value) => '₡'.number_format((float) $value, 2, ',', '.');
    $moneyUsd = fn ($value) => '$'.number_format((float) $value, 2, '.', ',');
    $typeLabels = [
        \App\Models\PaymentMethod::TYPE_CASH => 'Efectivo',
        \App\Models\PaymentMethod::TYPE_CARD => 'Tarjeta',
        \App\Models\PaymentMethod::TYPE_SINPE => 'SINPE',
        \App\Models\PaymentMethod::TYPE_BANK_TRANSFER => 'Transferencia',
        \App\Models\PaymentMethod::TYPE_OTHER => 'Otro',
        \App\Models\PaymentMethod::TYPE_CREDIT => 'Crédito',
        \App\Models\PaymentMethod::TYPE_LOYALTY_POINTS => 'Puntos',
    ];
    $allBuckets = collect($breakdown['monetary'])->merge($breakdown['non_monetary']);
    $detailsByMethod = collect($breakdown['details'])->groupBy('payment_method_id');
    $hasUsd = (float) $breakdown['collected_usd'] > 0 || $allBuckets->contains(fn ($bucket) => (float) $bucket['total_usd'] > 0);
@endphp

<x-card x-data="{ openMethod: null }">
    <x-slot:header>
        <h3 class="font-semibold">Totales por medio de pago</h3>
    </x-slot:header>

    <p class="mb-3 text-lg font-semibold">
        Cobrado monetario: {{ $moneyCrc($breakdown['collected_crc']) }}
        @if($hasUsd)
            · {{ $moneyUsd($breakdown['collected_usd']) }}
        @endif
    </p>

    <div class="overflow-x-auto">
        <table class="min-w-full">
            <thead>
                <tr class="border-b bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <th class="p-3">Medio</th>
                    <th class="p-3">Tipo</th>
                    <th class="p-3 text-right">Total CRC</th>
                    @if($hasUsd)
                        <th class="p-3 text-right">Total USD</th>
                    @endif
                    <th class="p-3 text-right">Pagos</th>
                    <th class="p-3 text-center">Detalle</th>
                </tr>
            </thead>
            <tbody>
                @forelse($allBuckets as $bucket)
                    <tr class="border-b">
                        <td class="p-3 font-medium">{{ $bucket['name'] }}</td>
                        <td class="p-3 text-sm text-slate-600">
                            {{ $typeLabels[$bucket['type']] ?? $bucket['type'] }}
                            @if(in_array($bucket['type'], [\App\Models\PaymentMethod::TYPE_CREDIT, \App\Models\PaymentMethod::TYPE_LOYALTY_POINTS], true))
                                <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs">No monetario</span>
                            @endif
                        </td>
                        <td class="p-3 text-right">{{ $moneyCrc($bucket['total_crc']) }}</td>
                        @if($hasUsd)
                            <td class="p-3 text-right">{{ (float) $bucket['total_usd'] > 0 ? $moneyUsd($bucket['total_usd']) : '—' }}</td>
                        @endif
                        <td class="p-3 text-right">{{ $bucket['payments_count'] }}</td>
                        <td class="p-3 text-center">
                            <button
                                type="button"
                                @click="openMethod = {{ $bucket['payment_method_id'] }}"
                                class="min-h-11 rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                            >
                                Ver ventas
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $hasUsd ? 7 : 6 }}" class="p-4 text-center text-slate-500">Sin pagos registrados en la sesión.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @foreach($allBuckets as $bucket)
        @php($rows = $detailsByMethod->get($bucket['payment_method_id'], collect()))
        <div
            x-cloak
            x-show="openMethod === {{ $bucket['payment_method_id'] }}"
            x-transition
            class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4"
            @keydown.escape.window="openMethod = null"
        >
            <div
                class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl"
                @click.outside="openMethod = null"
            >
                <div class="flex items-center justify-between border-b px-4 py-3">
                    <h4 class="text-lg font-semibold">
                        {{ $bucket['name'] }} — {{ $moneyCrc($bucket['total_crc']) }}
                        @if($hasUsd && (float) $bucket['total_usd'] > 0)
                            · {{ $moneyUsd($bucket['total_usd']) }}
                        @endif
                    </h4>
                    <button
                        type="button"
                        @click="openMethod = null"
                        class="flex h-11 w-11 items-center justify-center rounded-lg text-2xl leading-none text-slate-500 hover:bg-slate-100"
                        aria-label="Cerrar"
                    >&times;</button>
                </div>

                <div class="overflow-x-auto p-3 sm:p-4">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <th class="p-2">Venta</th>
                                <th class="p-2">Fecha/hora</th>
                                <th class="p-2">Cliente</th>
                                <th class="p-2">Cajero</th>
                                <th class="p-2 text-right">Total factura</th>
                                <th class="p-2 text-right">Pagado este medio</th>
                                <th class="p-2">Referencia</th>
                                <th class="p-2 text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                <tr class="border-b align-top">
                                    <td class="p-2 font-medium">{{ $row['sale_number'] }}</td>
                                    <td class="p-2">{{ $row['sold_at']?->timezone($companyTimezone)->format('d/m/Y H:i') }}</td>
                                    <td class="p-2">{{ $row['customer_name'] }}</td>
                                    <td class="p-2">{{ $row['cashier_name'] }}</td>
                                    <td class="p-2 text-right">{{ $moneyCrc($row['sale_total']) }}</td>
                                    <td class="p-2 text-right">
                                        {{ $moneyCrc($row['amount_crc']) }}
                                        @if($hasUsd && $row['amount_usd'] !== null && (float) $row['amount_usd'] > 0)
                                            <span class="block text-xs text-slate-500">{{ $moneyUsd($row['amount_usd']) }}</span>
                                        @endif
                                    </td>
                                    <td class="p-2">{{ $row['reference'] ?? '—' }}</td>
                                    <td class="p-2 text-center">
                                        <div class="flex flex-col items-center gap-2">
                                            <a
                                                href="{{ route('ventas.show', $row['sale_id']) }}"
                                                class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                                            >Ver</a>
                                            @if($sale = \App\Models\Sale::find($row['sale_id']))
                                                <x-mvs-print.reprint :sale="$sale" />
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="p-4 text-center text-slate-500">Sin ventas para este medio.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
</x-card>
