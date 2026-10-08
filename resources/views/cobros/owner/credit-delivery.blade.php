@extends('cobros.layout')

@section('title', 'Confirmar entrega del préstamo — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <a href="{{ $backRoute ?? route('cobros.owner.creditos.show', $credit->id) }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Volver al préstamo</a>

    <header>
        <h1 class="text-2xl font-bold sm:text-3xl">Confirmar entrega del préstamo</h1>
        <p class="mt-1 text-sm text-slate-500">Revise con el cliente las condiciones antes de entregar el dinero.</p>
        <p class="mt-1 text-sm font-semibold text-slate-600">Referencia del préstamo: <span class="font-bold text-slate-900">{{ $credit->publicReference() }}</span></p>
    </header>

    @if($errors->any())
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    {{-- ============ CONDICIONES ============ --}}
    <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
        <h2 class="text-sm font-bold uppercase text-slate-500">Condiciones del préstamo</h2>
        <dl class="mt-3 divide-y divide-slate-100 text-base">
            <div class="flex items-start justify-between gap-4 py-2">
                <dt class="font-semibold text-slate-600">Cliente</dt>
                <dd class="text-right font-bold text-slate-900">{{ $customerName }}</dd>
            </div>
            <div class="flex items-start justify-between gap-4 py-2">
                <dt class="font-semibold text-slate-600">Identificación</dt>
                <dd class="text-right font-bold text-slate-900">{{ strtoupper($identificationType) }} {{ $identification }}</dd>
            </div>
            <div class="flex items-start justify-between gap-4 py-2">
                <dt class="font-semibold text-slate-600">Monto que recibe</dt>
                <dd class="text-right text-lg font-extrabold text-slate-900">{{ \App\Support\Cobros\Money::human((string) $terms['principal'], $company->currency) }}</dd>
            </div>
            <div class="flex items-start justify-between gap-4 py-2">
                <dt class="font-semibold text-slate-600">Interés</dt>
                <dd class="text-right font-bold text-slate-900">{{ $interestLabel }} ({{ \App\Support\Cobros\Money::human($interest, $company->currency) }})</dd>
            </div>
            <div class="flex items-start justify-between gap-4 py-2">
                <dt class="font-bold text-slate-900">Total que pagará</dt>
                <dd class="text-right text-lg font-extrabold text-slate-900">{{ \App\Support\Cobros\Money::human((string) $terms['total_due'], $company->currency) }}</dd>
            </div>
            <div class="flex items-start justify-between gap-4 py-2">
                <dt class="font-semibold text-slate-600">Número de pagos</dt>
                <dd class="text-right font-bold text-slate-900">{{ $terms['installment_count'] }}</dd>
            </div>
            <div class="flex items-start justify-between gap-4 py-2">
                <dt class="font-semibold text-slate-600">Cada cuánto paga</dt>
                <dd class="text-right font-bold text-slate-900">{{ $frequencyLabel }}</dd>
            </div>
            <div class="flex items-start justify-between gap-4 py-2">
                <dt class="font-semibold text-slate-600">Monto de cada pago</dt>
                <dd class="text-right font-bold text-slate-900">
                    {{ \App\Support\Cobros\Money::human($normalAmount, $company->currency) }}
                    @if($normalAmount !== $lastAmount)
                        <span class="block text-xs font-normal text-slate-500">Último pago: {{ \App\Support\Cobros\Money::human($lastAmount, $company->currency) }}</span>
                    @endif
                </dd>
            </div>
            <div class="flex items-start justify-between gap-4 py-2">
                <dt class="font-semibold text-slate-600">Primer pago</dt>
                <dd class="text-right font-bold text-slate-900">{{ \Carbon\Carbon::parse((string) $terms['first_due_date'])->format('d/m/Y') }}</dd>
            </div>
            <div class="flex items-start justify-between gap-4 py-2">
                <dt class="font-semibold text-slate-600">Último pago</dt>
                <dd class="text-right font-bold text-slate-900">{{ \Carbon\Carbon::parse($lastDueDate)->format('d/m/Y') }}</dd>
            </div>
            <div class="flex items-start justify-between gap-4 py-2">
                <dt class="font-semibold text-slate-600">Mora</dt>
                <dd class="text-right font-bold text-slate-900">{{ $lateFeeLabel }}</dd>
            </div>
            <div class="flex items-start justify-between gap-4 py-2">
                <dt class="font-semibold text-slate-600">Días de gracia</dt>
                <dd class="text-right font-bold text-slate-900">{{ (int) $terms['late_fee_grace_days'] }} días</dd>
            </div>
            <div class="flex items-start justify-between gap-4 py-2">
                <dt class="font-semibold text-slate-600">Ruta</dt>
                <dd class="text-right font-bold text-slate-900">{{ $route?->name ?? '—' }}</dd>
            </div>
            <div class="flex items-start justify-between gap-4 py-2">
                <dt class="font-semibold text-slate-600">Cobrador</dt>
                <dd class="text-right font-bold text-slate-900">{{ $collector?->name ?? '—' }}</dd>
            </div>
        </dl>
    </section>

    {{-- ============ CALENDARIO COMPLETO ============ --}}
    <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
        <h2 class="text-sm font-bold uppercase text-slate-500">Calendario de pagos</h2>

        <div class="mt-3 space-y-2 sm:hidden">
            @foreach($rows as $row)
                <div class="flex items-center justify-between rounded-xl border border-slate-200 px-4 py-3">
                    <span class="text-sm font-semibold text-slate-600">Pago #{{ $row['number'] }}</span>
                    <span class="text-sm text-slate-700">{{ \Carbon\Carbon::parse($row['due_date'])->format('d/m/Y') }}</span>
                    <span class="font-bold text-slate-900">{{ \App\Support\Cobros\Money::human($row['contractual_amount'], $company->currency) }}</span>
                </div>
            @endforeach
        </div>

        <div class="mt-3 hidden overflow-x-auto sm:block">
            <table class="w-full min-w-[320px] text-sm">
                <thead>
                    <tr class="text-left text-slate-500">
                        <th class="py-2 pr-2">Pago</th>
                        <th class="py-2 pr-2">Fecha</th>
                        <th class="py-2 text-right">Monto</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($rows as $row)
                        <tr>
                            <td class="py-2 pr-2">{{ $row['number'] }}</td>
                            <td class="py-2 pr-2">{{ \Carbon\Carbon::parse($row['due_date'])->format('d/m/Y') }}</td>
                            <td class="py-2 text-right font-semibold">{{ \App\Support\Cobros\Money::human($row['contractual_amount'], $company->currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- ============ ACEPTACIÓN Y FIRMA ============ --}}
    <form method="POST" action="{{ $disburseRoute ?? route('cobros.owner.creditos.disburse', $credit->id) }}" id="delivery-form" class="space-y-5 rounded-2xl border-2 border-primary bg-white p-4 sm:p-6">
        @csrf

        <section>
            <h2 class="text-sm font-bold uppercase text-amber-700">Aceptación del cliente</h2>
            <p class="mt-2 rounded-xl bg-amber-50 p-4 text-base leading-relaxed text-slate-800">{{ $acceptanceText }}</p>
        </section>

        <section>
            <label for="signature-canvas" class="text-sm font-semibold text-slate-800">Firma del cliente</label>
            <p class="mt-1 text-xs text-slate-500">Que firme con el dedo en el celular o con el mouse en la computadora.</p>
        {{-- Firma táctil cómoda: ancho completo y alto generoso para dedo. --}}
        <canvas id="signature-canvas" width="640" height="220"
                class="mt-2 block h-[220px] min-h-[200px] w-full rounded-xl border-2 border-slate-300 bg-white touch-none"
                aria-label="Área de firma del cliente"></canvas>
            <p id="signature-error" class="mt-2 hidden text-sm font-semibold text-red-600" role="alert">El cliente debe firmar para confirmar la entrega.</p>
            <input type="hidden" name="signature" id="signature-input" value="">
        </section>

        {{-- Botones: apilados en móvil (confirmar ancho completo) y en fila
             horizontal sin comprimirse a partir de sm. --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-stretch">
            <button type="button" id="clear-signature"
                    class="min-h-[48px] w-full shrink-0 whitespace-nowrap rounded-xl border border-slate-300 bg-white px-6 text-base font-bold text-slate-700 hover:bg-slate-50 sm:w-auto">
                LIMPIAR FIRMA
            </button>
            <button type="submit" id="confirm-delivery"
                    class="min-h-[48px] w-full min-w-0 flex-1 whitespace-nowrap rounded-xl bg-primary px-6 text-base font-bold text-white hover:bg-primary-hover sm:w-auto">
                FIRMAR Y CONFIRMAR ENTREGA
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    var canvas = document.getElementById('signature-canvas');
    var input = document.getElementById('signature-input');
    var error = document.getElementById('signature-error');
    var form = document.getElementById('delivery-form');
    var ctx = canvas.getContext('2d');
    var drawing = false;
    var ink = false; // false = lienzo vacío => no se puede confirmar
    var last = null;

    function resize() {
        var ratio = window.devicePixelRatio || 1;
        var rect = canvas.getBoundingClientRect();
        var snapshot = ink ? canvas.toDataURL('image/png') : null;
        canvas.width = Math.max(1, Math.round(rect.width * ratio));
        canvas.height = Math.max(1, Math.round(rect.height * ratio));
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#0f172a';
        if (snapshot) {
            var img = new Image();
            img.onload = function () { ctx.drawImage(img, 0, 0, rect.width, rect.height); };
            img.src = snapshot;
        }
    }

    function point(event) {
        var rect = canvas.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    }

    canvas.addEventListener('pointerdown', function (event) {
        event.preventDefault();
        canvas.setPointerCapture(event.pointerId);
        drawing = true;
        last = point(event);
        ink = true;
        error.classList.add('hidden');
    });

    canvas.addEventListener('pointermove', function (event) {
        if (!drawing) { return; }
        var current = point(event);
        ctx.beginPath();
        ctx.moveTo(last.x, last.y);
        ctx.lineTo(current.x, current.y);
        ctx.stroke();
        last = current;
    });

    ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (name) {
        canvas.addEventListener(name, function () { drawing = false; last = null; });
    });

    // LIMPIAR FIRMA: lienzo realmente vacío; se puede volver a firmar cuantas veces haga falta.
    document.getElementById('clear-signature').addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        input.value = '';
        ink = false;
        error.classList.add('hidden');
        canvas.focus();
    });

    form.addEventListener('submit', function (event) {
        if (!ink) {
            event.preventDefault();
            error.classList.remove('hidden');
            return;
        }
        input.value = canvas.toDataURL('image/png');
    });

    window.addEventListener('resize', resize);
    resize();
})();
</script>
@endsection
