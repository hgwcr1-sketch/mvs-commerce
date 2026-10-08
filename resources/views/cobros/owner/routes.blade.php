@extends('cobros.layout')
@section('title', 'Rutas — MVS CobrOS')
@section('content')
<div class="space-y-5">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div><h1 class="text-2xl font-bold">Rutas</h1><p class="mt-1 text-sm">Organice sus rutas y los cobradores responsables.</p></div>
        <a href="{{ route('cobros.owner.routes.create') }}" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-primary px-5 font-semibold text-slate-950 hover:bg-primary-hover">Crear ruta</a>
    </header>
    <p class="rounded-xl border border-primary bg-white p-4 text-sm">Crear una ruta no activa su licencia. La activación se gestiona con MVS.</p>
    <section class="grid min-w-0 gap-4 md:grid-cols-2" aria-label="Rutas de la empresa">
        @forelse($routes as $routeModel)
            <article class="min-w-0 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="break-words text-lg font-bold">{{ $routeModel->name }}</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div><dt class="inline font-semibold">Estado:</dt> <dd class="inline">{{ $routeModel->active ? 'Activa' : 'Inactiva' }}</dd></div>
                    <div><dt class="inline font-semibold">Cobradores asignados:</dt> <dd class="inline break-words">{{ $routeModel->users->pluck('name')->join(', ') ?: 'Sin asignar' }}</dd></div>
                    <div><dt class="inline font-semibold">Licencia:</dt> <dd class="inline">{{ match($licenses->stateOf($routeModel->license, $company)) {
                        'active' => 'Activa', 'suspended' => 'Inactiva (suspendida)', 'expired' => 'Inactiva (vencida)',
                        'pending' => 'Pendiente de inicio', default => 'Pendiente de activación MVS',
                    } }}</dd></div>
                    <div><dt class="inline font-semibold">Clientes vinculados:</dt> <dd class="inline">{{ $routeModel->assignments_count }}</dd></div>
                </dl>
                <a href="{{ route('cobros.owner.routes.edit', $routeModel) }}" class="mt-4 inline-flex min-h-[44px] items-center rounded-xl bg-primary px-4 font-semibold text-slate-950 hover:bg-primary-hover">Editar ruta</a>
            </article>
        @empty
            <p class="rounded-xl bg-white p-5">Todavía no hay rutas. Cree la primera para organizar su equipo.</p>
        @endforelse
    </section>
    {{ $routes->links() }}
</div>
@endsection
