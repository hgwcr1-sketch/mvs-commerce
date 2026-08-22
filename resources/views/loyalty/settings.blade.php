@extends('layouts.app')
@section('title', 'Configuración de Fidelización')
@section('content')
<div class="space-y-6"><div><h1 class="text-2xl font-semibold text-slate-800">Plantillas de Fidelización</h1><p class="text-sm text-slate-500">Variables: {nombre}, {dias_sin_comprar}, {puntos}, {sucursal}.</p></div>
<x-card><form method="POST" action="{{ route('configuracion.loyalty-templates.update') }}" class="space-y-5">@csrf @method('PUT')
@foreach(['birthday'=>'Cumpleaños','inactive_30'=>'+30 días','inactive_60'=>'+60 días','inactive_90'=>'+90 días'] as $type=>$label)
<div><label class="mb-1 block text-sm font-semibold text-slate-700">{{ $label }}</label><textarea name="templates[{{ $type }}]" rows="3" class="form-input" required>{{ old("templates.$type", $loyaltyMessageTemplates[$type]) }}</textarea>@error("templates.$type")<p class="text-sm text-red-600">{{ $message }}</p>@enderror</div>
@endforeach
<button class="rounded-lg bg-amber-500 px-5 py-2.5 font-semibold text-black">Guardar plantillas</button></form></x-card></div>
@endsection
