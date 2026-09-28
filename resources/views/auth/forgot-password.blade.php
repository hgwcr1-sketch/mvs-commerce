@extends('layouts.auth')
@section('title', 'Recuperar contraseña')
@section('heading', 'Recupere su acceso')
@section('intro', 'Le enviaremos un enlace seguro al correo asociado con su usuario.')

@section('form')
<form method="POST" action="{{ route('password.email') }}" class="space-y-5">
    @csrf
    <label class="block text-sm font-semibold text-slate-700">Correo electrónico
        <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email" inputmode="email" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none transition focus:border-amber-500 focus:ring-4 focus:ring-amber-100">
    </label>
    <button type="submit" class="min-h-11 w-full rounded-xl bg-slate-950 px-5 py-3 font-bold text-white transition hover:bg-amber-600 focus:outline-none focus:ring-4 focus:ring-amber-200">Enviar enlace de recuperación</button>
    <a href="{{ route('login') }}" class="flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Volver a iniciar sesión</a>
</form>
@endsection
