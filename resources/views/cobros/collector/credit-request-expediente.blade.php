@extends('cobros.layout')

@section('title', 'Expediente — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold sm:text-3xl">Expediente del cliente</h1>
        <p class="mt-1 text-sm text-slate-500">
            Información faltante: trabajo e ingresos, señas, referencias y fiador.
            Esta información apoya la evaluación; no aprueba ni rechaza el préstamo. El expediente es permanente y sobrevive a las solicitudes.
        </p>
        <p class="mt-1 text-sm font-semibold text-slate-700">
            <span class="inline-flex items-center gap-1.5">@include('cobros.components.credit-risk', ['customer' => $customer, 'company' => $company])<span>{{ $customer->name }} — {{ $customer->identification }}</span></span>
        </p>
        @php
            $estadoExpediente = $expediente === null
                ? 'Sin expediente'
                : ($expediente->occupation && $expediente->income_amount && $expediente->address_reference && $expediente->references->count() > 0
                    ? 'Completo'
                    : 'Incompleto');
        @endphp
        <p class="mt-2">
            <span class="rounded-full px-3 py-1 text-xs font-bold {{ $estadoExpediente === 'Completo' ? 'bg-emerald-100 text-emerald-700' : ($estadoExpediente === 'Incompleto' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600') }}">
                Expediente: {{ $estadoExpediente }}
            </span>
            <span class="ml-2 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Calificación: Sin calificar</span>
        </p>
    </div>

    @if (session('expediente_saved'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-700">
            {{ session('expediente_saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            <p class="font-bold">Revise los datos marcados:</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ $saveUrl }}" enctype="multipart/form-data" class="space-y-6">
        @csrf

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
            <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Trabajo e ingresos</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="font-semibold text-slate-700">¿En qué trabaja?</span>
                    <input name="occupation" maxlength="120" value="{{ old('occupation', $expediente?->occupation) }}"
                           class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-sm">
                </label>
                <label class="block text-sm">
                    <span class="font-semibold text-slate-700">Lugar de trabajo</span>
                    <input name="workplace" maxlength="150" value="{{ old('workplace', $expediente?->workplace) }}"
                           class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-sm">
                </label>
                <label class="block text-sm">
                    <span class="font-semibold text-slate-700">Ingreso mensual aproximado</span>
                    <input name="income_amount" inputmode="decimal" value="{{ old('income_amount', $expediente?->income_amount) }}"
                           class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-right text-sm">
                </label>
                <label class="block text-sm">
                    <span class="font-semibold text-slate-700">Frecuencia del ingreso</span>
                    <select name="income_frequency" class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-sm">
                        <option value="">Seleccione</option>
                        @foreach (['daily' => 'Diario', 'weekly' => 'Semanal', 'biweekly' => 'Quincenal', 'monthly' => 'Mensual'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('income_frequency', $expediente?->income_frequency) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm sm:col-span-2">
                    <span class="font-semibold text-slate-700">Tiempo en esa actividad</span>
                    <input name="activity_time" maxlength="60" placeholder="Ej: 3 años"
                           value="{{ old('activity_time', $expediente?->activity_time) }}"
                           class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-sm">
                </label>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
            <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Dirección</h2>
            <p class="mb-2 text-xs text-slate-500">Dirección base: {{ $customer->address ?: 'sin dirección registrada' }}</p>
            <label class="block text-sm">
                <span class="font-semibold text-slate-700">Referencia de cómo llegar</span>
                <input name="address_reference" maxlength="250" placeholder="Ej: frente a la iglesia, casa azul"
                       value="{{ old('address_reference', $expediente?->address_reference) }}"
                       class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-sm">
            </label>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
            <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Referencias personales (máx. 2)</h2>
            <p class="mb-3 text-xs text-slate-500">Personas de confianza que conocen al solicitante. No son clientes ni fiadores.</p>
            <div class="space-y-4">
                @for ($i = 0; $i < 2; $i++)
                    <fieldset class="rounded-xl border border-slate-200 p-3">
                        <legend class="px-1 text-xs font-bold uppercase text-slate-500">Referencia {{ $i + 1 }}</legend>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <label class="block text-sm">
                                <span class="font-semibold text-slate-700">Nombre</span>
                                <input name="references[{{ $i }}][name]" maxlength="150"
                                       value="{{ $storedReferences[$i]['name'] ?? '' }}"
                                       class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-sm">
                            </label>
                            <label class="block text-sm">
                                <span class="font-semibold text-slate-700">Teléfono</span>
                                <input name="references[{{ $i }}][phone]" maxlength="30" inputmode="tel"
                                       value="{{ $storedReferences[$i]['phone'] ?? '' }}"
                                       class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-sm">
                            </label>
                            <label class="block text-sm">
                                <span class="font-semibold text-slate-700">Parentesco</span>
                                <input name="references[{{ $i }}][relationship]" maxlength="60" placeholder="Ej: hermano"
                                       value="{{ $storedReferences[$i]['relationship'] ?? '' }}"
                                       class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-sm">
                            </label>
                        </div>
                    </fieldset>
                @endfor
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
            <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Fiador (opcional)</h2>
            <label class="flex min-h-[44px] items-center gap-3 rounded-xl border border-slate-200 p-3 text-sm">
                <input type="checkbox" name="has_guarantor" value="1" id="has-guarantor" class="h-5 w-5 shrink-0"
                       @checked(old('has_guarantor', $expediente?->has_guarantor))>
                <span class="font-semibold text-slate-700">La solicitud cuenta con fiador</span>
            </label>

            <div id="guarantor-fields" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 {{ old('has_guarantor', $expediente?->has_guarantor) ? '' : 'hidden' }}">
                <label class="block text-sm">
                    <span class="font-semibold text-slate-700">Nombre del fiador</span>
                    <input name="guarantor_name" maxlength="150" value="{{ old('guarantor_name', $expediente?->guarantor_name) }}"
                           class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-sm">
                </label>
                <label class="block text-sm">
                    <span class="font-semibold text-slate-700">Identificación del fiador</span>
                    <input name="guarantor_identification" maxlength="50" inputmode="numeric" value="{{ old('guarantor_identification', $expediente?->guarantor_identification) }}"
                           class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-sm">
                </label>
                <label class="block text-sm">
                    <span class="font-semibold text-slate-700">Teléfono del fiador</span>
                    <input name="guarantor_phone" maxlength="30" inputmode="tel" value="{{ old('guarantor_phone', $expediente?->guarantor_phone) }}"
                           class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-sm">
                </label>
                <label class="block text-sm">
                    <span class="font-semibold text-slate-700">Dirección del fiador</span>
                    <input name="guarantor_address" maxlength="250" value="{{ old('guarantor_address', $expediente?->guarantor_address) }}"
                           class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-sm">
                </label>
                <label class="block text-sm">
                    <span class="font-semibold text-slate-700">Ocupación del fiador</span>
                    <input name="guarantor_occupation" maxlength="120" value="{{ old('guarantor_occupation', $expediente?->guarantor_occupation) }}"
                           class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-sm">
                </label>
                <label class="block text-sm">
                    <span class="font-semibold text-slate-700">Ingreso aproximado del fiador</span>
                    <input name="guarantor_income" inputmode="decimal" value="{{ old('guarantor_income', $expediente?->guarantor_income) }}"
                           class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 text-right text-sm">
                </label>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
            <h2 class="mb-3 text-sm font-bold uppercase text-slate-500">Documentos adicionales (privados)</h2>
            <p class="mb-3 text-xs text-slate-500">PDF o imagen (máx. 5 MB cada uno). Dejar vacío para conservar el actual.</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach (['income' => 'Comprobante de ingresos', 'address' => 'Comprobante de domicilio', 'other' => 'Otro documento'] as $type => $label)
                    <label class="block text-sm">
                        <span class="font-semibold text-slate-700">{{ $label }}</span>
                        <input type="file" name="doc_{{ $type }}" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                               class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @php($current = $expediente?->documents->firstWhere('doc_type', $type))
                        @if ($current)
                            <span class="mt-1 block text-xs text-slate-500">
                                Actual:
                                <a class="font-semibold text-primary underline"
                                   href="{{ $ownerMode ? route('cobros.owner.customers.expediente.document', [$customer->id, $type]) : route('cobros.collector.customers.expediente.document', [$customer->id, $type]) }}"
                                   target="_blank" rel="noopener">{{ $current->original_name }}</a>
                            </span>
                        @endif
                    </label>
                @endforeach
            </div>
        </section>

        <div class="sticky bottom-20 flex flex-col gap-3 sm:flex-row sm:justify-end">
            <a href="{{ $backUrl }}"
               class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 px-6 text-sm font-semibold hover:bg-slate-50">Volver</a>
            <button type="submit"
                    class="min-h-[48px] rounded-xl bg-primary px-6 text-base font-bold text-white hover:bg-primary-hover">GUARDAR EXPEDIENTE</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('has-guarantor');
    const fields = document.getElementById('guarantor-fields');
    if (!toggle || !fields) return;
    toggle.addEventListener('change', function () {
        fields.classList.toggle('hidden', !toggle.checked);
        if (!toggle.checked) {
            fields.querySelectorAll('input').forEach(function (input) { input.value = ''; });
        }
    });
});
</script>
@endsection
