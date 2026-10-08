@extends('cobros.layout')

@section('title', 'Solicitud #'.($creditRequest->id ?? 0).' — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <a href="{{ route('cobros.owner.solicitudes.index') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Volver a solicitudes</a>

    @if(session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <ul class="list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold sm:text-3xl">Solicitud #{{ $creditRequest->id }}</h1>
        <span class="rounded-full bg-primary/10 px-4 py-1 text-sm font-bold text-slate-800">{{ $creditRequest->statusLabel() }}</span>
    </div>

    <section class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="mb-2 text-sm font-bold uppercase text-slate-500">Solicitante</h2>
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Nombre</dt><dd class="flex items-center justify-end gap-1.5 text-right font-semibold">@if($creditRequest->customer)@include('cobros.components.credit-risk', ['customer' => $creditRequest->customer, 'company' => $company, 'label' => null])@endif{{ $creditRequest->name }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Identificación</dt><dd class="text-right font-semibold">{{ strtoupper((string) $creditRequest->identification_type) }} {{ $creditRequest->identification }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Teléfono</dt><dd class="text-right font-semibold">{{ $creditRequest->phone ?: '—' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Dirección</dt><dd class="text-right font-semibold">{{ $creditRequest->address ?: '—' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Solicitante</dt><dd class="text-right font-semibold">{{ $creditRequest->applicant_type === 'existente' ? 'Cliente existente' : 'Cliente nuevo' }}</dd></div>
            </dl>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="mb-2 text-sm font-bold uppercase text-slate-500">Solicitud</h2>
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Monto solicitado</dt><dd class="text-right font-semibold">₡{{ number_format((float) $creditRequest->amount_requested, 2) }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Ruta</dt><dd class="text-right font-semibold">{{ $creditRequest->route?->name ?? '—' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Cobrador</dt><dd class="text-right font-semibold">{{ $creditRequest->collector?->name ?? '—' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Enviada</dt><dd class="text-right font-semibold">{{ optional($creditRequest->submitted_at)->format('d/m/Y H:i') }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Notas</dt><dd class="text-right font-semibold">{{ $creditRequest->notes ?: '—' }}</dd></div>
            </dl>
        </div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4">
        <h2 class="mb-2 text-sm font-bold uppercase text-slate-500">Identidad / Hacienda</h2>
        <dl class="space-y-1 text-sm">
            <div class="flex justify-between gap-2">
                <dt class="text-slate-500">Información de identidad</dt>
                <dd class="text-right font-semibold">{{ $creditRequest->bureau_identity_id ? 'Registrada' : 'Sin registro' }}</dd>
            </div>
            <div class="flex justify-between gap-2">
                <dt class="text-slate-500">Nombre Hacienda/fuente fiscal</dt>
                <dd class="text-right font-semibold">{{ $haciendaName ?: 'Sin resultado' }}</dd>
            </div>
        </dl>
        <p class="mt-2 text-xs text-slate-500">Resultado objetivo de la fuente fiscal cuando existe; no implica aprobación ni puntaje.</p>
    </section>

    @if(($requiresOwnerPolicy ?? false) && in_array($creditRequest->status, [
        \App\Models\CobrosCreditRequest::STATUS_PENDING,
        \App\Models\CobrosCreditRequest::STATUS_CORRECTION,
        \App\Models\CobrosCreditRequest::STATUS_VERIFIED,
    ], true))
        {{-- 9C.4 — owner_required: la oficina consulta con la MISMA política/endpoint de 9C.3. --}}
        <section class="rounded-2xl border-2 border-primary/60 bg-amber-50/70 p-4 sm:p-5">
            <h2 class="text-base font-bold uppercase text-slate-700">Información para evaluar la solicitud</h2>
            <p class="mt-2 text-sm text-slate-700">
                Consulte la información disponible para evaluar esta solicitud.
                Esta información sirve como apoyo y no aprueba ni rechaza automáticamente el préstamo.
            </p>
            <p class="mt-2 text-xs font-semibold uppercase text-slate-500">
                Solicitud → Consultar información → Revisar → Preparar préstamo o No autorizar
            </p>

            <label class="mt-4 flex min-h-[48px] cursor-pointer items-start gap-3 rounded-xl border border-slate-300 bg-white p-3 text-sm font-semibold text-slate-800">
                <input type="checkbox" id="office-consent" class="mt-1 h-6 w-6 rounded border-slate-400">
                <span>El cliente autoriza la consulta de su información.</span>
            </label>

            <button type="button" id="consult-btn" disabled
                    class="mt-3 min-h-[52px] w-full cursor-not-allowed rounded-xl bg-slate-300 px-6 text-base font-bold text-slate-600 disabled:cursor-not-allowed">
                CONSULTAR INFORMACIÓN
            </button>

            <div id="consult-result" hidden class="mt-3 rounded-xl border border-emerald-200 bg-white p-3 text-sm text-slate-800"></div>
            <p class="mt-2 text-xs text-slate-500">La consulta queda registrada con su usuario y fecha. No genera aprobación, rechazo ni puntaje.</p>
        </section>

        <script>
            (function () {
                const consent = document.getElementById('office-consent');
                const btn = document.getElementById('consult-btn');
                const out = document.getElementById('consult-result');
                if (!consent || !btn || !out) { return; }
                consent.addEventListener('change', function () {
                    btn.disabled = !consent.checked;
                    btn.className = consent.checked
                        ? 'mt-3 min-h-[52px] w-full cursor-pointer rounded-xl bg-primary px-6 text-base font-bold text-white hover:bg-primary-hover'
                        : 'mt-3 min-h-[52px] w-full cursor-not-allowed rounded-xl bg-slate-300 px-6 text-base font-bold text-slate-600 disabled:cursor-not-allowed';
                });
                btn.addEventListener('click', function () {
                    if (btn.disabled) { return; }
                    btn.disabled = true;
                    out.hidden = false;
                    out.textContent = 'Consultando…';
                    const params = new URLSearchParams({
                        identification_type: @json((string) $creditRequest->identification_type),
                        identification: @json((string) $creditRequest->identification),
                        autorizacion: 'acepto'
                    });
                    fetch(@json(route('cobros.owner.solicitudes.bureau-check')) + '?' + params.toString(), { headers: { Accept: 'application/json' } })
                        .then((r) => (r.ok ? r.json() : Promise.reject()))
                        .then((data) => {
                            // Resultado permitido y humano: solo existencia.
                            out.textContent = data && data.found
                                ? 'Consulta realizada · Información de identidad: Registrada'
                                : 'Consulta realizada · Información de identidad: Sin registro';
                        })
                        .catch(() => { out.textContent = 'No fue posible realizar la consulta. Intente de nuevo.'; })
                        .finally(function () { btn.disabled = !consent.checked; });
                });
            })();
        </script>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-4">
        <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Evidencias de identificación</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <figure>
                <img src="{{ route('cobros.owner.solicitudes.photo', ['id' => $creditRequest->id, 'lado' => 'front']) }}"
                     alt="Frente de la identificación" loading="lazy"
                     class="min-h-[160px] w-full rounded-xl border border-slate-200 object-contain bg-slate-50">
                <figcaption class="mt-1 text-center text-xs text-slate-500">Frente</figcaption>
                <div class="mt-2 flex justify-center gap-3">
                    <a href="{{ route('cobros.owner.solicitudes.photo', ['id' => $creditRequest->id, 'lado' => 'front']) }}"
                       target="_blank" rel="noopener"
                       class="inline-flex min-h-[44px] items-center rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50">Ver foto</a>
                    <a href="{{ route('cobros.owner.solicitudes.photo', ['id' => $creditRequest->id, 'lado' => 'front', 'descargar' => 1]) }}"
                       download
                       class="inline-flex min-h-[44px] items-center rounded-xl border border-primary bg-white px-4 text-sm font-bold text-slate-900 hover:bg-primary/10">Descargar</a>
                </div>
            </figure>
            <figure>
                <img src="{{ route('cobros.owner.solicitudes.photo', ['id' => $creditRequest->id, 'lado' => 'back']) }}"
                     alt="Reverso de la identificación" loading="lazy"
                     class="min-h-[160px] w-full rounded-xl border border-slate-200 object-contain bg-slate-50">
                <figcaption class="mt-1 text-center text-xs text-slate-500">Reverso</figcaption>
                <div class="mt-2 flex justify-center gap-3">
                    <a href="{{ route('cobros.owner.solicitudes.photo', ['id' => $creditRequest->id, 'lado' => 'back']) }}"
                       target="_blank" rel="noopener"
                       class="inline-flex min-h-[44px] items-center rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50">Ver foto</a>
                    <a href="{{ route('cobros.owner.solicitudes.photo', ['id' => $creditRequest->id, 'lado' => 'back', 'descargar' => 1]) }}"
                       download
                       class="inline-flex min-h-[44px] items-center rounded-xl border border-primary bg-white px-4 text-sm font-bold text-slate-900 hover:bg-primary/10">Descargar</a>
                </div>
            </figure>
        </div>
        <p class="mt-2 text-xs text-slate-500">Servidas desde almacenamiento privado con autorización; nunca con URL pública.</p>
    </section>

    @if(in_array($creditRequest->status, [\App\Models\CobrosCreditRequest::STATUS_PENDING, \App\Models\CobrosCreditRequest::STATUS_CORRECTION], true))
        <section class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Revisión de la solicitud</h2>
            <div class="space-y-4">
                @if($creditRequest->status === \App\Models\CobrosCreditRequest::STATUS_PENDING)
                    <form method="POST" action="{{ route('cobros.owner.solicitudes.verify', $creditRequest->id) }}">
                        @csrf
                        <button type="submit" class="min-h-[48px] w-full rounded-xl bg-primary px-6 text-base font-bold text-white hover:bg-primary-hover">REVISAR Y CONFIRMAR DATOS</button>
                        <p class="mt-1 text-center text-xs text-slate-500">Confirma que los datos fueron revisados. No aprueba el préstamo: después prepara las condiciones.</p>
                    </form>
                @endif
                <form method="POST" action="{{ route('cobros.owner.solicitudes.correct', $creditRequest->id) }}" class="space-y-2">
                    @csrf
                    <label for="motivo_correccion" class="text-sm font-semibold">Solicitar corrección (motivo obligatorio)</label>
                    <textarea id="motivo_correccion" name="motivo" rows="2" required minlength="5" maxlength="255"
                              class="min-h-[64px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Explique qué debe corregir el cobrador…"></textarea>
                    <button type="submit" class="min-h-[48px] w-full rounded-xl border-2 border-amber-500 bg-white px-6 text-base font-bold text-amber-700 hover:bg-amber-50">SOLICITAR CORRECCIÓN</button>
                </form>
                @if($creditRequest->status === \App\Models\CobrosCreditRequest::STATUS_PENDING)
                    <form method="POST" action="{{ route('cobros.owner.solicitudes.reject', $creditRequest->id) }}" class="space-y-2">
                        @csrf
                        <label for="motivo_rechazo" class="text-sm font-semibold">No autorizar (motivo obligatorio · terminal)</label>
                        <textarea id="motivo_rechazo" name="motivo" rows="2" required minlength="5" maxlength="255"
                                  class="min-h-[64px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Motivo de la no autorización…"></textarea>
                        <button type="submit" onclick="return confirm('¿No autorizar esta solicitud? La acción es terminal.')"
                                class="min-h-[48px] w-full rounded-xl border-2 border-red-500 bg-white px-6 text-base font-bold text-red-700 hover:bg-red-50">NO AUTORIZAR</button>
                    </form>
                @endif
            </div>
        </section>
    @endif

    @if($creditRequest->status === \App\Models\CobrosCreditRequest::STATUS_VERIFIED || $credit)
        <section class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Crédito</h2>
            @if($credit)
                <a href="{{ route('cobros.owner.creditos.show', $credit->id) }}"
                   class="flex min-h-[48px] w-full items-center justify-center rounded-xl bg-primary px-6 text-base font-bold text-white hover:bg-primary-hover">
                    {{ $credit->status === \App\Models\CobrosCredit::STATUS_DISBURSED ? 'Ver crédito desembolsado' : 'Ver crédito aprobado' }}
                </a>
            @else
                <a href="{{ route('cobros.owner.creditos.define', $creditRequest->id) }}"
                   class="flex min-h-[48px] w-full items-center justify-center rounded-xl bg-primary px-6 text-base font-bold text-white hover:bg-primary-hover">PREPARAR PRÉSTAMO</a>
            @endif
        </section>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-4">
        <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Historial (append-only)</h2>
        <ol class="space-y-3">
            @forelse($creditRequest->logs as $log)
                <li class="rounded-xl border border-slate-100 bg-slate-50 p-3 text-sm">
                    <p class="font-semibold text-slate-800">
                        {{ \Illuminate\Support\Carbon::parse($log->created_at)->format('d/m/Y H:i') }}
                        · {{ $log->actor?->name ?? 'Sistema' }}
                        · {{ $log->from_status ? (\App\Models\CobrosCreditRequest::STATUS_LABELS[$log->from_status] ?? $log->from_status).' → ' : '' }}{{ \App\Models\CobrosCreditRequest::STATUS_LABELS[$log->to_status] ?? $log->to_status }}
                    </p>
                    @if($log->notes)<p class="mt-1 text-slate-600">Motivo: {{ $log->notes }}</p>@endif
                </li>
            @empty
                <li class="text-sm text-slate-500">Sin movimientos.</li>
            @endforelse
        </ol>
    </section>
</div>
@endsection
