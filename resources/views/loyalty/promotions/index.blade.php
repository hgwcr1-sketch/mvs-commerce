@extends('layouts.app')

@section('title', 'Promociones del portal de Fidelización')
@section('content')
<div class="space-y-6">
<div><h1 class="text-2xl font-semibold text-slate-800">Promociones del portal</h1><p class="text-sm text-slate-500">Contenido publicitario que sus clientes ven en el portal público de Fidelización. Solo se muestran promociones activas dentro de su periodo. Inicio y fin son inclusivos. Zona horaria: {{ $timezone }}.</p></div>
@if(session('success'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>@endif
@if($errors->any())<div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

<x-card><x-slot:header><h2 class="text-lg font-semibold">Nueva promoción</h2></x-slot:header>
<form method="POST" action="{{ route('loyalty.promotions.store') }}" class="grid gap-4 md:grid-cols-2">@csrf
<x-input name="title" label="Título" :value="old('title')" required maxlength="120"/>
<x-input name="sort_order" type="number" min="0" max="9999" label="Orden (menor aparece primero)" :value="old('sort_order', '0')"/>
<div class="md:col-span-2"><x-textarea name="description" label="Descripción corta (opcional)" :value="old('description')" maxlength="500" rows="2"/></div>
<x-input name="starts_at" type="datetime-local" label="Inicio" :value="old('starts_at')" required/><x-input name="ends_at" type="datetime-local" label="Fin" :value="old('ends_at')" required/>
<input type="hidden" name="is_active" value="1"><div class="md:col-span-2"><button class="rounded-lg bg-amber-500 px-5 py-2.5 font-semibold text-black">Crear promoción</button></div></form></x-card>

<div class="space-y-4">@forelse($promotions as $promotion)
@php $estado = $estados[(int) $promotion->id] ?? 'inactiva'; @endphp
<x-card><form method="POST" action="{{ route('loyalty.promotions.update', $promotion) }}" class="grid gap-3 md:grid-cols-6">@csrf @method('PUT')
<input name="title" value="{{ $promotion->title }}" maxlength="120" class="form-input md:col-span-2"><input name="sort_order" type="number" min="0" max="9999" value="{{ $promotion->sort_order }}" class="form-input">
<textarea name="description" maxlength="500" rows="2" placeholder="Descripción corta" class="form-input md:col-span-3">{{ $promotion->description }}</textarea>
<input name="starts_at" type="datetime-local" value="{{ $promotion->starts_at->timezone($timezone)->format('Y-m-d\TH:i') }}" class="form-input"><input name="ends_at" type="datetime-local" value="{{ $promotion->ends_at->timezone($timezone)->format('Y-m-d\TH:i') }}" class="form-input">
<div class="flex items-center justify-between gap-3 md:col-span-6"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold @if($estado === 'vigente') bg-emerald-100 text-emerald-800 @elseif($estado === 'futura') bg-sky-100 text-sky-800 @elseif($estado === 'vencida') bg-slate-200 text-slate-600 @else bg-amber-100 text-amber-800 @endif">{{ ucfirst($estado) }}</span><button class="rounded bg-slate-800 px-3 py-2 text-white">Guardar</button></div>
</form>
<form method="POST" action="{{ route('loyalty.promotions.toggle', $promotion) }}" class="mt-3">@csrf @method('PATCH')<button class="text-sm font-semibold {{ $promotion->is_active ? 'text-red-600' : 'text-emerald-700' }}">{{ $promotion->is_active ? 'Desactivar' : 'Activar' }}</button></form></x-card>
@empty<p class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500">No hay promociones configuradas. Cree la primera con el formulario superior.</p>@endforelse</div>{{ $promotions->links() }}
</div>
@endsection
