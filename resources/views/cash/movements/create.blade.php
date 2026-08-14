@extends('layouts.app')
@section('title', 'Registrar movimiento de Caja')
@section('content')
<div class="mx-auto max-w-3xl space-y-6" x-data="{ processing: false }">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h2 class="text-2xl font-semibold text-slate-800">Registrar movimiento</h2>
            <p class="text-sm text-slate-600">{{ $cashSession->session_number }} — {{ $cashSession->cashRegister->name }}</p>
        </div>
        <a href="{{ auth()->user()->hasPermission('caja.ver', $cashSession->company) ? route('cash.movements.index', $cashSession) : route('cash.index') }}" class="rounded-lg border border-slate-300 px-4 py-2">Volver</a>
    </div>

    @if(session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-green-800">{{ session('success') }}</div>
    @endif

    <x-card>
        <div class="mb-6 rounded-xl bg-slate-900 p-5 text-white">
            <span class="text-sm text-slate-300">Efectivo esperado actual</span>
            <strong class="block text-3xl">₡{{ number_format($expectedCash, 0, ',', '.') }}</strong>
        </div>

        <form method="POST" action="{{ route('cash.movements.store', $cashSession) }}" class="space-y-5" @submit="processing = true">
            @csrf
            <input type="hidden" name="request_token" value="{{ old('request_token', $requestToken) }}">

            @if($errors->any())
                <div class="rounded-lg bg-red-50 p-4 text-red-700">{{ $errors->first() }}</div>
            @endif

            <div>
                <label for="type" class="mb-2 block font-medium">Tipo de movimiento</label>
                <select id="type" name="type" required class="w-full rounded-xl border-slate-300 px-4 py-3">
                    <option value="entry" @selected(old('type', $selectedType) === 'entry')>Entrada de efectivo</option>
                    <option value="exit" @selected(old('type', $selectedType) === 'exit')>Salida de efectivo</option>
                    <option value="withdrawal" @selected(old('type', $selectedType) === 'withdrawal')>Retiro de efectivo</option>
                </select>
            </div>

            <div>
                <label for="amount" class="mb-2 block font-medium">Monto en colones</label>
                <input id="amount" name="amount" type="number" min="1" step="1" required value="{{ old('amount') }}" class="w-full rounded-xl border-slate-300 px-4 py-3 text-right text-2xl">
            </div>

            <div>
                <label for="reason" class="mb-2 block font-medium">Motivo</label>
                <textarea id="reason" name="reason" rows="3" maxlength="1000" required class="w-full rounded-xl border-slate-300 px-4 py-3">{{ old('reason') }}</textarea>
            </div>

            <div>
                <label for="notes" class="mb-2 block font-medium">Notas <span class="font-normal text-slate-500">(opcional)</span></label>
                <textarea id="notes" name="notes" rows="3" maxlength="5000" class="w-full rounded-xl border-slate-300 px-4 py-3">{{ old('notes') }}</textarea>
            </div>

            <div class="flex justify-end gap-3">
                <a href="{{ auth()->user()->hasPermission('caja.ver', $cashSession->company) ? route('cash.movements.index', $cashSession) : route('cash.index') }}" class="rounded-xl border border-slate-300 px-5 py-3">Cancelar</a>
                <button type="submit" :disabled="processing" class="rounded-xl bg-amber-500 px-6 py-3 font-normal text-black hover:bg-amber-600 disabled:cursor-not-allowed disabled:opacity-50" x-text="processing ? 'Guardando…' : 'Registrar movimiento'"></button>
            </div>
        </form>
    </x-card>
</div>
@endsection
