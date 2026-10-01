@extends('layouts.app')
@section('content')
<div class="mx-auto max-w-2xl space-y-5 pb-24 md:pb-8">
    <section class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-7">
        <p class="text-sm font-bold uppercase tracking-wide text-amber-700">Facturación Electrónica</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-950">Cambio de proveedor</h1>
        <p class="mt-2 text-sm text-slate-600">Conexión actual: <strong>Conexión fiscal MVS</strong>. El portal y el POS no cambian; el historial conserva su origen. El cambio real se hace desde la conexión después de cumplir la lista.</p>

        <form method="GET" action="{{ route('fiscal.switch') }}" class="mt-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="text-sm font-bold" for="to">Conexión destino</label>
                <select id="to" name="to" class="mt-1 rounded-xl border border-slate-300 p-3 min-h-[44px]">
                    <option value="">Elegir…</option>
                    @foreach($providers as $code)
                        <option value="{{ $code }}" @selected($target === $code)>Conexión alternativa {{ $loop->iteration }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black hover:brightness-95">Revisar requisitos</button>
        </form>

        @if($check !== null)
            <div class="mt-4 rounded-xl {{ $check['ok'] ? 'bg-emerald-50' : 'bg-amber-50' }} p-4 text-sm">
                @if($check['ok'])
                    <p class="font-bold text-emerald-900">Listo para cambiar. Verifique el destino y actualice la conexión.</p>
                @else
                    <p class="font-bold text-amber-900">Aún no se puede cambiar:</p>
                    <ul class="mt-2 list-disc pl-5 text-amber-900">
                        @foreach($check['blockers'] as $blocker)
                            <li>{{ $blocker['message'] }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        <a href="{{ route('fiscal.index') }}" class="mt-4 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Volver al portal fiscal</a>
    </section>
</div>
@endsection
