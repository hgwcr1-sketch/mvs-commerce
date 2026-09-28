<div class="flex flex-wrap gap-2">
    @unless($detail)
        <a href="{{ route('transferencias.show', $transfer) }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Ver detalle</a>
    @endunless
    @foreach(['prepare' => ['Preparar', $transfer->canBePrepared()], 'dispatch' => ['Despachar', $transfer->canBeDispatched()], 'review' => ['Iniciar revisión', $transfer->canBeReviewed()], 'cancel' => ['Cancelar traslado', $transfer->canBeCancelled()]] as $action => [$label, $available])
        @if($available)
            <form method="POST" action="{{ route('transferencias.'.$action, $transfer) }}">
                @csrf
                <button type="submit" class="min-h-11 rounded-xl border px-4 py-2 text-sm font-semibold {{ $action === 'cancel' ? 'border-red-200 text-red-700 hover:bg-red-50' : 'border-amber-300 bg-amber-50 text-amber-900 hover:bg-amber-100' }}">{{ $label }}</button>
            </form>
        @endif
    @endforeach
    @if(!$detail && $transfer->isInReview())
        <a href="{{ route('transferencias.show', $transfer) }}#recepcion" class="inline-flex min-h-11 items-center rounded-xl bg-amber-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-amber-600">Recibir</a>
    @endif
</div>
