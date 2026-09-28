@extends('layouts.app')
@section('title', 'Cuenta por cobrar')
@section('content')
@php
    $effectiveStatus = $account->effective_status;
    $statusLabels = ['pending' => 'Pendiente', 'partial' => 'Parcial', 'paid' => 'Pagada', 'overdue' => 'Vencida', 'cancelled' => 'Cancelada'];
    $statusClasses = ['pending' => 'bg-amber-100 text-amber-800', 'partial' => 'bg-sky-100 text-sky-800', 'paid' => 'bg-emerald-100 text-emerald-800', 'overdue' => 'bg-red-100 text-red-800', 'cancelled' => 'bg-slate-200 text-slate-700'];
    $paidTotal = max(0, (float) $account->original_amount - (float) $account->balance_due);
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-2xl font-bold text-slate-800">Cuenta por cobrar</h1><p class="text-sm text-slate-500">Venta {{ $account->sale->sale_number }} · {{ $account->customer->name }}</p></div><a href="{{ route('cuentas-por-cobrar.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100">Volver</a></div>

    <x-card>
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div><p class="text-xs font-semibold uppercase text-slate-500">Cliente</p><p class="mt-1 font-semibold text-slate-800">{{ $account->customer->name }}</p></div><div><p class="text-xs font-semibold uppercase text-slate-500">Venta</p><p class="mt-1 font-semibold text-slate-800">{{ $account->sale->sale_number }}</p></div><div><p class="text-xs font-semibold uppercase text-slate-500">Monto original</p><p class="mt-1 font-semibold text-slate-800">₡{{ number_format((float) $account->original_amount, 0, ',', '.') }}</p></div><div><p class="text-xs font-semibold uppercase text-slate-500">Total abonado</p><p class="mt-1 font-semibold text-slate-800">₡{{ number_format($paidTotal, 0, ',', '.') }}</p></div>
            <div><p class="text-xs font-semibold uppercase text-slate-500">Saldo</p><p class="mt-1 text-xl font-bold {{ (float) $account->balance_due > 0 ? 'text-slate-900' : 'text-emerald-700' }}">₡{{ number_format((float) $account->balance_due, 0, ',', '.') }}</p></div><div><p class="text-xs font-semibold uppercase text-slate-500">Fecha de emisión</p><p class="mt-1 text-slate-800">{{ $account->issued_at->format('d/m/Y') }}</p></div><div><p class="text-xs font-semibold uppercase text-slate-500">Fecha de vencimiento</p><p class="mt-1 text-slate-800">{{ $account->due_date->format('d/m/Y') }}</p></div><div><p class="text-xs font-semibold uppercase text-slate-500">Estado</p><span class="mt-1 inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ $statusClasses[$effectiveStatus] ?? 'bg-slate-100 text-slate-700' }}">{{ $statusLabels[$effectiveStatus] ?? $effectiveStatus }}</span></div>
        </div>
    </x-card>

    @can('cuentas_cobrar.abonar')
        @if(!in_array($account->status, ['paid', 'cancelled'], true) && (float) $account->balance_due > 0)
            <x-card>
                <x-slot:header><h2 class="text-lg font-semibold text-slate-800">Registrar abono</h2></x-slot:header>
                <form method="POST" action="{{ route('cuentas-por-cobrar.payments.store', $account) }}" class="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                    @csrf
                    <div><label for="amount" class="mb-1 block text-sm font-medium text-slate-700">Monto</label><input id="amount" required min="1" max="{{ $account->balance_due }}" step="1" type="number" name="amount" value="{{ old('amount') }}" class="w-full rounded-lg border-slate-300"></div>
                    <div><label for="payment_method_id" class="mb-1 block text-sm font-medium text-slate-700">Forma de pago</label><select id="payment_method_id" required name="payment_method_id" class="w-full rounded-lg border-slate-300"><option value="">Seleccione</option>@foreach($methods as $method)<option value="{{ $method->id }}" @selected((string) old('payment_method_id') === (string) $method->id)>{{ $method->name }}</option>@endforeach</select></div>
                    <div><label for="cash_session_id" class="mb-1 block text-sm font-medium text-slate-700">Sesión de caja</label><select id="cash_session_id" name="cash_session_id" class="w-full rounded-lg border-slate-300"><option value="">Seleccione</option>@foreach($sessions as $session)<option value="{{ $session->id }}" @selected((string) old('cash_session_id') === (string) $session->id)>{{ $session->session_number }} — {{ $session->cashRegister->name }}</option>@endforeach</select></div>
                    <div><label for="reference" class="mb-1 block text-sm font-medium text-slate-700">Referencia</label><input id="reference" name="reference" maxlength="150" value="{{ old('reference') }}" class="w-full rounded-lg border-slate-300"></div><div><label for="notes" class="mb-1 block text-sm font-medium text-slate-700">Notas</label><input id="notes" name="notes" maxlength="2000" value="{{ old('notes') }}" class="w-full rounded-lg border-slate-300"></div>
                    <button type="submit" class="rounded-lg bg-amber-500 px-4 py-2 font-semibold text-white hover:bg-amber-600 md:w-fit lg:col-span-5">Registrar abono</button>
                </form>
                @if($errors->any())<p class="mt-3 text-sm font-medium text-red-600">{{ $errors->first() }}</p>@endif
            </x-card>
        @endif
    @endcan

    <x-card>
        <x-slot:header><h2 class="text-lg font-semibold text-slate-800">Historial de abonos</h2></x-slot:header>
        <div class="overflow-x-auto"><table class="w-full min-w-[720px] text-sm"><thead class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase text-slate-600"><tr><th class="px-3 py-3">Fecha</th><th class="px-3 py-3">Método</th><th class="px-3 py-3">Referencia</th><th class="px-3 py-3">Usuario</th><th class="px-3 py-3 text-right">Monto</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse($account->payments as $payment)<tr><td class="px-3 py-3">{{ $payment->paid_at->format('d/m/Y H:i') }}</td><td class="px-3 py-3">{{ $payment->paymentMethod->name }}</td><td class="px-3 py-3">{{ $payment->reference ?? '—' }}</td><td class="px-3 py-3">{{ $payment->user->name }}</td><td class="whitespace-nowrap px-3 py-3 text-right font-semibold">₡{{ number_format((float) $payment->amount, 0, ',', '.') }}</td></tr>@empty<tr><td colspan="5" class="p-6 text-center text-slate-500">Sin abonos registrados.</td></tr>@endforelse</tbody></table></div>
    </x-card>
</div>
@endsection
