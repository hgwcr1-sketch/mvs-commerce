@extends('layouts.app')

@section('title', 'Usuarios')

@section('description', 'Administración de usuarios del sistema')

@section('content')

<x-card>

    <x-slot:header>

        <div class="flex items-center justify-between">

            <h1 class="text-2xl font-bold">
                Información del Usuario
            </h1>

            <a href="{{ route('usuarios.index') }}">

                <x-button color="secondary">
                    Volver
                </x-button>

            </a>

        </div>

    </x-slot:header>
    
    <div class="space-y-4">

        <p><strong>Nombre:</strong> {{ $usuario->name }}</p>

        <p><strong>Correo:</strong> {{ $usuario->email }}</p>

        <p><strong>Teléfono:</strong> {{ $usuario->phone }}</p>

        <p>
            
    <strong>Rol:</strong>

    @if($role)
        <x-badge type="warning">
            {{ $role->name }}
        </x-badge>
    @else
        Sin rol asignado
    @endif
</p>

        <p>
            <strong>Estado:</strong>

            @if($usuario->is_active)

                <x-badge type="success">
                    Activo
                </x-badge>

            @else

                <x-badge type="danger">
                    Inactivo
                </x-badge>

            @endif

        </p>

        <p>

            <strong>Último acceso:</strong>

            {{ $usuario->last_login_at?->format('d/m/Y H:i') ?? 'Nunca' }}

        </p>

        @if($usuario->photo)

            <img
                src="{{ asset('storage/'.$usuario->photo) }}"
                class="h-32 w-32 rounded-full border object-cover">

        @endif

    </div>

</x-card>

@endsection