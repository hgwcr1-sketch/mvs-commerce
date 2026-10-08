@extends('cobros.layout')

@section('title', 'Mora del cobrador · MVS CobrOS')

@section('content')
<div class="space-y-6">
    <a href="{{ route('cobros.owner.dashboard') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Inicio</a>

    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" aria-label="Reparto de la mora">
        <h1 class="text-2xl font-bold text-slate-900">Reparto de la mora</h1>
        <p class="mt-1 text-sm text-slate-500">Define cuánto de la mora cobrada gana normalmente el cobrador. No cambia lo que debe el cliente.</p>

        <form method="POST" action="{{ route('cobros.owner.latefee.update') }}" class="mt-4">
            @csrf
            @method('PUT')
            <label for="collector_late_fee_pct" class="block text-sm font-semibold text-slate-700">¿Qué porcentaje de la mora gana normalmente el cobrador?</label>
            <div class="mt-2 flex items-center gap-3">
                <input id="collector_late_fee_pct" name="collector_late_fee_pct" type="text" inputmode="decimal" maxlength="6"
                       value="{{ old('collector_late_fee_pct', $generalPct) }}"
                       class="min-h-[48px] w-32 rounded-xl border border-slate-300 px-4 text-right text-base font-semibold text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                <span class="text-base font-semibold text-slate-600">%</span>
            </div>
            @error('collector_late_fee_pct')
            <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p>
            @enderror
            <p class="mt-2 text-xs text-slate-500">Entre 0 y 100. Con 0% toda la mora es del prestamista; con 100% toda la mora es del cobrador.</p>

            <div class="mt-4 rounded-xl bg-slate-50 p-3 text-sm" id="mora-ejemplo">
                <p class="font-semibold text-slate-700">Si se cobran <span id="ej-monto">₡5,000.00</span> de mora:</p>
                <p class="mt-1 text-slate-700">Cobrador: <strong id="ej-cobrador">₡2,500.00</strong></p>
                <p class="text-slate-700">Prestamista: <strong id="ej-prestamista">₡2,500.00</strong></p>
            </div>

            <button type="submit" class="mt-4 inline-flex min-h-[44px] items-center rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover">Guardar</button>
        </form>
    </section>
</div>

<script>
    (function () {
        var input = document.getElementById('collector_late_fee_pct');
        if (!input) { return; }

        function fmt(cents) {
            var whole = Math.floor(cents / 100);
            var fraction = cents % 100;
            return '₡' + String(whole).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + (fraction < 10 ? '0' + fraction : fraction);
        }

        function update() {
            var pct = parseFloat(input.value);
            if (isNaN(pct) || pct < 0) { pct = 0; }
            if (pct > 100) { pct = 100; }
            var totalCents = 500000; // ₡5,000.00
            var collectorCents = Math.round(totalCents * Math.round(pct * 100) / 10000);
            document.getElementById('ej-cobrador').textContent = fmt(collectorCents);
            document.getElementById('ej-prestamista').textContent = fmt(totalCents - collectorCents);
        }

        input.addEventListener('input', update);
        update();
    })();
</script>
@endsection
