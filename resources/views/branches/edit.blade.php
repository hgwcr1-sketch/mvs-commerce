@extends('layouts.app')

@section('title', 'Editar Sucursal')

@section('description', 'Modificar información de la sucursal')

@section('content')

<div class="max-w-4xl">

    <div class="mb-6">

        <h1 class="text-2xl font-bold text-slate-800">
            Editar Sucursal
        </h1>

        <p class="text-sm text-slate-500 mt-1">
            Actualiza la información de la sucursal.
        </p>

    </div>

    <form method="POST"
          action="{{ route('branches.update', $branch) }}"
          class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-6">

        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">
                    Nombre *
                </label>

                <input type="text"
                       name="name"
                       value="{{ old('name', $branch->name) }}"
                       required
                       class="w-full rounded-xl border-slate-300">
            </div>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">
                    Código *
                </label>

                <input type="text"
                       name="code"
                       value="{{ old('code', $branch->code) }}"
                       required
                       class="w-full rounded-xl border-slate-300">
            </div>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">
                    Teléfono
                </label>

                <input type="text"
                       name="phone"
                       value="{{ old('phone', $branch->phone) }}"
                       class="w-full rounded-xl border-slate-300">
            </div>

            <div class="flex items-center pt-7">

                <label class="flex items-center gap-3 cursor-pointer">

                    <input type="checkbox"
                           name="is_active"
                           value="1"
                           @checked($branch->is_active)
                           class="rounded border-slate-300">

                    <span class="font-semibold text-slate-700">
                        Sucursal activa
                    </span>

                </label>

            </div>

        </div>

        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-2">
                Dirección
            </label>

            <textarea name="address"
                      rows="4"
                      class="w-full rounded-xl border-slate-300">{{ old('address', $branch->address) }}</textarea>
        </div>

        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <h2 class="font-semibold text-slate-800">Comprobantes de venta</h2>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <label class="text-sm font-semibold text-slate-700">Formato predeterminado
                    <select name="receipt_format" class="mt-2 min-h-11 w-full rounded-xl border-slate-300">
                        @foreach(['80mm' => 'Térmico 80 mm', '58mm' => 'Térmico 58 mm', 'letter' => 'Comprobante grande'] as $value => $label)<option value="{{ $value }}" @selected(old('receipt_format', $branch->receipt_format) === $value)>{{ $label }}</option>@endforeach
                    </select>
                </label>
                <label class="flex min-h-11 items-center gap-3 self-end text-sm font-semibold text-slate-700"><input type="checkbox" name="receipt_auto_print" value="1" @checked(old('receipt_auto_print', $branch->receipt_auto_print)) class="h-5 w-5 rounded border-slate-300"> Abrir diálogo de impresión automáticamente</label>
            </div>
        </div>

        <div class="flex items-center gap-3 pt-4 border-t border-slate-200">

            <button type="submit"
                    class="bg-amber-500 hover:bg-amber-600 text-white font-semibold px-6 py-3 rounded-xl transition">
                Guardar Cambios
            </button>

            <a href="{{ route('branches.index') }}"
               class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold px-6 py-3 rounded-xl transition">
                Cancelar
            </a>

        </div>

    </form>

</div>

@endsection
