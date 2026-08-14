@extends('layouts.app')
@section('title', 'Movimientos de Caja')
@section('content')
<div class="space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h2 class="text-2xl font-semibold text-slate-800">Movimientos de Caja</h2>
            <p class="text-sm text-slate-600">{{ $cashSession->session_number }} — {{ $cashSession->cashRegister->name }}</p>
        </div>
        <a href="{{ route('cash.index') }}" class="rounded-lg border border-slate-300 px-4 py-2">Volver</a>
    </div>

    @if(session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-green-800">{{ session('success') }}</div>
    @endif

    <x-card>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <span class="text-sm text-slate-500">Efectivo esperado actual</span>
                <strong class="block text-3xl text-slate-900">₡{{ number_format($expectedCash, 0, ',', '.') }}</strong>
            </div>
            @if($canCreate)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('cash.movements.create', [$cashSession, 'type' => 'entry']) }}" class="rounded-xl bg-amber-500 px-4 py-3 font-normal text-black hover:bg-amber-600">Entrada</a>
                    <a href="{{ route('cash.movements.create', [$cashSession, 'type' => 'exit']) }}" class="rounded-xl bg-amber-500 px-4 py-3 font-normal text-black hover:bg-amber-600">Salida</a>
                    <a href="{{ route('cash.movements.create', [$cashSession, 'type' => 'withdrawal']) }}" class="rounded-xl bg-amber-500 px-4 py-3 font-normal text-black hover:bg-amber-600">Retiro</a>
                </div>
            @endif
        </div>
    </x-card>

    <x-card>
        <x-slot:header><h3 class="text-lg font-semibold">Historial auditable</h3></x-slot:header>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b bg-slate-100">
                    <tr><th class="px-4 py-3 text-left">Fecha</th><th class="px-4 py-3 text-left">Tipo</th><th class="px-4 py-3 text-left">Motivo</th><th class="px-4 py-3 text-left">Notas</th><th class="px-4 py-3 text-left">Usuario</th><th class="px-4 py-3 text-right">Monto</th></tr>
                </thead>
                <tbody>
                    @forelse($movements as $movement)
                        <tr class="border-b">
                            <td class="whitespace-nowrap px-4 py-3">{{ $movement->occurred_at->timezone($companyTimezone)->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3">{{ ['entry' => 'Entrada', 'exit' => 'Salida', 'withdrawal' => 'Retiro'][$movement->type] ?? ucfirst($movement->type) }}</td>
                            <td class="px-4 py-3">{{ $movement->reason }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $movement->notes ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $movement->createdBy->name }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right {{ $movement->direction === 'in' ? 'text-green-700' : 'text-red-700' }}">{{ $movement->direction === 'in' ? '+' : '−' }} ₡{{ number_format((float) $movement->amount, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-8 text-center text-slate-500">No hay movimientos registrados en esta sesión.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
@endsection
