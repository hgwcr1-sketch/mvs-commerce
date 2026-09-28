@extends('layouts.app')
@section('title', 'Multiplicadores de Fidelización')
@section('content')
<div class="space-y-6">
<div><h1 class="text-2xl font-semibold text-slate-800">Multiplicadores</h1><p class="text-sm text-slate-500">Se aplica únicamente el mayor factor vigente. Inicio y fin son inclusivos. Zona horaria: {{ $timezone }}.</p></div>
@if(session('success'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>@endif
<x-card><x-slot:header><h2 class="text-lg font-semibold">Nuevo multiplicador</h2></x-slot:header>
<form method="POST" action="{{ route('loyalty.multipliers.store') }}" class="grid gap-4 md:grid-cols-2">@csrf
<x-input name="name" label="Nombre" :value="old('name')" required/><x-input name="multiplier" type="number" min="0.0001" max="10" step="0.0001" label="Factor" :value="old('multiplier', '2')" required/>
<div><label class="form-label">Sucursal</label><select name="branch_id" class="form-input"><option value="">Todas</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div>
<div></div><x-input name="starts_at" type="datetime-local" label="Inicio" :value="old('starts_at')" required/><x-input name="ends_at" type="datetime-local" label="Fin" :value="old('ends_at')" required/>
<input type="hidden" name="is_active" value="1"><div class="md:col-span-2"><button class="rounded-lg bg-amber-500 px-5 py-2.5 font-semibold text-black">Crear multiplicador</button></div></form></x-card>
<div class="space-y-4">@forelse($multipliers as $multiplier)<x-card><form method="POST" action="{{ route('loyalty.multipliers.update', $multiplier) }}" class="grid gap-3 md:grid-cols-6">@csrf @method('PUT')
<input name="name" value="{{ $multiplier->name }}" class="form-input"><input name="multiplier" type="number" step="0.0001" value="{{ $multiplier->multiplier }}" class="form-input"><select name="branch_id" class="form-input"><option value="">Todas</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($multiplier->branch_id===$branch->id)>{{ $branch->name }}</option>@endforeach</select><input name="starts_at" type="datetime-local" value="{{ $multiplier->starts_at->timezone($timezone)->format('Y-m-d\TH:i') }}" class="form-input"><input name="ends_at" type="datetime-local" value="{{ $multiplier->ends_at->timezone($timezone)->format('Y-m-d\TH:i') }}" class="form-input"><input type="hidden" name="is_active" value="{{ $multiplier->is_active ? 1 : 0 }}"><button class="rounded bg-slate-800 px-3 py-2 text-white">Guardar</button></form>
<form method="POST" action="{{ route('loyalty.multipliers.toggle', $multiplier) }}" class="mt-3">@csrf @method('PATCH')<button class="text-sm font-semibold {{ $multiplier->is_active ? 'text-red-600' : 'text-emerald-700' }}">{{ $multiplier->is_active ? 'Desactivar' : 'Activar' }}</button></form></x-card>@empty<p class="text-slate-500">No hay multiplicadores configurados.</p>@endforelse</div>{{ $multipliers->links() }}
</div>
@endsection
