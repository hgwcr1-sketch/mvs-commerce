@extends('layouts.portal')
@php($portalBranding = $portalBranding ?? $portalSetting ?? null)
@section('title', 'Cambiar contraseña · '.($portalBranding ? $portalBranding->displayName($company) : $company->trade_name))
@section('content')
<div class="mx-auto max-w-md rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-8">
    <x-portal-brand :company="$company" :branding="$portalBranding" class="mb-5" />
    <h1 class="text-2xl font-bold text-slate-900">Cambia tu contraseña</h1>
    <p class="mt-2 text-sm text-slate-600">Por seguridad debes cambiar tu contraseña temporal antes de continuar.</p>
    @if($errors->any())<p class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ route('loyalty.customer.password.force.store', $company) }}" class="mt-6 space-y-4">@csrf
        <label class="block text-sm font-semibold">Nueva contraseña<input type="password" name="password" required autocomplete="new-password" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-4 py-3"></label>
        <label class="block text-sm font-semibold">Confirmar contraseña<input type="password" name="password_confirmation" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-4 py-3"></label>
        <button type="submit" class="min-h-11 w-full rounded-xl bg-slate-900 px-4 py-3 font-semibold text-white">Guardar y continuar</button>
    </form>
</div>
@endsection
