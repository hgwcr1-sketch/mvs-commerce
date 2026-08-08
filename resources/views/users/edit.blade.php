@extends('layouts.app')

@section('title', 'Usuarios')

@section('description', 'Administración de usuarios del sistema')

@section('content')

<x-card>

    <x-slot:header>

        <h1 class="text-2xl font-bold">
            Editar Usuario
        </h1>

    </x-slot:header>

    <form
        action="{{ route('usuarios.update',$usuario) }}"
        method="POST"
        enctype="multipart/form-data">

        @csrf
        @method('PUT')

        @include('users.form')

    </form>

</x-card>

@endsection