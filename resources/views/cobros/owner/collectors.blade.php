@extends('cobros.layout')
@section('title', 'Cobradores — MVS CobrOS')
@section('content')
<div class="space-y-6">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <a href="{{ route('cobros.owner.dashboard') }}" class="text-sm font-semibold text-amber-700">← Inicio / Cartera</a>
            <h1 class="mt-2 text-2xl font-bold sm:text-3xl">Cobradores</h1>
            <p class="mt-1 text-sm text-slate-500">
                @if($contract['license_mode'] === 'individual')
                    Licencias individuales — cada cobrador tiene su licencia propia.
                @else
                    Licencia por empresa + cupo de cobradores.
                @endif
            </p>
        </div>
        <div class="flex items-center gap-3">
            @if($contract['license_mode'] === 'company_cupo')
                <span class="rounded-full {{ $canCreate ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }} px-3 py-2 text-sm font-bold">
                    {{ $collectorCount }} / {{ $contract['collector_limit'] }} cobradores
                </span>
            @else
                <span class="rounded-full bg-slate-100 px-3 py-2 text-sm font-bold text-slate-800">
                    {{ $collectorCount }} cobradores vinculados
                </span>
            @endif
        </div>
    </header>

    @if($contract['license_mode'] === 'individual')
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
            <h2 class="font-bold text-amber-900">Modalidad de licencias individuales</h2>
            <p class="mt-2 text-sm text-amber-800">Los cobradores son habilitados individualmente desde el Panel Maestro. Cada cobrador tiene su propia licencia con MVS.</p>
            <p class="mt-1 text-sm text-amber-700">Desde aquí puede ver los cobradores vinculados a su empresa.</p>
        </div>
    @endif

    @if($contract['license_mode'] === 'company_cupo' && $canCreate)
        <form method="POST" action="{{ route('cobros.owner.collectors.store') }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            @csrf
            <h2 class="font-bold">Registrar cobrador</h2>
            <p class="mt-2 text-sm text-slate-600">Cupos restantes: <strong>{{ $remainingSlots }}</strong></p>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <label class="text-sm font-semibold">
                    Nombre
                    <input type="text" name="name" required class="mt-2 min-h-11 w-full rounded-xl border px-4" placeholder="Nombre del cobrador">
                </label>
                <label class="text-sm font-semibold">
                    Correo electrónico
                    <input type="email" name="email" required class="mt-2 min-h-11 w-full rounded-xl border px-4" placeholder="correo@ejemplo.com">
                </label>
                <label class="text-sm font-semibold">
                    Teléfono
                    <input type="text" name="phone" class="mt-2 min-h-11 w-full rounded-xl border px-4" placeholder="Opcional">
                </label>
                <label class="text-sm font-semibold">
                    Contraseña
                    <input type="text" name="password" class="mt-2 min-h-11 w-full rounded-xl border px-4" placeholder="Dejar vacío para generar automática">
                    <span class="mt-1 block text-xs text-slate-500">Si se deja vacío, se genera una contraseña de 16 caracteres.</span>
                </label>
            </div>
            @if($branches->count())
                <div class="mt-4">
                    <span class="text-sm font-semibold">Sucursales asignadas</span>
                    <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2 md:grid-cols-3">
                        @foreach($branches as $branch)
                            <label class="flex items-center gap-2 rounded-lg border p-3 text-sm">
                                <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" class="h-4 w-4">
                                {{ $branch->name }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="mt-5">
                <button class="min-h-11 rounded-xl bg-amber-500 px-5 font-bold">Registrar cobrador</button>
            </div>
        </form>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-bold">Cobradores registrados</h2>
        @forelse($collectors as $collector)
            <div class="mt-4 rounded-xl border p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <strong class="text-lg">{{ $collector['name'] }}</strong>
                        <span class="ml-2 rounded-full {{ $collector['is_active'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }} px-2 py-1 text-xs font-bold">
                            {{ $collector['is_active'] ? 'Activo' : 'Inactivo' }}
                        </span>
                        <p class="mt-1 text-sm text-slate-500">{{ $collector['email'] }}</p>
                        @if($collector['role'])
                            <p class="mt-1 text-xs text-slate-400">Rol: {{ $collector['role'] }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if($contract['license_mode'] === 'company_cupo')
                            <form method="POST" action="{{ route('cobros.owner.collectors.toggle', $collector['id']) }}">
                                @csrf
                                @method('PATCH')
                                <button class="min-h-10 rounded-lg border px-4 text-sm font-semibold {{ $collector['is_active'] ? 'border-red-300 text-red-700 hover:bg-red-50' : 'border-emerald-300 text-emerald-700 hover:bg-emerald-50' }}">
                                    {{ $collector['is_active'] ? 'Desactivar' : 'Activar' }}
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
                @if(count($collector['branches']))
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($collector['branches'] as $branchId => $branchName)
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{{ $branchName }}</span>
                        @endforeach
                    </div>
                @else
                    <p class="mt-3 text-sm text-slate-400">Sin sucursales asignadas.</p>
                @endif
            </div>
        @empty
            <p class="mt-4 text-sm text-slate-500">No hay cobradores registrados.</p>
        @endforelse
    </section>
</div>
@endsection
