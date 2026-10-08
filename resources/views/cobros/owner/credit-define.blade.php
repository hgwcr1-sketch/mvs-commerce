@extends('cobros.layout')

@section('title', 'Preparar el préstamo — MVS CobrOS')

@section('content')
@php
    // 9C: misma vista para dueño (histórico) y cobrador (autoridad); el
    // controlador que la renderiza define ruta, etiqueta y decisión.
    $backRoute = $backRoute ?? route('cobros.owner.solicitudes.show', $creditRequest->id);
    $backLabel = $backLabel ?? '← Volver a la solicitud';
    $approveRoute = $approveRoute ?? route('cobros.owner.creditos.approve', $creditRequest->id);
    $previewUrl = $previewUrl ?? route('cobros.owner.creditos.preview');
    $primaryLabel = $primaryLabel ?? 'REVISAR Y AUTORIZAR PRÉSTAMO';
    $confirmLead = $confirmLead ?? 'Está por aprobar un préstamo de';
    // owner_required: el cobrador prepara la propuesta pero NO decide aquí.
    $hideDecisions = $hideDecisions ?? false;
@endphp
<div class="space-y-6">
    <a href="{{ $backRoute }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">{{ $backLabel }}</a>

    <header>
        <h1 class="text-2xl font-bold sm:text-3xl">Preparar el préstamo</h1>
        <p class="mt-1 text-sm text-slate-500">Revise cuánto va a prestar y cómo pagará el cliente.</p>
        <dl class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
                <dt class="text-xs font-semibold uppercase text-slate-500">Cliente</dt>
                <dd class="text-base font-semibold text-slate-800">{{ $creditRequest->name }}</dd>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
                <dt class="text-xs font-semibold uppercase text-slate-500">Solicitó</dt>
                <dd class="text-base font-semibold text-slate-800">₡ {{ number_format((float) $creditRequest->amount_requested, 2) }} <span class="text-xs font-normal text-slate-400">(solo lectura)</span></dd>
            </div>
        </dl>
    </header>

    @if($errors->any())
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <form method="POST" action="{{ $approveRoute }}" id="credit-form" class="space-y-6"{{ $hideDecisions ? ' onsubmit="return false;"' : '' }}>
        @csrf

        {{-- ============ SECCIÓN 1 — EL PRÉSTAMO ============ --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <h2 class="text-sm font-bold uppercase text-slate-500">El préstamo</h2>

            <div class="mt-4">
                <label for="principal" class="text-sm font-semibold text-slate-800">Monto que va a prestar</label>
                <div class="relative mt-1">
                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-500">₡</span>
                    <input id="principal" name="principal" type="text" inputmode="decimal" required maxlength="18"
                           value="{{ old('principal', number_format((float) $creditRequest->amount_requested, 2, '.', '')) }}"
                           class="min-h-[48px] w-full rounded-xl border border-slate-300 pl-8 pr-4 text-base text-right"
                           aria-describedby="principal-help">
                </div>
                <p id="principal-help" class="mt-1 text-xs text-slate-500">Monto solicitado: ₡ {{ number_format((float) $creditRequest->amount_requested, 2) }}. Puede prestar otro monto.</p>
            </div>

            <fieldset class="mt-5">
                <legend class="text-sm font-semibold text-slate-800">¿Cómo cobrará el interés?</legend>
                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <label class="flex min-h-[48px] cursor-pointer items-center gap-3 rounded-xl border border-slate-300 px-4 text-base font-semibold text-slate-800 has-[:checked]:border-primary has-[:checked]:bg-amber-50">
                        <input type="radio" name="interest_type" id="it-none" value="none" class="h-5 w-5 accent-primary"
                               {{ old('interest_type', 'none') === 'none' ? 'checked' : '' }}>
                        Sin interés
                    </label>
                    <label class="flex min-h-[48px] cursor-pointer items-center gap-3 rounded-xl border border-slate-300 px-4 text-base font-semibold text-slate-800 has-[:checked]:border-primary has-[:checked]:bg-amber-50">
                        <input type="radio" name="interest_type" id="it-percent" value="percent" class="h-5 w-5 accent-primary"
                               {{ old('interest_type') === 'percent' ? 'checked' : '' }}>
                        Porcentaje
                    </label>
                    <label class="flex min-h-[48px] cursor-pointer items-center gap-3 rounded-xl border border-slate-300 px-4 text-base font-semibold text-slate-800 has-[:checked]:border-primary has-[:checked]:bg-amber-50">
                        <input type="radio" name="interest_type" id="it-fixed" value="fixed" class="h-5 w-5 accent-primary"
                               {{ old('interest_type') === 'fixed' ? 'checked' : '' }}>
                        Monto fijo
                    </label>
                </div>
            </fieldset>

            <div id="interest-value-block" data-interest-panel="value" class="mt-4" hidden>
                <label for="interest_value" id="interest-value-label" class="text-sm font-semibold text-slate-800">Porcentaje de interés</label>
                <div class="relative mt-1">
                    <input id="interest_value" name="interest_value" type="text" inputmode="decimal" maxlength="18"
                           value="{{ old('interest_value', '0') }}"
                           class="min-h-[48px] w-full rounded-xl border border-slate-300 px-4 pr-10 text-base text-right"
                           aria-describedby="interest-value-help">
                    <span id="interest-value-suffix" class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-slate-500">%</span>
                </div>
                <p id="interest-value-help" class="mt-1 text-xs text-slate-500">Ejemplo: 20 significa 20% sobre el monto prestado.</p>
            </div>
        </section>

        {{-- ============ SECCIÓN 2 — CÓMO VA A PAGAR ============ --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <h2 class="text-sm font-bold uppercase text-slate-500">Cómo va a pagar</h2>

            <div class="mt-4">
                <label for="installment_count" class="text-sm font-semibold text-slate-800">¿En cuántos pagos?</label>
                <input id="installment_count" name="installment_count" type="number" inputmode="numeric" min="1" max="120" required
                       value="{{ old('installment_count', 12) }}"
                       class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-4 text-base">
            </div>

            <fieldset class="mt-5">
                <legend class="text-sm font-semibold text-slate-800">¿Cada cuánto paga?</legend>
                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach(['daily' => 'Todos los días', 'weekly' => 'Cada semana', 'biweekly' => 'Cada 15 días', 'monthly' => 'Cada mes'] as $value => $label)
                        <label class="flex min-h-[48px] cursor-pointer items-center gap-3 rounded-xl border border-slate-300 px-4 text-base font-semibold text-slate-800 has-[:checked]:border-primary has-[:checked]:bg-amber-50">
                            <input type="radio" name="frequency" value="{{ $value }}" class="h-5 w-5 accent-primary"
                                   {{ old('frequency', 'monthly') === $value ? 'checked' : '' }}>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="mt-5">
                <label for="first_due_date" class="text-sm font-semibold text-slate-800">Primer pago</label>
                <input id="first_due_date" name="first_due_date" type="date" required min="{{ now()->toDateString() }}"
                       value="{{ old('first_due_date', now()->addWeek()->toDateString()) }}"
                       class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-4 text-base">
            </div>
        </section>

        {{-- ============ SECCIÓN 3 — SI SE ATRASA ============ --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <h2 class="text-sm font-bold uppercase text-slate-500">Si se atrasa</h2>

            <fieldset class="mt-4">
                <legend class="text-sm font-semibold text-slate-800">¿Cobra mora si se atrasa?</legend>
                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <label class="flex min-h-[48px] cursor-pointer items-center gap-3 rounded-xl border border-slate-300 px-4 text-base font-semibold text-slate-800 has-[:checked]:border-primary has-[:checked]:bg-amber-50">
                        <input type="radio" name="late_fee_type" id="lf-none" value="none" class="h-5 w-5 accent-primary"
                               {{ old('late_fee_type', 'none') === 'none' ? 'checked' : '' }}>
                        No
                    </label>
                    <label class="flex min-h-[48px] cursor-pointer items-center gap-3 rounded-xl border border-slate-300 px-4 text-base font-semibold text-slate-800 has-[:checked]:border-primary has-[:checked]:bg-amber-50">
                        <input type="radio" name="late_fee_type" id="lf-fixed" value="fixed" class="h-5 w-5 accent-primary"
                               {{ old('late_fee_type') === 'fixed' ? 'checked' : '' }}>
                        Sí, monto fijo
                    </label>
                    <label class="flex min-h-[48px] cursor-pointer items-center gap-3 rounded-xl border border-slate-300 px-4 text-base font-semibold text-slate-800 has-[:checked]:border-primary has-[:checked]:bg-amber-50">
                        <input type="radio" name="late_fee_type" id="lf-percent" value="percent" class="h-5 w-5 accent-primary"
                               {{ old('late_fee_type') === 'percent' ? 'checked' : '' }}>
                        Sí, porcentaje
                    </label>
                </div>
            </fieldset>

            <div id="latefee-value-block" class="mt-4" hidden>
                <label for="late_fee_value" id="latefee-value-label" class="text-sm font-semibold text-slate-800">Mora por atraso</label>
                <div class="relative mt-1">
                    <input id="late_fee_value" name="late_fee_value" type="text" inputmode="decimal" maxlength="18"
                           value="{{ old('late_fee_value', '0') }}"
                           class="min-h-[48px] w-full rounded-xl border border-slate-300 px-4 pr-10 text-base text-right"
                           aria-describedby="latefee-value-help">
                    <span id="latefee-value-suffix" class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-slate-500">₡</span>
                </div>
                <p id="latefee-value-help" class="mt-1 text-xs text-slate-500">Monto que cobrará por cada atraso.</p>
            </div>

            <div id="latefee-grace-block" class="mt-4" hidden>
                <label for="late_fee_grace_days" class="text-sm font-semibold text-slate-800">Días antes de cobrar mora</label>
                <input id="late_fee_grace_days" name="late_fee_grace_days" type="number" inputmode="numeric" min="0" max="365"
                       value="{{ old('late_fee_grace_days', 0) }}"
                       class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-4 text-base"
                       aria-describedby="latefee-grace-help">
                <p id="latefee-grace-help" class="mt-1 text-xs text-slate-500">Durante estos días puede pagar sin que se le cobre mora.</p>
            </div>
        </section>

        {{-- ============ SECCIÓN 4 — RESUMEN ============ --}}
        <section class="rounded-2xl border-2 border-primary bg-amber-50 p-4 sm:p-6" aria-live="polite">
            <h2 class="text-sm font-bold uppercase text-amber-800">Resumen</h2>
            <dl class="mt-3 divide-y divide-amber-200 text-base">
                <div class="flex items-center justify-between py-2">
                    <dt class="font-semibold text-slate-700">MONTO PRESTADO</dt>
                    <dd class="font-bold text-slate-900" id="sum-principal">—</dd>
                </div>
                <div class="flex items-center justify-between py-2">
                    <dt class="font-semibold text-slate-700">INTERÉS</dt>
                    <dd class="font-bold text-slate-900" id="sum-interest">—</dd>
                </div>
                <div class="flex items-center justify-between py-2">
                    <dt class="font-bold text-slate-900">TOTAL A COBRAR</dt>
                    <dd class="text-lg font-extrabold text-slate-900" id="sum-total">—</dd>
                </div>
                <div class="flex items-center justify-between py-2">
                    <dt class="font-semibold text-slate-700">NÚMERO DE PAGOS</dt>
                    <dd class="font-bold text-slate-900" id="sum-count">—</dd>
                </div>
                <div class="flex items-center justify-between py-2">
                    <dt class="font-semibold text-slate-700">PAGO NORMAL</dt>
                    <dd class="font-bold text-slate-900" id="sum-normal">—</dd>
                </div>
                <div class="flex items-center justify-between py-2">
                    <dt class="font-semibold text-slate-700">PRIMER PAGO</dt>
                    <dd class="font-bold text-slate-900" id="sum-first">—</dd>
                </div>
            </dl>
            <p id="sum-rounding" class="mt-2 hidden text-sm text-amber-900">
                <span id="sum-rounding-normal"></span><br>
                <span id="sum-rounding-last"></span>
            </p>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6" aria-live="polite">
            <h2 class="text-sm font-bold uppercase text-slate-500">Calendario de pagos</h2>

            <div class="mt-3 sm:hidden space-y-2" id="preview-cards">
                @if(!empty($initialRows))
                    @foreach($initialRows as $row)
                        <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3">
                            <span class="text-sm font-semibold text-slate-600">Pago #{{ $row['number'] }}</span>
                            <span class="text-sm text-slate-700">{{ \Illuminate\Support\Carbon::parse($row['due_date'])->format('d/m/Y') }}</span>
                            <span class="text-base font-bold text-slate-900">₡ {{ number_format((float) $row['contractual_amount'], 2, ',', '.') }}</span>
                        </div>
                    @endforeach
                @else
                    <p class="text-sm text-slate-500">Complete los datos para ver el calendario…</p>
                @endif
            </div>

            <div class="mt-3 hidden overflow-x-auto sm:block">
                <table class="w-full min-w-[320px] text-sm">
                    <thead>
                        <tr class="text-left text-slate-500">
                            <th class="py-2 pr-2">N.º de pago</th>
                            <th class="py-2 pr-2">Fecha</th>
                            <th class="py-2 text-right">Monto</th>
                        </tr>
                    </thead>
                    <tbody id="preview-rows" class="divide-y divide-slate-100">
                        @if(!empty($initialRows))
                            @foreach($initialRows as $row)
                                <tr>
                                    <td class="py-2 pr-2">{{ $row['number'] }}</td>
                                    <td class="py-2 pr-2">{{ \Illuminate\Support\Carbon::parse($row['due_date'])->format('d/m/Y') }}</td>
                                    <td class="py-2 text-right">₡ {{ number_format((float) $row['contractual_amount'], 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        @else
                            <tr><td colspan="3" class="py-2 text-slate-500">Complete los datos para ver el calendario…</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </section>

        @if($hideDecisions)
            <div role="status" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Las solicitudes de este cobrador esperan la autorización del dueño.
                Prepare la propuesta de condiciones; el dueño define y autoriza el préstamo.
            </div>
        @else
            <button type="submit" id="approve-btn" class="min-h-[48px] w-full rounded-xl bg-primary px-6 text-base font-bold text-white hover:bg-primary-hover">{{ $primaryLabel }}</button>
            <p class="text-center text-xs text-slate-500">Al aprobar, los términos quedan fijos. El desembolso se confirma por separado.</p>
        @endif
    </form>

    @if(! $hideDecisions && isset($noRealizarRoute))
    <form method="POST" action="{{ $noRealizarRoute }}" class="rounded-xl border border-red-200 bg-red-50 p-4 space-y-3">
        @csrf
        <div>
            <label for="motivo" class="block text-sm font-semibold text-red-800">¿No se va a realizar el préstamo?</label>
            <p class="text-xs text-red-700">La solicitud se conserva con su motivo. No se crea ningún préstamo.</p>
        </div>
        <select id="motivo" name="motivo" required
                class="min-h-[48px] w-full rounded-xl border border-red-300 bg-white px-3 text-base text-slate-800">
            <option value="">Elija un motivo…</option>
            <option value="cliente_no_califica">El cliente no califica para este préstamo</option>
            <option value="condiciones_no_acordadas">No se acordaron las condiciones</option>
            <option value="informacion_incompleta">Información incompleta del cliente</option>
            <option value="otro">Otro motivo</option>
        </select>
        <button type="submit" class="min-h-[48px] w-full rounded-xl border border-red-600 bg-white px-6 text-base font-bold text-red-700 hover:bg-red-100">
            NO REALIZAR EL PRÉSTAMO
        </button>
    </form>
    @endif
</div>

<dialog id="approve-dialog" aria-labelledby="approve-dialog-title"
        class="w-[min(94vw,520px)] rounded-2xl border border-slate-200 bg-white p-5 text-slate-800 backdrop:bg-slate-900/50">
    <h2 id="approve-dialog-title" class="text-lg font-bold text-slate-900">{{ $confirmTitle ?? 'Confirmar autorización' }}</h2>
    <p id="confirm-text" class="mt-3 text-base leading-relaxed text-slate-700"></p>
    <div class="mt-5 flex flex-col gap-2 sm:flex-row">
        <button type="button" id="confirm-back" class="min-h-[48px] flex-1 rounded-xl border border-slate-300 bg-white px-4 text-base font-semibold text-slate-700 hover:bg-slate-50">Volver y revisar</button>
        <button type="button" id="confirm-yes" class="min-h-[48px] flex-1 rounded-xl bg-primary px-4 text-base font-bold text-white hover:bg-primary-hover">{{ $confirmLabel ?? 'CONFIRMAR AUTORIZACIÓN' }}</button>
    </div>
</dialog>

<script>
(function () {
    var form = document.getElementById('credit-form');
    var url = @json($previewUrl);
    // CSRF: el navegador real exige el token en el POST del preview
    // (los tests unitarios lo bypasean, por eso nunca fallaban ahí).
    var csrfInput = form.querySelector('input[name="_token"]');
    var csrfToken = csrfInput ? csrfInput.value : '';
    var timer = null;
    var lastPayload = null;

    var FREQ_LABEL = { daily: 'todos los días', weekly: 'cada semana', biweekly: 'cada 15 días', monthly: 'cada mes' };
    var INTEREST_UI = {
        percent: { label: 'Porcentaje de interés', suffix: '%', help: 'Ejemplo: 20 significa 20% sobre el monto prestado.' },
        fixed: { label: 'Interés total', suffix: '₡', help: 'Monto total que cobrará de interés por este préstamo.' }
    };
    var LATEFEE_UI = {
        fixed: { label: 'Mora por atraso', suffix: '₡', help: 'Monto que cobrará por cada atraso.' },
        percent: { label: 'Porcentaje de mora', suffix: '%', help: 'Ejemplo: 2 significa 2% sobre el pago atrasado.' }
    };

    function fmt(n) { return Number(n).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function radio(name) { var el = form.querySelector('input[name="' + name + '"]:checked'); return el ? el.value : null; }
    function setText(id, text) { var el = document.getElementById(id); if (el) { el.textContent = text; } }

    function syncInterestUI() {
        var type = radio('interest_type') || 'none';
        var block = document.getElementById('interest-value-block');
        var input = document.getElementById('interest_value');
        if (type === 'none') {
            block.hidden = true;
            input.required = false;
            input.removeAttribute('aria-invalid');
        } else {
            var ui = INTEREST_UI[type];
            document.getElementById('interest-value-label').textContent = ui.label;
            document.getElementById('interest-value-suffix').textContent = ui.suffix;
            document.getElementById('interest-value-help').textContent = ui.help;
            block.hidden = false;
            input.required = true;
        }
    }

    function syncLateFeeUI() {
        var type = radio('late_fee_type') || 'none';
        var valueBlock = document.getElementById('latefee-value-block');
        var graceBlock = document.getElementById('latefee-grace-block');
        var valueInput = document.getElementById('late_fee_value');
        var graceInput = document.getElementById('late_fee_grace_days');
        if (type === 'none') {
            valueBlock.hidden = true;
            graceBlock.hidden = true;
            valueInput.required = false;
            graceInput.required = false;
        } else {
            var ui = LATEFEE_UI[type];
            document.getElementById('latefee-value-label').textContent = ui.label;
            document.getElementById('latefee-value-suffix').textContent = ui.suffix;
            document.getElementById('latefee-value-help').textContent = ui.help;
            valueBlock.hidden = false;
            graceBlock.hidden = false;
            valueInput.required = true;
            graceInput.required = true;
        }
    }

    function showFieldErrors(errors) {
        Object.keys(errors || {}).forEach(function (name) {
            var input = document.getElementById(name);
            if (!input) { return; }
            input.setAttribute('aria-invalid', 'true');
            var help = input.getAttribute('aria-describedby');
            var helpEl = help ? document.getElementById(help.split(' ')[0]) : null;
            if (helpEl) { helpEl.textContent = errors[name][0]; helpEl.classList.add('text-red-600', 'font-semibold'); }
        });
    }

    function renderSummary(p) {
        // Calendario PRIMERO: nunca puede quedar vacío aunque el resumen falle.
        var rows = (p && p.rows) ? p.rows : [];
        var cardsEl = document.getElementById('preview-cards');
        var tbodyEl = document.getElementById('preview-rows');
        if (rows.length && cardsEl && tbodyEl) {
            cardsEl.innerHTML = rows.map(function (row) {
                return '<div class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3">' +
                    '<span class="text-sm font-semibold text-slate-600">Pago #' + row.number + '</span>' +
                    '<span class="text-sm text-slate-700">' + formatDate(row.due_date) + '</span>' +
                    '<span class="text-base font-bold text-slate-900">₡ ' + fmt(row.contractual_amount) + '</span>' +
                    '</div>';
            }).join('');
            tbodyEl.innerHTML = rows.map(function (row) {
                return '<tr><td class="py-2 pr-2">' + row.number + '</td>' +
                    '<td class="py-2 pr-2">' + formatDate(row.due_date) + '</td>' +
                    '<td class="py-2 text-right">' + fmt(row.contractual_amount) + '</td></tr>';
            }).join('');
        }

        if (!rows.length) { return; }
        setText('sum-principal', '₡ ' + fmt(p.principal));
        setText('sum-interest', '₡ ' + fmt(p.interest));
        setText('sum-total', '₡ ' + fmt(p.total_due));
        setText('sum-count', String(p.installment_count));
        setText('sum-first', formatDate(p.rows[0].due_date));

        var same = Number(p.normal_amount) === Number(p.last_amount);
        var normalEl = document.getElementById('sum-normal');
        if (normalEl) { normalEl.textContent = '₡ ' + fmt(p.normal_amount); }
        var roundEl = document.getElementById('sum-rounding');
        if (same) {
            if (roundEl) { roundEl.classList.add('hidden'); }
        } else {
            // El motor reparte el residuo en el último pago: se informa tal cual.
            setText('sum-rounding-normal', 'Pagos 1–' + (p.installment_count - 1) + ': ₡ ' + fmt(p.normal_amount));
            setText('sum-rounding-last', 'Último pago: ₡ ' + fmt(p.last_amount));
            if (roundEl) { roundEl.classList.remove('hidden'); }
        }
    }

    function formatDate(iso) {
        var d = iso.split('-');
        return d[2] + '/' + d[1] + '/' + d[0];
    }

    var TEXT_FIELDS = ['principal', 'interest_value', 'installment_count', 'first_due_date', 'late_fee_value', 'late_fee_grace_days'];
    var RADIO_FIELDS = ['interest_type', 'frequency', 'late_fee_type'];

    function update() {
        var data = new FormData();
        TEXT_FIELDS.forEach(function (name) {
            var el = document.getElementById(name);
            if (el) { data.append(name, el.value); }
        });
        RADIO_FIELDS.forEach(function (name) {
            var checked = form.querySelector('input[name="' + name + '"]:checked');
            if (checked) { data.append(name, checked.value); }
        });

        fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }, body: data, credentials: 'same-origin' })
            .then(function (r) {
                if (r.ok) { return r.json(); }
                return r.json().catch(function () { return null; }).then(function (body) {
                    showFieldErrors(body && body.errors ? body.errors : null);
                    return null;
                });
            })
            .then(function (payload) {
                if (!payload) { return; }
                lastPayload = payload;
                renderSummary(payload);
            })
            .catch(function () { /* la vista previa nunca bloquea el formulario */ });
    }

    function scheduleUpdate() {
        clearTimeout(timer);
        timer = setTimeout(update, 350);
    }

    form.querySelectorAll('input, select').forEach(function (el) {
        el.addEventListener('change', function () {
            if (el.name === 'interest_type') { syncInterestUI(); }
            if (el.name === 'late_fee_type') { syncLateFeeUI(); }
            scheduleUpdate();
        });
        el.addEventListener('input', scheduleUpdate);
    });

    syncInterestUI();
    syncLateFeeUI();
    update();

    // ---- Confirmación antes de aprobar (progresiva: sin JS se envía directo) ----
    var dialog = document.getElementById('approve-dialog');
    var supportsDialog = typeof dialog.showModal === 'function';
    var confirmed = false;

    form.addEventListener('submit', function (event) {
        if (confirmed || !supportsDialog) { confirmed = false; return; }
        if (!form.reportValidity()) { event.preventDefault(); return; }
        event.preventDefault();
        if (!lastPayload) { update(); }
        var p = lastPayload;
        var freq = radio('frequency') || 'monthly';
        if (p) {
            document.getElementById('confirm-text').textContent =
                '{{ $confirmLead }}' + ' ₡ ' + fmt(p.principal) + '. ' +
                'El cliente pagará ' + p.installment_count + ' pagos ' + FREQ_LABEL[freq] + '. ' +
                'Total a cobrar: ₡ ' + fmt(p.total_due) + '. ' +
                'Después de aprobar, estas condiciones quedan fijas.';
        } else {
            document.getElementById('confirm-text').textContent =
                '{{ $confirmLead }}' + '. Después de aprobar, estas condiciones quedan fijas.';
        }
        dialog.showModal();
    });

    document.getElementById('confirm-back').addEventListener('click', function () { dialog.close(); });
    document.getElementById('confirm-yes').addEventListener('click', function () {
        dialog.close();
        // Marca la confirmación: sin esto, requestSubmit() reentra al handler
        // de submit, vuelve a preventDefault() y reabre el diálogo (bucle).
        confirmed = true;
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
    });
})();
</script>
@endsection
