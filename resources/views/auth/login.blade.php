@extends('layouts.auth')
@section('title', 'Iniciar sesión')
@section('heading', 'Bienvenido a MVS Commerce')
@section('intro', 'Ingrese con sus credenciales para continuar a su espacio de trabajo.')

@section('form')
<form method="POST" action="{{ route('login.store') }}" class="space-y-5">
    @csrf
    <label class="block text-sm font-semibold text-slate-700">Correo electrónico
        <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" inputmode="email" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 outline-none transition focus:border-amber-500 focus:ring-4 focus:ring-amber-100">
    </label>
    <label class="block text-sm font-semibold text-slate-700">Contraseña
        <span class="relative mt-2 block">
            <input id="password" type="password" name="password" required autocomplete="current-password" class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 pr-14 outline-none transition focus:border-amber-500 focus:ring-4 focus:ring-amber-100">
            <button type="button" data-password-toggle="password" class="absolute inset-y-0 right-0 flex min-h-11 min-w-11 items-center justify-center rounded-r-xl text-slate-500 hover:text-slate-900" aria-label="Mostrar contraseña" aria-pressed="false"><span aria-hidden="true">◉</span></button>
        </span>
    </label>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <label class="flex min-h-11 items-center gap-3 text-sm text-slate-600"><input type="checkbox" name="remember" id="remember" class="h-5 w-5 rounded border-slate-300 text-amber-600 focus:ring-amber-500"> Recordarme</label>
        <a href="{{ route('password.request') }}" class="inline-flex min-h-11 items-center font-semibold text-amber-700 hover:text-amber-800">¿Olvidó su contraseña?</a>
    </div>
    <button type="submit" class="min-h-11 w-full rounded-xl bg-slate-950 px-5 py-3 font-bold text-white shadow-lg shadow-slate-950/15 transition hover:bg-amber-600 focus:outline-none focus:ring-4 focus:ring-amber-200">Ingresar</button>
</form>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const field = document.getElementById(button.dataset.passwordToggle);
        const show = field.type === 'password';
        field.type = show ? 'text' : 'password';
        button.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
        button.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
});
</script>
@endpush
