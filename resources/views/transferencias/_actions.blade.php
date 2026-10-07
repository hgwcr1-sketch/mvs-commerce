<div class="flex flex-wrap gap-2">
    @unless($detail)
        @canany(['inventario.transferencias.ver', 'inventario.transferencias.recibir', 'inventario.transferir'])
            <a href="{{ route('transferencias.show', $transfer) }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Ver detalle</a>
        @endcanany
    @endunless
    @php
        $actionPermissions = [
            'prepare' => ['inventario.transferir'],
            'dispatch' => ['inventario.transferir'],
            'review' => ['inventario.transferencias.recibir', 'inventario.transferir'],
            'cancel' => ['inventario.transferir'],
        ];
    @endphp
    @foreach(['prepare' => ['Preparar', $transfer->canBePrepared()], 'dispatch' => ['Despachar', $transfer->canBeDispatched()], 'review' => ['Iniciar revisión', $transfer->canBeReviewed()], 'cancel' => ['Cancelar traslado', $transfer->canBeCancelled()]] as $action => [$label, $available])
        @if($available)
            @canany($actionPermissions[$action])
                <form method="POST" action="{{ route('transferencias.'.$action, $transfer) }}">
                    @csrf
                    <button type="submit" class="min-h-11 rounded-xl border px-4 py-2 text-sm font-semibold {{ $action === 'cancel' ? 'border-red-200 text-red-700 hover:bg-red-50' : 'border-amber-300 bg-amber-50 text-amber-900 hover:bg-amber-100' }}">{{ $label }}</button>
                </form>
            @endcanany
        @endif
    @endforeach
    @if(!$detail && $transfer->isInReview())
        @canany(['inventario.transferencias.recibir', 'inventario.transferir'])
            <a href="{{ route('transferencias.show', $transfer) }}#recepcion" class="inline-flex min-h-11 items-center rounded-xl bg-amber-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-amber-600">Recibir</a>
        @endcanany
    @endif
</div>
