@extends('layouts.app')
@section('title', 'Canjes de premios de Fidelización')
@section('content')
<div class="space-y-6">
<div><h1 class="text-2xl font-semibold text-slate-800">Canjes de premios</h1><p class="text-sm text-slate-500">Cada canje descuenta los puntos del cliente y una unidad del premio. Queda registrado en el historial y en el Kardex.</p></div>
@if(session('success'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>@endif
@php $modeLabels = ['unlimited' => 'Ilimitado', 'limited' => 'Cupo propio', 'product' => 'Producto real']; @endphp
<x-card><x-slot:header><h2 class="text-lg font-semibold">Nuevo canje</h2></x-slot:header>
<form method="POST" action="{{ route('loyalty.redemptions.store') }}" class="grid gap-4 md:grid-cols-3">@csrf
<div><label for="customer_id" class="form-label">Cliente<span class="text-red-500">*</span></label><select name="customer_id" id="customer_id" class="form-input"><option value="">Seleccione un cliente</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>{{ $customer->name }}</option>@endforeach</select>@error('customer_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
<div><label for="reward_id" class="form-label">Premio<span class="text-red-500">*</span></label><select name="reward_id" id="reward_id" class="form-input"><option value="">Seleccione un premio</option>@foreach($rewards as $reward)<option value="{{ $reward->id }}" @selected((string) old('reward_id') === (string) $reward->id)>{{ $reward->name }} — {{ $reward->points_cost }} pts ({{ $modeLabels[$reward->availability_mode] }})</option>@endforeach</select>@error('reward_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
<div class="flex items-end"><button class="w-full rounded-lg bg-amber-500 px-5 py-2.5 font-semibold text-black hover:bg-amber-600">Canjear premio</button></div>
</form></x-card>
<x-card><x-slot:header><h2 class="text-lg font-semibold">Historial de canjes</h2></x-slot:header>
<div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500"><th class="py-2 pr-4">Fecha</th><th class="py-2 pr-4">Premio</th><th class="py-2 pr-4">Cliente</th><th class="py-2 pr-4">Puntos</th><th class="py-2 pr-4">Sucursal</th><th class="py-2 pr-4">Usuario</th><th class="py-2 pr-4">Movimiento</th></tr></thead><tbody>
@forelse($redemptions as $redemption)<tr class="border-b border-slate-100"><td class="py-2 pr-4 whitespace-nowrap">{{ $redemption->created_at->format('d/m/Y H:i') }}</td><td class="py-2 pr-4">{{ $redemption->reward_name }}<span class="ml-1 text-xs text-slate-400">{{ $modeLabels[$redemption->availability_mode] ?? '' }}</span></td><td class="py-2 pr-4">{{ $redemption->customer?->name }}</td><td class="py-2 pr-4 font-semibold text-slate-800">-{{ $redemption->points_cost }}</td><td class="py-2 pr-4">{{ $redemption->branch?->name }}</td><td class="py-2 pr-4">{{ $redemption->user?->name }}</td><td class="py-2 pr-4">#{{ $redemption->loyalty_movement_id }}</td></tr>
@empty<tr><td colspan="7" class="py-4 text-slate-500">No hay canjes registrados.</td></tr>@endforelse
</tbody></table></div>{{ $redemptions->links() }}
</x-card>
</div>
@endsection
