@extends('layouts.app')

@section('title', 'Cajas')
@section('description', 'Administra las cajas o terminales físicas disponibles por sucursal.')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-slate-800">Cajas</h2>
            <p class="mt-1 text-sm text-slate-600">Cajas o terminales físicas disponibles por sucursal.</p>
        </div>
        <div class="flex justify-end gap-3">
            <a href="{{ route('configuracion.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 font-medium text-slate-700 hover:bg-slate-100">Volver</a>
            <a href="{{ route('settings.cash-registers.create') }}" class="rounded-lg bg-amber-500 px-4 py-2 font-semibold text-white hover:bg-amber-600">+ Nueva caja</a>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-red-700">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-red-700">{{ $errors->first() }}</div>
    @endif

    @unless($cashSettings->allow_multiple_registers)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-800">Actualmente se permite una caja activa por sucursal.</div>
    @endunless

    <x-card>
        <form method="GET" action="{{ route('settings.cash-registers.index') }}" class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="w-full sm:max-w-sm">
                <label for="branch_id" class="mb-1 block text-sm font-medium text-slate-700">Filtrar por sucursal</label>
                <select id="branch_id" name="branch_id" class="w-full rounded-xl border border-slate-300 px-4 py-2 focus:border-amber-500 focus:ring-0">
                    <option value="">Todas las sucursales</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($selectedBranchId === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="rounded-xl bg-slate-700 px-4 py-2 font-semibold text-white hover:bg-slate-800">Filtrar</button>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="border-b bg-slate-100"><tr>
                    <th class="px-4 py-3 text-left">Sucursal</th><th class="px-4 py-3 text-left">Código</th><th class="px-4 py-3 text-left">Nombre</th><th class="px-4 py-3 text-center">Predeterminada</th><th class="px-4 py-3 text-center">Estado</th><th class="px-4 py-3 text-center">Acciones</th>
                </tr></thead>
                <tbody>
                    @forelse($cashRegisters as $cashRegister)
                        <tr class="border-b hover:bg-slate-50">
                            <td class="px-4 py-3">{{ $cashRegister->branch->name }}</td>
                            <td class="px-4 py-3 font-mono text-sm">{{ $cashRegister->code }}</td>
                            <td class="px-4 py-3 font-medium">{{ $cashRegister->name }}</td>
                            <td class="px-4 py-3 text-center">{{ $cashRegister->is_default ? 'Sí' : 'No' }}</td>
                            <td class="px-4 py-3 text-center"><span class="rounded-full px-3 py-1 text-xs font-semibold {{ $cashRegister->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">{{ $cashRegister->is_active ? 'Activa' : 'Inactiva' }}</span></td>
                            <td class="px-4 py-3"><div class="flex flex-wrap justify-center gap-2">
                                <a href="{{ route('settings.cash-registers.edit', $cashRegister) }}" class="rounded-lg bg-blue-500 px-3 py-1 text-sm font-semibold text-white hover:bg-blue-600">Editar</a>
                                <form method="POST" action="{{ route('settings.cash-registers.toggle-status', $cashRegister) }}">@csrf @method('PATCH')
                                    <button class="rounded-lg bg-slate-600 px-3 py-1 text-sm font-semibold text-white hover:bg-slate-700">{{ $cashRegister->is_active ? 'Desactivar' : 'Activar' }}</button>
                                </form>
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No hay cajas registradas para el filtro seleccionado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $cashRegisters->links() }}</div>
    </x-card>
</div>
@endsection
