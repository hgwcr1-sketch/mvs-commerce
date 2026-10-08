@extends('cobros.layout')
@section('title', 'Administrar ruta — MVS CobrOS')
@section('content')
<div class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-2xl font-bold">{{ $routeModel->exists ? 'Editar ruta' : 'Crear ruta' }}</h1>
    @if($errors->any())
        <div role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif
    <form method="POST" action="{{ $routeModel->exists ? route('cobros.owner.routes.update', $routeModel) : route('cobros.owner.routes.store') }}" class="space-y-5 rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
        @csrf
        @if($routeModel->exists) @method('PUT') @endif
        <div>
            <label for="route-name" class="block text-sm font-semibold">Nombre de ruta</label>
            <input id="route-name" name="name" required maxlength="255" value="{{ old('name', $routeModel->name) }}" placeholder="Ej. San Ramón" class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3">
        </div>
        <div>
            <label for="route-active" class="block text-sm font-semibold">Estado</label>
            <select id="route-active" name="active" class="mt-1 min-h-[44px] w-full rounded-xl border border-slate-300 px-3">
                <option value="1" @selected((string) old('active', (int) $routeModel->active) === '1')>Activa</option>
                <option value="0" @selected((string) old('active', (int) $routeModel->active) === '0')>Inactiva</option>
            </select>
        </div>
        <fieldset>
            <legend class="text-sm font-semibold">Cobradores asignados</legend>
            <p class="mt-1 text-sm text-slate-600">Una persona puede estar asignada a varias rutas. Puede dejar la ruta sin asignar.</p>
            @foreach($collectors as $collector)
                <label class="mt-2 flex min-h-[44px] items-center gap-3 rounded-lg border border-slate-200 px-3 py-2">
                    <input type="checkbox" name="collector_ids[]" value="{{ $collector->id }}" @checked(in_array($collector->id, old('_token') ? old('collector_ids', []) : $selected)) class="h-5 w-5 shrink-0 rounded border-slate-300 text-primary focus:ring-primary">
                    <span class="min-w-0 break-words">{{ $collector->name }}</span>
                </label>
            @endforeach
            @if($collectors->isEmpty())<p class="mt-3 text-sm">No hay cobradores activos disponibles en su empresa.</p>@endif
        </fieldset>
        <p class="text-sm text-slate-600">El estado de la ruta y su licencia son independientes. Guardar no activa ni renueva licencias.</p>
        <div class="sticky bottom-24 flex flex-wrap gap-3 rounded-xl bg-white py-3">
            <button class="min-h-[44px] rounded-xl bg-primary px-5 font-semibold text-slate-950 hover:bg-primary-hover">Guardar ruta</button>
            <a href="{{ route('cobros.owner.routes') }}" class="inline-flex min-h-[44px] items-center rounded-xl border border-slate-300 px-4">Cancelar</a>
        </div>
    </form>
</div>
@endsection
