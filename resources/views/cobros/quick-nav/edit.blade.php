@extends('cobros.layout')
@section('title', 'Personalizar accesos rápidos — MVS CobrOS')
@section('content')
<div class="mx-auto w-full max-w-xl space-y-4">
    <a href="{{ route('cobros.collector.index') }}" class="inline-flex min-h-[44px] items-center gap-2 text-sm font-semibold text-slate-700">← Volver</a>
    <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <h1 class="text-xl font-bold text-slate-900">Personalizar accesos rápidos</h1>
        <p class="mt-1 text-sm text-slate-600">Elija @if($needsFour) exactamente 4 @else sus accesos @endif en el orden que prefiera. “Más” siempre queda de último.</p>
        <form method="POST" action="{{ route('cobros.quick-nav.update') }}" class="mt-4 space-y-3">
            @csrf
            @method('PUT')
            @for($i = 0; $i < 4; $i++)
                <div>
                    <label for="qnav-{{ $i }}" class="block text-sm font-semibold text-slate-700">Acceso {{ $i + 1 }}</label>
                    <select id="qnav-{{ $i }}" name="shortcuts[]" required class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-base">
                        @foreach($options as $route => $label)
                            <option value="{{ $route }}" @selected(($selected[$i] ?? '') === $route)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endfor
            @error('shortcuts')<p role="alert" class="text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
            <button type="submit" class="flex min-h-[48px] w-full items-center justify-center rounded-xl bg-[#D4AF37] px-4 py-3 text-base font-bold text-slate-950 hover:bg-[#B1922D]">GUARDAR ACCESOS</button>
        </form>
    </div>
</div>
@endsection
