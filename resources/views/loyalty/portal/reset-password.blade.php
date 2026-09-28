@extends('layouts.portal')
@php($portalBranding = $portalBranding ?? $portalSetting ?? null)
@section('title', 'Nueva contraseña · '.($portalBranding ? $portalBranding->displayName($company) : $company->trade_name))
@section('content')
<div class="mx-auto max-w-md rounded-2xl border bg-white p-5 shadow-sm sm:p-8">
    <x-portal-brand :company="$company" :branding="$portalBranding" class="mb-5" />
    <h1 class="text-2xl font-bold">Crea una nueva contraseña</h1>
    @if($errors->any())<p class="mt-4 rounded-xl bg-red-50 p-3 text-sm">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ route('loyalty.customer.password.update', ['company' => $company, 'token' => $token]) }}" class="mt-6 space-y-4">@csrf
        <label class="block text-sm font-semibold">Contraseña<input type="password" name="password" required class="mt-2 min-h-11 w-full rounded-xl border-slate-300"></label>
        <label class="block text-sm font-semibold">Confirmar<input type="password" name="password_confirmation" required class="mt-2 min-h-11 w-full rounded-xl border-slate-300"></label>
        <button class="min-h-11 w-full rounded-xl text-white" style="background-color:var(--portal-primary)">Guardar contraseña</button>
    </form>
</div>
@endsection
