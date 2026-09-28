@extends('layouts.app')

@section('title', 'Ajustes manuales de puntos')
@section('description', 'Ajuste manual de puntos de Fidelización con motivo obligatorio y trazabilidad en el Kardex.')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-slate-800">Ajustes de puntos</h1>
        <p class="text-sm text-slate-500">Sume o reste puntos manualmente a una cuenta de fidelización. Cada ajuste queda registrado en el Kardex con su motivo.</p>
    </div>

    @if(session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    <x-card><x-slot:header><h2 class="text-lg font-semibold">Nuevo ajuste</h2></x-slot:header>
    <form method="POST" action="{{ route('loyalty.adjustments.store') }}" class="grid gap-4 md:grid-cols-3">@csrf
        <input type="hidden" name="event_token" value="{{ old('event_token', (string) \Illuminate\Support\Str::uuid()) }}">
        <div><label for="customer_id" class="form-label">Cliente<span class="text-red-500">*</span></label><select name="customer_id" id="customer_id" class="form-input"><option value="">Seleccione un cliente</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>{{ $customer->name }}</option>@endforeach</select>@error('customer_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
        <div>
            <label for="direction" class="form-label">Operación<span class="text-red-500">*</span></label>
            <select name="direction" id="direction" class="form-input">
                <option value="sumar" @selected(old('direction', 'sumar') === 'sumar')>Sumar puntos</option>
                <option value="restar" @selected(old('direction') === 'restar')>Restar puntos</option>
            </select>
            @error('direction')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="points" class="form-label">Cantidad de puntos<span class="text-red-500">*</span></label>
            <input id="points" name="points" type="number" min="0.0001" max="999999999999" step="0.0001" required value="{{ old('points') }}" class="form-input">
            @error('points')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div class="md:col-span-2">
            <label for="reason" class="form-label">Motivo<span class="text-red-500">*</span></label>
            <input id="reason" name="reason" type="text" maxlength="255" required value="{{ old('reason') }}" class="form-input" placeholder="Ejemplo: corrección por error de digitación en la venta #1234">
            @error('reason')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div class="flex items-end">
            <button class="w-full rounded-lg bg-amber-500 px-5 py-2.5 font-semibold text-black hover:bg-amber-600">Registrar ajuste</button>
        </div>
    </form>
    @if(isset($branchName))<p class="mt-2 text-sm text-slate-500">Sucursal de origen del ajuste: {{ $branchName }}.</p>@endif</x-card>

    <x-card><x-slot:header><h2 class="text-lg font-semibold">Historial de ajustes</h2></x-slot:header>
    <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500"><th class="py-2 pr-4">Fecha</th><th class="py-2 pr-4">Cliente</th><th class="py-2 pr-4">Puntos</th><th class="py-2 pr-4">Usuario</th><th class="py-2 pr-4">Sucursal</th><th class="py-2 pr-4">Motivo</th></tr></thead><tbody>
    @forelse($adjustments as $adjustment)<tr class="border-b border-slate-100"><td class="py-2 pr-4 whitespace-nowrap">{{ $adjustment->created_at->format('d/m/Y H:i') }}</td><td class="py-2 pr-4">{{ $adjustment->customer?->name }}</td><td class="py-2 pr-4 font-semibold {{ str_starts_with((string) $adjustment->points, '-') ? 'text-red-600' : 'text-emerald-700' }}">{{ $adjustment->points }}</td><td class="py-2 pr-4">{{ $adjustment->user?->name }}</td><td class="py-2 pr-4">{{ $adjustment->branch?->name }}</td><td class="py-2 pr-4">{{ $adjustment->description }}</td></tr>
    @empty<tr><td colspan="6" class="py-4 text-slate-500">No hay ajustes registrados.</td></tr>@endforelse
    </tbody></table></div>{{ $adjustments->links() }}
    </x-card>
</div>
@endsection
