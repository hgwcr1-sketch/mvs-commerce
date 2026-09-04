@extends('layouts.portal')
@section('title', 'Ingresar · '.$portalBranding->displayName($company))
@section('content')
<div class="mx-auto max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
    <x-portal-brand :company="$company" :branding="$portalBranding" class="mb-5" />
    <h1 class="text-2xl font-bold text-slate-900">Tu portal en {{ $portalBranding->displayName($company) }}</h1>
    <p class="mt-2 text-sm text-slate-600">Consulta tus puntos y compras de todas las sucursales.</p>
    @if(session('success'))<p class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</p>@endif
    @if($errors->any())<p class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ route('loyalty.customer.login.store', $company) }}" class="mt-6 space-y-4">@csrf
        <label class="block text-sm font-semibold">Usuario o correo<input name="username" value="{{ old('username') }}" required autocomplete="username" class="mt-2 min-h-11 w-full rounded-xl border-slate-300"></label>
        <label class="block text-sm font-semibold">Contraseña<input type="password" name="password" required autocomplete="current-password" class="mt-2 min-h-11 w-full rounded-xl border-slate-300"></label>
        <button class="min-h-11 w-full rounded-xl px-4 font-semibold text-white" style="background-color:var(--portal-primary)">Ingresar</button>
    </form>
     <a href="{{ route('loyalty.customer.password.request', $company) }}" class="mt-5 block min-h-11 py-3 text-center text-sm font-semibold text-amber-700">Olvidé mi contraseña</a>
    <p class="mt-2 text-center text-sm text-slate-600">¿No tienes cuenta? <a href="{{ route('loyalty.customer.register', $company) }}" class="font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 hover:text-slate-700">Registrarme / Crear mi cuenta</a></p>
</div>
@endsection
