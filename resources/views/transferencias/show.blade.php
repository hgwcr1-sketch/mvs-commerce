@extends('layouts.app')

@section('content')
<div class="mx-auto min-w-0 max-w-6xl space-y-6">
    <header class="space-y-3">
        <a href="{{ route('transferencias.index') }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-slate-600 hover:text-slate-900">â† Volver a traslados</a>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="break-all text-xl font-bold text-slate-800 sm:text-2xl">Traslado {{ $transfer->transfer_number }}</h1>
            @include('transferencias._status')
        </div>
        @include('transferencias._actions', ['detail' => true])
    </header>
    @include('transferencias._messages')

@if ($errors->has('received_products'))
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 mt-4">
        {{ $errors->first('received_products') }}
    </div>
@endif

    <section class="grid min-w-0 gap-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5 md:grid-cols-2">
        <div class="min-w-0"><h2 class="text-sm font-medium text-slate-500">Sucursal origen</h2><p class="mt-1 break-words font-semibold text-slate-800">{{ $transfer->fromBranch?->name ?? 'Sucursal no disponible' }}</p><p class="text-xs text-slate-500">{{ $transfer->fromBranch?->code }}</p></div>
        <div class="min-w-0"><h2 class="text-sm font-medium text-slate-500">Sucursal destino</h2><p class="mt-1 break-words font-semibold text-slate-800">{{ $transfer->toBranch?->name ?? 'Sucursal no disponible' }}</p><p class="text-xs text-slate-500">{{ $transfer->toBranch?->code }}</p></div>
        <div class="min-w-0 md:col-span-2"><h2 class="text-sm font-medium text-slate-500">Observaciones</h2><p class="mt-1 whitespace-pre-wrap break-words text-sm text-slate-700">{{ $transfer->notes ?: 'Sin observaciones' }}</p></div>
    </section>

    <section class="space-y-4">
        <h2 class="font-semibold text-slate-800">Productos <span class="text-sm font-normal text-slate-500">({{ $transfer->items->count() }})</span></h2>
        @forelse($transfer->items as $item)
            <article class="grid min-w-0 gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                <div class="min-w-0">
                    <h3 class="break-words font-semibold text-slate-800">{{ $item->product?->name ?? 'Producto no disponible' }}</h3>
                    <p class="mt-1 break-words text-xs text-slate-500">SKU: {{ $item->product?->internal_code ?? 'Sin cÃ³digo' }}</p>
                    @if($item->item_notes)<p class="mt-2 break-words text-sm text-slate-600">{{ $item->item_notes }}</p>@endif
                </div>
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-slate-500">Solicitado</dt><dd class="mt-1 break-all font-medium tabular-nums">{{ $item->quantity }}</dd></div>
                    <div><dt class="text-slate-500">Enviado</dt><dd class="mt-1 break-all font-medium tabular-nums">{{ $item->sent_quantity ?? ($transfer->isCompleted() ? $item->quantity : 'â€”') }}</dd></div>
                    <div><dt class="text-slate-500">Recibido</dt><dd class="mt-1 break-all font-medium tabular-nums">{{ $item->received_quantity ?? 'â€”' }}</dd></div>
                    <div>
                        <dt class="text-slate-500">Diferencia</dt>
                        <dd class="mt-1 break-words font-semibold">
                            @if($item->received_quantity === null)
                                <span class="text-slate-500">Sin registro</span>
                            @elseif($item->isQuantityExact())
                                <span class="text-emerald-700">Exacta</span>
                            @else
                                <span class="text-amber-800">{{ $item->isQuantityShort() ? 'Faltante' : 'Sobrante' }}: {{ ltrim((string) $item->difference, '-') }}</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </article>
        @empty
            <p class="rounded-xl bg-white p-5 text-sm text-slate-500">Este traslado no tiene productos registrados.</p>
        @endforelse
        @if($transfer->isCompleted())
            <p class="text-sm text-slate-500">Traslado histÃ³rico completado mediante transferencia inmediata. Los datos de recepciÃ³n se muestran solo si fueron registrados.</p>
        @endif
    </section>

    @if($transfer->isInReview())
        <section id="recepcion" class="scroll-mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" x-data="transferReceipt">
            <h2 class="font-semibold text-slate-800">RecepciÃ³n por producto</h2>
            <p class="mt-1 text-sm text-slate-500">Cuente los productos e indique la cantidad que realmente recibiÃ³.</p>
            <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                @if($transfer->items->count() > 1)
                    Por ahora solo se puede confirmar este traslado si todas las cantidades coinciden con lo enviado. Las diferencias por producto pueden revisarse aquÃ­, pero todavÃ­a no se pueden confirmar.
                @else
                    Puede confirmar una cantidad exacta, un faltante o un sobrante.
                @endif
                La recepciÃ³n en cero todavÃ­a no estÃ¡ disponible. Las cantidades no se guardan hasta confirmar.
            </p>

            <form action="{{ route('transferencias.receive', $transfer) }}" method="POST" class="mt-4 space-y-4">
                @csrf
                @foreach($transfer->items as $item)
                    @php
                        $receivedField = 'received_products.'.$item->product_id.'.quantity';
                        $receivedValue = old($receivedField, '');
                        $receivedValue = is_scalar($receivedValue) ? (string) $receivedValue : '';
                        $sentValue = (string) ($item->sent_quantity ?? $item->quantity);
                    @endphp
                    <div class="grid min-w-0 gap-3 rounded-xl border border-slate-200 p-3 md:grid-cols-2" x-data="{ received: {{ Illuminate\Support\Js::from($receivedValue) }}, sent: {{ Illuminate\Support\Js::from($sentValue) }} }">
                        <div class="min-w-0">
                            <h3 class="break-words text-sm font-semibold text-slate-800">{{ $item->product?->name ?? 'Producto no disponible' }}</h3>
                            <p class="break-words text-xs text-slate-500">{{ $item->product?->internal_code ?? 'Sin cÃ³digo' }}</p>
                            <p class="mt-2 text-sm text-slate-600">Enviado: <strong class="tabular-nums">{{ $sentValue }}</strong></p>
                        </div>
                        <div class="min-w-0">
                            <input type="hidden" name="received_products[{{ $item->product_id }}][product_id]" value="{{ $item->product_id }}">
                            <label for="received-{{ $item->id }}" class="mb-1 block text-sm font-medium text-slate-700">Cantidad recibida</label>
                            <input id="received-{{ $item->id }}" name="received_products[{{ $item->product_id }}][quantity]" value="{{ $receivedValue }}" x-model="received" type="number" required min="0" step="0.0001" inputmode="decimal" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2 text-right text-sm tabular-nums focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                <span x-cloak class="text-sm font-semibold" :class="tone(received, sent)" x-text="differenceLabel(received, sent)" aria-live="polite"></span>
                                <button x-cloak type="button" @click="received = sent" class="min-h-11 rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700">Usar cantidad enviada</button>
                            </div>
                            @error($receivedField)<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                        </div>
                    </div>
                @endforeach
                <div>
                    <label for="receipt-notes" class="mb-2 block text-sm font-medium text-slate-700">Observaciones de recepciÃ³n (opcional)</label>
                    <textarea name="notes" id="receipt-notes" maxlength="1000" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-3 text-sm">{{ old('notes') }}</textarea>
                </div>
                <div class="sticky bottom-0 z-10 flex justify-end rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                    <button type="submit" class="min-h-11 rounded-xl bg-amber-500 px-5 py-3 text-sm font-semibold text-slate-950 hover:bg-amber-600">Confirmar recepciÃ³n</button>
                </div>
            </form>
        </section>
    @elseif($transfer->isInTransit())
        <p class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">El traslado estÃ¡ en trÃ¡nsito. Inicie la revisiÃ³n para ingresar las cantidades recibidas.</p>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <h2 class="mb-4 font-semibold text-slate-800">Responsables y fechas</h2>
        <dl class="grid min-w-0 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            @foreach([
                ['CreaciÃ³n', $transfer->user?->name, $transfer->created_at],
                ['PreparaciÃ³n', $transfer->preparer?->name, $transfer->prepared_at],
                ['Despacho', $dispatcher?->name, $transfer->dispatched_at],
                ['RecepciÃ³n', $transfer->receiver?->name, $transfer->received_at],
                ['ConfirmaciÃ³n', $transfer->confirmer?->name, $transfer->confirmed_at],
                ['Transferencia histÃ³rica', null, $transfer->transferred_at],
            ] as [$label, $person, $date])
                @if($date || $person)
                    <div class="min-w-0">
                        <dt class="text-slate-500">{{ $label }}</dt>
                        <dd class="mt-1 break-words font-medium text-slate-800">{{ $person ?? 'Sin responsable registrado' }}</dd>
                        <dd class="mt-1 text-xs text-slate-500">{{ $date ? Illuminate\Support\Carbon::parse($date)->format('d/m/Y H:i') : 'Sin fecha registrada' }}</dd>
                    </div>
                @endif
            @endforeach
        </dl>
    </section>
</div>
@endsection
