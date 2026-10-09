@extends('cobros.layout')
@section('title', '¿Qué ruta vamos a trabajar hoy? — MVS CobrOS')
@section('content')
<div class="mx-auto max-w-lg space-y-4">
    <header>
        <h1 class="text-2xl font-bold text-slate-900">¿Qué ruta vamos a trabajar hoy?</h1>
        <p class="mt-1 text-sm text-slate-500">Trabaja dentro de una ruta a la vez. {{ $company->trade_name }}</p>
    </header>

    @if($errors->any())
        <div role="alert" class="rounded-xl bg-red-50 p-4 text-sm text-red-800">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    @if($routes->isEmpty())
        <div class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-600">
            No tiene rutas activas asignadas. Contacte al administrador de su empresa.
        </div>
    @else
        <div class="space-y-3" role="group" aria-label="Rutas disponibles">
            @foreach($routes as $route)
                <form method="POST" action="{{ route('cobros.routes.store') }}">
                    @csrf
                    <input type="hidden" name="route_id" value="{{ $route->id }}">
                    <button type="submit"
                        class="flex min-h-[56px] w-full items-center justify-between gap-3 rounded-xl border-2 border-primary bg-white px-5 py-4 text-left text-lg font-bold text-slate-900 hover:bg-primary/10">
                        <span>{{ $route->name }}</span>
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
                    </button>
                </form>
            @endforeach
        </div>
    @endif
</div>
@endsection