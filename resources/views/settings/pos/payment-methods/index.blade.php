@extends('layouts.app')

@section('title', 'Formas de pago')
@section('description', 'Administra los métodos disponibles para el cobro en el POS.')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-slate-800">Formas de pago</h2>
            <p class="mt-1 text-sm text-slate-600">Métodos disponibles para el cobro en el POS.</p>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('configuracion.index') }}"
               class="rounded-lg border border-slate-300 px-4 py-2 font-medium text-slate-700 hover:bg-slate-100">
                Volver
            </a>
            <a href="{{ route('settings.pos.payment-methods.create') }}"
               class="rounded-lg bg-amber-500 px-4 py-2 font-semibold text-white hover:bg-amber-600">
                + Nueva forma de pago
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-green-700">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-red-700">
            {{ session('error') }}
        </div>
    @endif

    <x-card>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="border-b bg-slate-100">
                    <tr>
                        <th class="px-4 py-3 text-center">Orden</th>
                        <th class="px-4 py-3 text-left">Nombre</th>
                        <th class="px-4 py-3 text-left">Código</th>
                        <th class="px-4 py-3 text-left">Tipo</th>
                        <th class="px-4 py-3 text-center">Afecta caja</th>
                        <th class="px-4 py-3 text-center">Referencia</th>
                        <th class="px-4 py-3 text-center">Vuelto</th>
                        <th class="px-4 py-3 text-center">Estado</th>
                        <th class="px-4 py-3 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($paymentMethods as $paymentMethod)
                        <tr class="border-b {{ $paymentMethod->is_system ? 'bg-amber-50/60' : 'hover:bg-slate-50' }}">
                            <td class="px-4 py-3 text-center">{{ $paymentMethod->sort_order }}</td>
                            <td class="px-4 py-3 font-medium">
                                {{ $paymentMethod->name }}
                                @if($paymentMethod->is_system)
                                    <span class="ml-2 rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-700">Sistema</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-sm">{{ $paymentMethod->code }}</td>
                            <td class="px-4 py-3">{{ str($paymentMethod->type)->replace('_', ' ')->title() }}</td>
                            <td class="px-4 py-3 text-center">{{ $paymentMethod->affects_cash ? 'Sí' : 'No' }}</td>
                            <td class="px-4 py-3 text-center">{{ $paymentMethod->requires_reference ? 'Sí' : 'No' }}</td>
                            <td class="px-4 py-3 text-center">{{ $paymentMethod->allows_change ? 'Sí' : 'No' }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $paymentMethod->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $paymentMethod->is_active ? 'Activa' : 'Inactiva' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-center gap-2">
                                    <a href="{{ route('settings.pos.payment-methods.edit', $paymentMethod) }}"
                                       class="rounded-lg bg-blue-500 px-3 py-1 text-sm font-semibold text-white hover:bg-blue-600">
                                        Editar
                                    </a>

                                    <form action="{{ route('settings.pos.payment-methods.toggle-status', $paymentMethod) }}" method="POST">
                                        @csrf
                                        @method('PATCH')
                                        <button class="rounded-lg bg-slate-600 px-3 py-1 text-sm font-semibold text-white hover:bg-slate-700">
                                            {{ $paymentMethod->is_active ? 'Desactivar' : 'Activar' }}
                                        </button>
                                    </form>

                                    @unless($paymentMethod->is_system)
                                        <form action="{{ route('settings.pos.payment-methods.destroy', $paymentMethod) }}"
                                              method="POST"
                                              onsubmit="return confirm('¿Desea eliminar esta forma de pago? Esta acción no se puede deshacer.')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="rounded-lg bg-red-500 px-3 py-1 text-sm font-semibold text-white hover:bg-red-600">
                                                Eliminar
                                            </button>
                                        </form>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-10 text-center text-slate-500">
                                No hay formas de pago registradas.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($paymentMethods->hasPages())
            <x-slot:footer>{{ $paymentMethods->links() }}</x-slot:footer>
        @endif
    </x-card>
</div>
@endsection
