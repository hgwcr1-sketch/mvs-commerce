@extends('layouts.app')

@section('title', 'Crear Empleado')

@section('description', 'Nuevo empleado para planilla')

@section('content')

<div class="space-y-6">

    <div class="flex justify-between items-center">
        <h1 class="text-2xl font-bold text-slate-800">Nuevo empleado</h1>
    </div>

    <x-card>
        <form method="POST" action="{{ route('planilla.empleados.store') }}" class="space-y-6">
            @csrf

            {{-- Código de empleado --}}
            <div>
                <label for="employee_code" class="form-label">Código de empleado <span class="text-red-500">*</span></label>
                <input type="text"
                    id="employee_code"
                    name="employee_code"
                    value="{{ old('employee_code') }}"
                    class="form-input w-full mt-1 @error('employee_code') border-red-500 @enderror"
                    required>
                @error('employee_code')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Identificación --}}
            <div>
                <label for="identification" class="form-label">Identificación <span class="text-red-500">*</span></label>
                <input type="text"
                    id="identification"
                    name="identification"
                    value="{{ old('identification') }}"
                    class="form-input w-full mt-1 @error('identification') border-red-500 @enderror"
                    required>
                @error('identification')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Nombres --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="first_name" class="form-label">Nombres <span class="text-red-500">*</span></label>
                    <input type="text"
                        id="first_name"
                        name="first_name"
                        value="{{ old('first_name') }}"
                        class="form-input w-full mt-1 @error('first_name') border-red-500 @enderror"
                        required>
                    @error('first_name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="last_name" class="form-label">Apellidos <span class="text-red-500">*</span></label>
                    <input type="text"
                        id="last_name"
                        name="last_name"
                        value="{{ old('last_name') }}"
                        class="form-input w-full mt-1 @error('last_name') border-red-500 @enderror"
                        required>
                    @error('last_name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Cargo --}}
            <div>
                <label for="position" class="form-label">Cargo <span class="text-red-500">*</span></label>
                <input type="text"
                    id="position"
                    name="position"
                    value="{{ old('position') }}"
                    class="form-input w-full mt-1 @error('position') border-red-500 @enderror"
                    required>
                @error('position')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Fecha de contratación --}}
            <div>
                <label for="hire_date" class="form-label">Fecha de contratación <span class="text-red-500">*</span></label>
                <input type="date"
                    id="hire_date"
                    name="hire_date"
                    value="{{ old('hire_date') }}"
                    class="form-input w-full mt-1 @error('hire_date') border-red-500 @enderror"
                    required>
                @error('hire_date')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Salario base --}}
            <div>
                <label for="base_salary" class="form-label">Salario base <span class="text-red-500">*</span></label>
                <input type="number"
                    id="base_salary"
                    name="base_salary"
                    value="{{ old('base_salary') }}"
                    step="0.01"
                    min="0"
                    class="form-input w-full mt-1 @error('base_salary') border-red-500 @enderror"
                    required>
                @error('base_salary')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Botones --}}
            <div class="flex justify-end gap-3 pt-4 border-t border-slate-200">
                <a href="{{ route('planilla.empleados.index') }}">
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