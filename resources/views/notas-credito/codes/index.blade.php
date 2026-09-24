@extends('layouts.app')

@section('title', 'Códigos de Notas de Crédito')

@section('description', 'Administre y regenere los códigos de las notas de crédito a consumidor final.')

@section('content')

<div class="space-y-6" x-data="{ openId: null, openNumber: '', reason: '' }">

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-slate-800">
                Códigos de Notas de Crédito
            </h2>

            <p class="text-sm text-slate-500">
                Notas de crédito a consumidor final. Regenerar deja sin efecto el código anterior de inmediato.
            </p>
        </div>
    </div>

    <x-card>

        <form method="GET" action="{{ route('notas-credito.codes.index') }}" class="grid grid-cols-1 gap-4 md:grid-cols-[1fr_auto]">
            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-700">
                    Buscar por número
                </label>

                <input
                    type="text"
                    name="search"
                    value="{{ request('search') }}"
                    placeholder="NC-00000001"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>

            <div class="flex items-end gap-2">
                <button
                    type="submit"
                    class="min-h-11 rounded-lg bg-primary px-4 py-2 font-semibold text-black hover:bg-primary-hover">
                    Filtrar
                </button>

                <a
                    href="{{ route('notas-credito.codes.index') }}"
                    class="min-h-11 rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
                    Limpiar
                </a>
            </div>
        </form>

    </x-card>

    <x-card>

        @php
            $statusLabels = [
                \App\Models\CreditNote::STATUS_ISSUED => ['Emitida', 'bg-green-100 text-green-700'],
                \App\Models\CreditNote::STATUS_PARTIALLY_APPLIED => ['Parcialmente aplicada', 'bg-amber-100 text-amber-800'],
                \App\Models\CreditNote::STATUS_APPLIED => ['Aplicada', 'bg-slate-200 text-slate-700'],
                \App\Models\CreditNote::STATUS_VOIDED => ['Anulada', 'bg-red-100 text-red-700'],
            ];
        @endphp

        @if($notes->count())

            <div class="overflow-x-auto">

                <table class="min-w-full divide-y divide-slate-200">

                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                                Número
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600 hidden sm:table-cell">
                                Emitida
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                                Saldo
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-600 hidden md:table-cell">
                                Vence
                            </th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-600 hidden sm:table-cell">
                                Estado
                            </th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-slate-600">
                                Acción
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100 bg-white">

                        @foreach($notes as $note)

                            <tr class="hover:bg-slate-50">

                                <td class="px-4 py-3 font-medium text-slate-800">
                                    {{ $note->credit_note_number }}
                                </td>

                                <td class="px-4 py-3 text-sm text-slate-600 hidden sm:table-cell">
                                    {{ $note->issued_at?->format('d/m/Y H:i') ?: '—' }}
                                </td>

                                <td class="px-4 py-3 text-right font-semibold text-slate-800">
                                    ₡{{ number_format((float) $note->balance, 2, ',', '.') }}
                                </td>

                                <td class="px-4 py-3 text-sm text-slate-500 hidden md:table-cell">
                                    {{ $note->expires_at?->format('d/m/Y') ?: 'Sin vencimiento' }}
                                </td>

                                <td class="px-4 py-3 text-center hidden sm:table-cell">
                                    @php [$statusLabel, $statusClasses] = $statusLabels[$note->status] ?? [$note->status, 'bg-slate-100 text-slate-700']; @endphp
                                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ $statusClasses }}">
                                        {{ $statusLabel }}
                                    </span>
                                </td>

                                <td class="px-4 py-3 text-center">
                                    @if($note->canRegenerateCode())

                                        <button
                                            type="button"
                                            @click="openId = {{ $note->id }}; openNumber = '{{ $note->credit_note_number }}'; reason = ''"
                                            class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-black hover:bg-primary-hover transition">
                                            Regenerar código
                                        </button>

                                    @else

                                        <span class="inline-block px-3 py-2 text-xs text-slate-400">
                                            No disponible
                                        </span>

                                    @endif
                                </td>

                            </tr>

                        @endforeach

                    </tbody>

                </table>

            </div>

            <div class="mt-5">
                {{ $notes->links() }}
            </div>

        @else

            <div class="py-10 text-center text-slate-500">
                No se encontraron notas de crédito a consumidor final.
            </div>

        @endif

    </x-card>

    {{-- Modal de regeneración (mobile-safe) --}}
    <div
        x-show="openId !== null"
        x-cloak
        x-transition
        class="fixed inset-0 z-[120] flex items-center justify-center bg-[#111111]/80 p-3 sm:p-5"
        role="dialog"
        aria-modal="true"
        aria-label="Regenerar código de nota de crédito">
        <div class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-xl max-h-[90vh]">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 sm:px-5">
                <h3 class="font-black text-slate-800">
                    Regenerar código
                </h3>

                <button
                    type="button"
                    @click="openId = null"
                    class="flex h-11 w-11 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100">
                    &times;
                </button>
            </div>

            <div class="overflow-y-auto p-4 sm:p-5">
                <template x-if="openId !== null">
                    <form
                        method="POST"
                        :action="'/notas-credito/' + openId + '/codigo/regenerar'"
                        class="space-y-4">

                        @csrf

                        <div class="rounded-xl border border-primary bg-primary/10 p-4">
                            <p class="text-sm font-bold text-slate-800">
                                Nota de Crédito
                                <span x-text="openNumber" class="font-mono"></span>
                            </p>

                            <p class="mt-1 text-sm font-semibold text-red-700">
                                El código anterior dejará de funcionar.
                            </p>
                        </div>

                        <div>
                            <label for="nc-rotation-reason" class="mb-1 block text-sm font-semibold text-slate-700">
                                Motivo obligatorio
                            </label>

                            <textarea
                                id="nc-rotation-reason"
                                name="reason"
                                x-model="reason"
                                rows="3"
                                maxlength="500"
                                required
                                placeholder="Ej. El cliente perdió el código original."
                                class="w-full rounded-lg border border-slate-300 px-3 py-2"></textarea>

                            @error('reason')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <button
                                type="button"
                                @click="openId = null"
                                class="min-h-11 rounded-lg border border-slate-300 px-4 py-2 font-semibold text-slate-700 hover:bg-slate-100">
                                Cancelar
                            </button>

                            <button
                                type="submit"
                                :disabled="reason.trim().length < 3"
                                class="min-h-11 rounded-lg bg-primary px-4 py-2 font-semibold text-black hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-50">
                                Regenerar código
                            </button>
                        </div>
                    </form>
                </template>
            </div>
        </div>
    </div>

</div>

@endsection