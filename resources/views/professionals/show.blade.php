@extends('layouts.app')

@section('title', 'Detalle profesional')
@section('description', 'Perfil profesional de BeautyOS')

@section('content')
<div class="mx-auto max-w-3xl space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $professional->user->name }}</h1>
            <p class="text-sm text-slate-500">Perfil profesional</p>
        </div>
        <a href="{{ route('professionals.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 px-4 font-semibold text-slate-700">Volver</a>
    </div>

    <x-card>
        <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div><dt class="text-sm font-semibold text-slate-500">Correo</dt><dd class="mt-1 break-all text-slate-900">{{ $professional->user->email }}</dd></div>
            <div><dt class="text-sm font-semibold text-slate-500">Teléfono</dt><dd class="mt-1 text-slate-900">{{ $professional->user->phone ?: 'No registrado' }}</dd></div>
            <div><dt class="text-sm font-semibold text-slate-500">Estado</dt><dd class="mt-1 text-slate-900">{{ $professional->is_active ? 'Activo' : 'Inactivo' }}</dd></div>
            <div><dt class="text-sm font-semibold text-slate-500">Sucursales</dt><dd class="mt-1 text-slate-900">{{ $professional->branches->pluck('name')->join(', ') }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-sm font-semibold text-slate-500">Especialidades</dt><dd class="mt-1 text-slate-900">{{ $professional->specialties->pluck('name')->join(', ') ?: 'Sin especialidades asignadas' }}</dd></div>
        </dl>

        @can('profesionales.editar')
            <div class="mt-6 border-t border-slate-200 pt-5">
                <a href="{{ route('professionals.edit', $professional) }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-amber-500 px-4 font-semibold text-slate-900 sm:w-auto">Editar profesional</a>
            </div>
        @endcan
    </x-card>
</div>
@endsection
