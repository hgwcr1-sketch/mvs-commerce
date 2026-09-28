@extends('layouts.app')

@section('title', 'Seleccionar sucursal operativa')

@section('content')
<section class="mx-auto max-w-xl space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
    <h1 class="text-xl font-semibold">Seleccionar sucursal operativa</h1>
    <p class="text-sm text-slate-600">Para trabajar en POS o Caja, seleccione una sucursal en el encabezado. Después se comprobarán los permisos y la caja correspondiente.</p>
    <p class="text-sm text-slate-600">Si no tiene sucursales asignadas, solicite la asignación al administrador de su empresa.</p>
    <a href="{{ route('dashboard') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 py-2">Volver al Dashboard</a>
</section>
@endsection
