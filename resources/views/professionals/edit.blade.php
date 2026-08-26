@extends('layouts.app')

@section('title', 'Editar profesional')
@section('description', 'Actualizar perfil profesional de BeautyOS')

@section('content')
<div class="mx-auto max-w-4xl">
    <x-card>
        <x-slot:header><h1 class="text-xl font-bold text-slate-900 sm:text-2xl">Editar profesional</h1></x-slot:header>
        <form method="POST" action="{{ route('professionals.update', $professional) }}">
            @method('PUT')
            @include('professionals.form')
        </form>
    </x-card>
</div>
@endsection
