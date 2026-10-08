@extends('cobros.layout')

@section('title', 'Evaluar solicitud — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold sm:text-3xl">Información para evaluar la solicitud</h1>
        <p class="mt-1 text-sm text-slate-500">Consulte la información disponible para evaluar esta solicitud. Esta información sirve como apoyo y no aprueba ni rechaza automáticamente el préstamo.</p>
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Datos de la solicitud</h2>
        <dl class="divide-y divide-slate-100 text-sm">
            <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Solicitante</dt><dd class="text-right">{{ $creditRequest->name }}</dd></div>
            <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Identificación</dt><dd class="text-right">{{ $creditRequest->identification }}</dd></div>
            @if($creditRequest->phone)
                <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Teléfono</dt><dd class="text-right">{{ $creditRequest->phone }}</dd></div>
            @endif
            @if($creditRequest->address)
                <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Dirección</dt><dd class="text-right">{{ $creditRequest->address }}</dd></div>
            @endif
            <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Monto solicitado</dt><dd class="text-right">@include('cobros.components.money', ['amount' => $creditRequest->amount_requested, 'currency' => $company->currency])</dd></div>
        </dl>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Documentos de identificación</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <figure class="rounded-xl border border-slate-200 p-2">
                <img src="{{ route('cobros.collector.credit-requests.photo', [$creditRequest->id, 'front']) }}"
                     alt="Foto identificación — Frente" class="h-40 w-full rounded-lg object-contain bg-slate-50">
                <figcaption class="mt-2 text-center text-xs font-semibold text-slate-600">Foto identificación — Frente</figcaption>
            </figure>
            <figure class="rounded-xl border border-slate-200 p-2">
                <img src="{{ route('cobros.collector.credit-requests.photo', [$creditRequest->id, 'back']) }}"
                     alt="Foto identificación — Reverso" class="h-40 w-full rounded-lg object-contain bg-slate-50">
                <figcaption class="mt-2 text-center text-xs font-semibold text-slate-600">Foto identificación — Reverso</figcaption>
            </figure>
        </div>
    </section>

    @if($creditRequest->customer_id)
    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Expediente</h2>
        <p class="mb-3 text-sm text-slate-500">Complete la información faltante: trabajo e ingresos, señas, referencias, fiador y documentos adicionales.</p>
        <a href="{{ route('cobros.collector.customers.expediente', $creditRequest->customer_id).'?solicitud='.$creditRequest->id }}"
           class="inline-flex min-h-[44px] w-full items-center justify-center rounded-xl bg-primary px-6 text-sm font-bold text-white hover:bg-primary-hover sm:w-auto">COMPLETAR EXPEDIENTE</a>
    </section>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Consulta de información</h2>
        <label class="flex min-h-[44px] items-start gap-3 rounded-xl border border-slate-200 p-3 text-sm">
            <input type="checkbox" id="eval-consent" name="consent" value="acepto" class="mt-1 h-5 w-5 shrink-0">
            <span>El cliente autoriza la consulta de su información.</span>
        </label>
        <button type="button" id="eval-consult"
                class="mt-3 min-h-[48px] w-full rounded-xl bg-primary px-6 text-base font-bold text-white hover:bg-primary-hover sm:w-auto">CONSULTAR INFORMACIÓN</button>
        <p id="eval-consult-result" class="mt-3 hidden rounded-xl bg-slate-50 p-3 text-sm text-slate-700"></p>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Confirmación de la revisión</h2>
        <form method="POST" action="{{ route('cobros.collector.credit-requests.verify', $creditRequest->id) }}">
            @csrf
            <label class="block text-sm">
                <span class="font-semibold text-slate-700">Describa la revisión realizada con el cliente</span>
                <input name="motivo" maxlength="500" required
                       value="Datos, documentos e información revisados con el cliente"
                       class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-sm">
            </label>
            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:justify-end">
                <a href="{{ route('cobros.collector.credit-requests.index') }}"
                   class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 px-6 text-sm font-semibold hover:bg-slate-50">Volver</a>
                <button type="submit"
                        class="min-h-[48px] rounded-xl bg-primary px-6 text-base font-bold text-white hover:bg-primary-hover">REVISAR Y CONFIRMAR DATOS</button>
            </div>
        </form>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const button = document.getElementById('eval-consult');
    const consent = document.getElementById('eval-consent');
    const result = document.getElementById('eval-consult-result');
    if (!button) return;
    button.addEventListener('click', function () {
        result.classList.remove('hidden');
        if (!consent.checked) {
            result.textContent = 'El cliente debe autorizar la consulta de su información antes de continuar.';
            return;
        }
        result.textContent = 'Consultando información disponible…';
        const params = new URLSearchParams({
            identification_type: @json($creditRequest->identification_type ?? 'cedula'),
            identification: @json($creditRequest->identification),
            autorizacion: 'acepto'
        });
        fetch(@json(route('cobros.collector.credit-requests.bureau-check')) + '?' + params.toString(), { headers: { Accept: 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
            .then(function (data) {
                result.textContent = data.found
                    ? 'Información disponible encontrada. Considere este resultado como apoyo al evaluar la solicitud.'
                    : 'No se encontró información disponible. Continúe con la revisión de los datos del cliente.';
            })
            .catch(function () {
                result.textContent = 'No fue posible consultar la información en este momento. Puede continuar con la revisión manual.';
            });
    });
});
</script>
@endsection
