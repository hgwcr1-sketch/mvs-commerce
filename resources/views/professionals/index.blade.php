@extends('layouts.app')

@section('title', 'Profesionales')
@section('description', 'Equipo profesional de BeautyOS')

@section('content')
<div class="space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Profesionales</h1>
            <p class="mt-1 text-sm text-slate-500">Administra quién atiende y en cuáles sucursales.</p>
        </div>
        @can('profesionales.crear')
            <a href="{{ route('professionals.create') }}" class="w-full sm:w-auto">
                <x-button color="primary" class="min-h-11 w-full sm:w-auto">+ Nuevo profesional</x-button>
            </a>
        @endcan
    </div>

    @if(session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">{{ session('success') }}</div>
    @endif

    <form method="GET" action="{{ route('professionals.index') }}" class="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-4 md:grid-cols-4">
        <input name="search" value="{{ request('search') }}" placeholder="Nombre, correo o teléfono" class="min-h-11 rounded-lg border border-slate-300 px-4 md:col-span-2">
        <select name="branch_id" class="min-h-11 rounded-lg border border-slate-300 px-3">
            <option value="">Todas las sucursales</option>
            @foreach($branches as $branch)
                <option value="{{ $branch->id }}" @selected((string) request('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </select>
        <select name="status" class="min-h-11 rounded-lg border border-slate-300 px-3">
            <option value="">Todos los estados</option>
            <option value="1" @selected(request('status') === '1')>Activos</option>
            <option value="0" @selected(request('status') === '0')>Inactivos</option>
        </select>
        <div class="flex gap-2 md:col-span-4 md:justify-end">
            <a href="{{ route('professionals.index') }}" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-lg border border-slate-300 px-4 font-semibold text-slate-700 md:flex-none">Limpiar</a>
            <x-button type="submit" color="secondary" class="min-h-11 flex-1 md:flex-none">Filtrar</x-button>
        </div>
    </form>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        @forelse($professionals as $professional)
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="truncate text-lg font-bold text-slate-900">{{ $professional->user->name }}</h2>
                        <p class="truncate text-sm text-slate-500">{{ $professional->user->email }}</p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $professional->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">
                        {{ $professional->is_active ? 'Activo' : 'Inactivo' }}
                    </span>
                </div>

                <div class="mt-4 space-y-3 text-sm">
                    <div>
                        <p class="font-semibold text-slate-700">Sucursales</p>
                        <p class="text-slate-600">{{ $professional->branches->pluck('name')->join(', ') }}</p>
                    </div>
                    <div>
                        <p class="font-semibold text-slate-700">Especialidades</p>
                        <p class="text-slate-600">{{ $professional->specialties->pluck('name')->join(', ') ?: 'Sin especialidades asignadas' }}</p>
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <a href="{{ route('professionals.show', $professional) }}" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-slate-700 px-4 font-semibold text-white">Ver</a>
                    @can('profesionales.editar')
                        <a href="{{ route('professionals.edit', $professional) }}" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 px-4 font-semibold text-slate-700">Editar</a>
                    @endcan
                    @can('profesionales.eliminar')
                        <form method="POST" action="{{ route('professionals.destroy', $professional) }}" onsubmit="return confirm('¿Eliminar este perfil profesional? El usuario Core se conservará.')">
                            @csrf
                            @method('DELETE')
                            <x-button type="submit" color="danger" class="min-h-11 w-full">Eliminar perfil</x-button>
                        </form>
                    @endcan
                </div>
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500 lg:col-span-2">
                No hay profesionales que coincidan con los filtros.
            </div>
        @endforelse
    </div>

    {{ $professionals->links() }}
</div>
@endsection
