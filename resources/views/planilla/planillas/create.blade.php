@extends('layouts.app')

@section('title', 'Crear Planilla')

@section('description', 'Nueva planilla')

@section('content')

<div class="space-y-6">

    <div class="flex justify-between items-center">
        <h1 class="text-2xl font-bold text-slate-800">Nueva planilla</h1>
    </div>

    <x-card>
        <form method="POST" action="{{ route('planilla.planillas.store') }}" class="space-y-6">
            @csrf

            {{-- Número de planilla --}}
            <div>
                <label for="payroll_number" class="form-label">Número de planilla <span class="text-red-500">*</span></label>
                <input type="text"
                    id="payroll_number"
                    name="payroll_number"
                    value="{{ old('payroll_number') }}"
                    class="form-input w-full mt-1 @error('payroll_number') border-red-500 @enderror"
                    required>
                @error('payroll_number')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Fechas --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="period_start" class="form-label">Inicio de período <span class="text-red-500">*</span></label>
                    <input type="date"
                        id="period_start"
                        name="period_start"
                        value="{{ old('period_start') }}"
                        class="form-input w-full mt-1 @error('period_start') border-red-500 @enderror"
                        required>
                    @error('period_start')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="period_end" class="form-label">Fin de período <span class="text-red-500">*</span></label>
                    <input type="date"
                        id="period_end"
                        name="period_end"
                        value="{{ old('period_end') }}"
                        class="form-input w-full mt-1 @error('period_end') border-red-500 @enderror"
                        required>
                    @error('period_end')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Frecuencia --}}
            <div>
                <label for="frequency" class="form-label">Frecuencia <span class="text-red-500">*</span></label>
                <select id="frequency" name="frequency" class="form-select w-full mt-1 @error('frequency') border-red-500 @enderror" required>
                    <option value="">Seleccionar frecuencia</option>
                    <option value="semanal" {{ old('frequency') === 'semanal' ? 'selected' : '' }}>Semanal</option>
                    <option value="quincenal" {{ old('frequency') === 'quincenal' ? 'selected' : '' }}>Quincenal</option>
                    <option value="mensual" {{ old('frequency') === 'mensual' ? 'selected' : '' }}>Mensual</option>
                </select>
                @error('frequency')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Botones --}}
            <div class="flex justify-end gap-3 pt-4 border-t border-slate-200">
                <a href="{{ route('planilla.planillas.index') }}">
                    <x-button type="button" variant="secondary">
                        Cancelar
                    </x-button>
                </a>
                <x-button type="submit">
                    Guardar
                </x-button>
            </div>
        </form>
    </x-card>

</div>

@endsection