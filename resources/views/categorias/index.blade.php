@extends('layouts.app')

@section('title', 'Categorías')

@section('description', 'Administra las categorías de tus productos.')

@section('content')

<div class="space-y-6">

{{-- Encabezado --}}
<div class="flex justify-end gap-3">

    <a
        href="{{ route('productos.index') }}"
        class="rounded-lg border border-slate-300 px-4 py-2 font-medium hover:bg-slate-100">
        Volver
    </a>

    <x-button onclick="window.location='{{ route('categorias.create') }}'">

+ Nueva Categoría
    </x-button>

</div>

    {{-- Mensaje --}}
    @if(session('success'))

        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-green-700">

            {{ session('success') }}

        </div>

    @endif

    <x-card>

        <x-slot:header>

            <div class="flex items-center justify-between">

                <h2 class="text-lg font-semibold">

                    Listado de Categorías

                </h2>

                <form method="GET" action="{{ route('categorias.index') }}" class="mb-6">

    <div class="flex gap-3">

        <input
            type="text"
            name="search"
            value="{{ $search }}"
            placeholder="Buscar categoría..."
            class="flex-1 rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0">

        <button
            type="submit"
            class="rounded-xl bg-amber-500 px-6 py-3 font-semibold text-white hover:bg-amber-600 transition">

            Buscar

        </button>

        @if($search)

            <a href="{{ route('categorias.index') }}"
               class="rounded-xl border border-slate-300 px-6 py-3 hover:bg-slate-100 transition">

                Limpiar

            </a>

        @endif

    </div>

</form>
            </div>

        </x-slot:header>

        <div class="overflow-x-auto">

            <table class="min-w-full">

                <thead class="border-b bg-slate-100">

                    <tr>

                        <th class="px-4 py-3 text-left">Nombre</th>

                        <th class="px-4 py-3 text-left">Categoría Padre</th>

                        <th class="px-4 py-3 text-center">Orden</th>

                        <th class="px-4 py-3 text-center">Estado</th>
                        <th class="px-4 py-3 text-center">Acciones</th>

                    </tr>

                </thead>

                <tbody>

                    @forelse($categories as $category)

                        <tr class="border-b hover:bg-slate-50">

                            <td class="px-4 py-3 font-medium">

                                {{ $category->name }}

                            </td>

                            <td class="px-4 py-3">

                                {{ $category->parent?->name ?? '—' }}

                            </td>

                            <td class="px-4 py-3 text-center">

                                {{ $category->sort_order }}

                            </td>

                            <td class="px-4 py-3 text-center">

                                @if($category->is_active)

                                    <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700">
                                        Activa
                                    </span>

                                @else

                                    <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">
                                        Inactiva
                                    </span>

                                @endif

                            </td>

                            <td class="px-4 py-3 text-center">

                                <div class="flex justify-center gap-2">

                                    <a
                                        href="{{ route('categorias.edit', $category) }}"
                                        class="rounded-lg bg-blue-500 px-3 py-1 text-sm font-semibold text-white hover:bg-blue-600">

                                        Editar

                                    </a>

                                    <form
                                        action="{{ route('categorias.destroy', $category) }}"
                                        method="POST"
                                        onsubmit="return confirm('¿Desea eliminar esta categoría?')">

                                        @csrf
                                        @method('DELETE')

                                        <button
                                            class="rounded-lg bg-red-500 px-3 py-1 text-sm font-semibold text-white hover:bg-red-600">

                                            Eliminar

                                        </button>

                                    </form>

                                </div>

                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td colspan="5" class="py-10 text-center text-slate-500">

                                No hay categorías registradas.

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

        <x-slot:footer>

            {{ $categories->links() }}

        </x-slot:footer>

    </x-card>

</div>

@endsection