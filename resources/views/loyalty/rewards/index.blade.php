@extends('layouts.app')
@section('title', 'Premios de Fidelización')
@section('content')
<div class="space-y-6">
<div><h1 class="text-2xl font-semibold text-slate-800">Premios</h1><p class="text-sm text-slate-500">Premios directos canjeables con puntos. El costo se expresa en puntos con hasta cuatro decimales.</p></div>
@if(session('success'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>@endif
<x-card><x-slot:header><h2 class="text-lg font-semibold">Nuevo premio</h2></x-slot:header>
<form method="POST" action="{{ route('loyalty.rewards.store') }}" class="grid gap-4 md:grid-cols-2">@csrf
<x-input name="name" label="Nombre" :value="old('name')" required/>
<div><label for="type" class="form-label">Tipo<span class="text-red-500">*</span></label><select name="type" id="type" class="form-input">@foreach($types as $type)<option value="{{ $type }}" @selected(old('type') === $type)>{{ ['product' => 'Producto', 'discount' => 'Descuento', 'service' => 'Servicio', 'gift' => 'Regalo'][$type] }}</option>@endforeach</select>@error('type')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
<x-input name="points_cost" type="number" min="0.0001" step="0.0001" label="Costo en puntos" :value="old('points_cost', '1')" required/>
<x-input name="description" label="Descripción (opcional)" :value="old('description')"/>
<input type="hidden" name="is_active" value="1"><div class="md:col-span-2"><button class="rounded-lg bg-amber-500 px-5 py-2.5 font-semibold text-black">Crear premio</button></div></form></x-card>
<div class="space-y-4">@forelse($rewards as $reward)<x-card><form method="POST" action="{{ route('loyalty.rewards.update', $reward) }}" class="grid gap-3 md:grid-cols-6">@csrf @method('PUT')
<input name="name" value="{{ $reward->name }}" class="form-input"><select name="type" class="form-input">@foreach($types as $type)<option value="{{ $type }}" @selected($reward->type === $type)>{{ ['product' => 'Producto', 'discount' => 'Descuento', 'service' => 'Servicio', 'gift' => 'Regalo'][$type] }}</option>@endforeach</select><input name="points_cost" type="number" step="0.0001" value="{{ $reward->points_cost }}" class="form-input"><input name="description" value="{{ $reward->description }}" class="form-input md:col-span-2" placeholder="Descripción"><input type="hidden" name="is_active" value="{{ $reward->is_active ? 1 : 0 }}"><button class="rounded bg-slate-800 px-3 py-2 text-white">Guardar</button></form>
<form method="POST" action="{{ route('loyalty.rewards.toggle', $reward) }}" class="mt-3">@csrf @method('PATCH')<span class="mr-3 text-xs uppercase tracking-wide {{ $reward->is_active ? 'text-emerald-700' : 'text-slate-400' }}">{{ $reward->is_active ? 'Activo' : 'Inactivo' }}</span><button class="text-sm font-semibold {{ $reward->is_active ? 'text-red-600' : 'text-emerald-700' }}">{{ $reward->is_active ? 'Desactivar' : 'Activar' }}</button></form></x-card>@empty<p class="text-slate-500">No hay premios configurados.</p>@endforelse</div>{{ $rewards->links() }}
</div>
@endsection
