@extends('layouts.app')
@section('title', 'Nueva caja')
@section('description', 'Registra una terminal o gaveta física para una sucursal.')
@section('content')
<div class="mx-auto max-w-5xl"><x-card><x-slot:header><div class="flex items-center justify-between"><h2 class="text-xl font-semibold text-slate-800">Nueva caja</h2><a href="{{ route('settings.cash-registers.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 font-medium text-slate-700 hover:bg-slate-100">Volver</a></div></x-slot:header>
<form action="{{ route('settings.cash-registers.store') }}" method="POST">@include('settings.cash-registers._form')</form></x-card></div>
@endsection
