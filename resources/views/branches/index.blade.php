@extends('layouts.app')

@section('title', 'Sucursales')

@section('description', 'Administración de sucursales')

@section('content')

<div class="space-y-6">

    <div class="flex items-center justify-between">

        <div>
            <h1 class="text-2xl font-bold text-slate-800">
                Sucursales
            </h1>

            <p class="text-sm text-slate-500 mt-1">
                Administra las sucursales de la empresa.
            </p>
        </div>

        <a href="{{ route('branches.create') }}"
           class="bg-amber-500 hover:bg-amber-600 text-white font-semibold px-5 py-3 rounded-xl transition">
            + Nueva Sucursal
        </a>

    </div>

    @if(session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-5 py-4 rounded-xl">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">

        <div class="overflow-x-auto">

            <table class="min-w-full">

                <thead class="bg-slate-50">

                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-600">
                            Nombre
                        </th>

                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-600">
                            Código
                        </th>

                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-600">
                            Teléfono
                        </th>

                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-600">
                            Estado
                        </th>

                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-600">
                            Acciones
                        </th>
                    </tr>

                </thead>

                <tbody class="divide-y divide-slate-200">

                    @forelse($branches as $branch)

                        <tr>

                            <td class="px-6 py-4 font-semibold text-slate-800">
                                {{ $branch->name }}
                            </td>

                            <td class="px-6 py-4 text-slate-600">
                                {{ $branch->code }}
                            </td>

                            <td class="px-6 py-4 text-slate-600">
                                {{ $branch->phone ?? '—' }}
                            </td>

                            <td class="px-6 py-4">

                                @if($branch->is_active)

                                    <span class="inline-flex px-3 py-1 rounded-full bg-green-100 text-green-700 text-sm font-semibold">
                                        Activa
                                    </span>

                                @else

                                    <span class="inline-flex px-3 py-1 rounded-full bg-red-100 text-red-700 text-sm font-semibold">
                                        Inactiva
                                    </span>

                                @endif

                            </td>

                            <td class="px-6 py-4 text-right">

                                <a href="{{ route('branches.edit', $branch) }}"
                                   class="text-amber-600 hover:text-amber-700 font-semibold">
                                    Editar
                                </a>

                            </td>

                        </tr>

                    @empty

                        <tr>
                            <td colspan="5"
                                class="px-6 py-12 text-center text-slate-400">
                                No existen sucursales registradas.
                            </td>
                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

    </div>

</div>

@endsection