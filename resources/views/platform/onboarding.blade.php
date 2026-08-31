@extends('layouts.platform')
@section('title', 'Alta comercial')
@section('content')
<div class="mx-auto max-w-4xl space-y-5" data-commercial-onboarding>
    <header><a href="{{ route('platform.index') }}" class="text-sm font-semibold text-amber-700">← Panel Maestro</a><h1 class="mt-2 text-2xl font-bold sm:text-3xl">Alta comercial de tenant</h1><p class="mt-2 text-sm text-slate-600">MVS define acceso y contrato. El propietario completará después sus datos legales, primera sucursal y operación.</p></header>
    <form method="POST" action="{{ route('platform.companies.store') }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">@csrf
        @if($errors->any())<div class="rounded-xl bg-red-50 p-4 text-sm text-red-700">{{ $errors->first() }}</div>@endif
        <section><h2 class="font-bold">Tenant y propietario</h2><div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <label class="text-sm font-semibold">Nombre de referencia<input name="trade_name" value="{{ old('trade_name') }}" required class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
            <label class="text-sm font-semibold">Nombre del propietario<input name="owner[name]" value="{{ old('owner.name') }}" required class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
            <label class="text-sm font-semibold">Correo del propietario<input type="email" name="owner[email]" value="{{ old('owner.email') }}" required class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
            <label class="text-sm font-semibold">Teléfono opcional<input name="owner[phone]" value="{{ old('owner.phone') }}" class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
        </div></section>
        <section><h2 class="font-bold">Contrato inicial</h2><div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
            <label class="text-sm font-semibold">Plan / referencia<input name="plan" value="{{ old('plan') }}" required class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
            <label class="text-sm font-semibold">Límite de sucursales<input type="number" min="1" name="branch_limit" value="{{ old('branch_limit', 1) }}" required class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
            <label class="text-sm font-semibold">Estado<select name="status" class="mt-2 min-h-11 w-full rounded-xl border px-4">@foreach(\App\Models\CompanyLicense::STATUSES as $status)<option value="{{ $status }}" @selected(old('status', 'trial') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
        </div><label class="mt-4 block text-sm font-semibold">Nota comercial<textarea name="notes" class="mt-2 min-h-24 w-full rounded-xl border p-3">{{ old('notes') }}</textarea></label></section>
        <fieldset><legend class="font-bold">Módulos habilitados</legend><div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach($moduleCatalog as $key => $definition)<label class="flex min-h-11 items-center gap-3 rounded-xl border p-3"><input type="checkbox" name="modules[]" value="{{ $key }}" @checked(in_array($key, old('modules', []), true)) class="h-5 w-5"><span class="font-semibold">{{ $definition['label'] }}</span></label>@endforeach</div></fieldset>
        <button class="min-h-11 w-full rounded-xl bg-amber-500 px-5 font-bold text-slate-950 sm:w-auto">Crear tenant y contrato</button>
    </form>
</div>
@endsection
