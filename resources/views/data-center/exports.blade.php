@extends('layouts.app')

@section('title', 'Exportar — Centro de Datos')
@section('description', 'Exportadores esenciales de datos empresariales.')

@section('content')
<div class="mx-auto max-w-6xl space-y-6" data-data-center-exports>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-amber-700">Centro de Datos</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-800">Exportar</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Genere archivos verificables de la empresa activa. Cada bloque respeta el permiso de su módulo.</p>
        </div>
        <a href="{{ route('data-center.index') }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 sm:w-auto">Volver al Centro</a>
    </header>

    @include('data-center._navigation')

    @if($datasets->isEmpty())
        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
            Tiene permiso para entrar al área, pero necesita acceso de lectura al módulo que desea exportar.
        </section>
    @else
        <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" aria-label="Exportadores disponibles">
            @foreach($datasets as $key => $definition)
                <article class="flex min-h-56 flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-export-dataset="{{ $key }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">{{ $definition['branch'] ? 'Por sucursal' : 'Toda la empresa' }}</p>
                            <h2 class="mt-1 text-lg font-bold text-slate-800">{{ $definition['label'] }}</h2>
                        </div>
                        <span class="rounded-xl bg-blue-50 px-3 py-2 text-sm font-bold text-blue-700" aria-hidden="true">↓</span>
                    </div>

                    <form class="mt-auto space-y-3 pt-5" method="GET" action="{{ route('data-center.exports.download', [$key, 'xlsx']) }}">
                        @if($definition['branch'])
                            <label class="block text-sm font-semibold text-slate-700">
                                Sucursal
                                <select name="branch_id" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                    @foreach($key === 'inventory' ? $inventoryBranches : $branches as $branch)
                                        <option value="{{ $branch->id }}" @selected($branch->id === (int) session('active_branch_id'))>{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endif

                        <div class="grid grid-cols-2 gap-2">
                            <button type="submit" class="min-h-11 rounded-xl bg-slate-800 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-700">XLSX</button>
                            <button type="submit" formaction="{{ route('data-center.exports.download', [$key, 'csv']) }}" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">CSV</button>
                        </div>
                    </form>
                </article>
            @endforeach
        </section>
    @endif
</div>
@endsection
