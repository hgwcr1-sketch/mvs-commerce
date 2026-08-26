@extends('layouts.auth')
@section('title', 'Nueva contraseña')
@section('heading', 'Cree una nueva contraseña')
@section('intro', 'Use al menos ocho caracteres, mayúsculas, minúsculas y números.')

@section('form')
<form method="POST" action="{{ route('password.update') }}" class="space-y-5">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <input type="hidden" name="email" value="{{ $email }}">
    @foreach(['password' => 'Nueva contraseña', 'password_confirmation' => 'Confirmar nueva contraseña'] as $field => $label)
        <label class="block text-sm font-semibold text-slate-700">{{ $label }}
            <span class="relative mt-2 block">
                <input id="{{ $field }}" type="password" name="{{ $field }}" required autocomplete="new-password" class="min-h-11 w-full rounded-xl border border-slate-300 px-4 py-3 pr-14 outline-none transition focus:border-amber-500 focus:ring-4 focus:ring-amber-100">
                <button type="button" data-password-toggle="{{ $field }}" class="absolute inset-y-0 right-0 flex min-h-11 min-w-11 items-center justify-center rounded-r-xl text-slate-500 hover:text-slate-900" aria-label="Mostrar {{ strtolower($label) }}" aria-pressed="false"><span aria-hidden="true">◉</span></button>
            </span>
        </label>
    @endforeach
    <button type="submit" class="min-h-11 w-full rounded-xl bg-slate-950 px-5 py-3 font-bold text-white transition hover:bg-amber-600 focus:outline-none focus:ring-4 focus:ring-amber-200">Restablecer contraseña</button>
    <a href="{{ route('login') }}" class="flex min-h-11 items-center justify-center text-sm font-semibold text-amber-700 hover:text-amber-800">Volver al inicio de sesión</a>
</form>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const field = document.getElementById(button.dataset.passwordToggle);
        const show = field.type === 'password';
        field.type = show ? 'text' : 'password';
        button.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
});
</script>
@endpush
