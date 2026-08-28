@extends('layouts.portal')
@section('title', 'Crear cuenta · '.$company->trade_name)
@section('content')
<div class="mx-auto max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
    <h1 class="text-2xl font-bold text-slate-900">Crear mi cuenta en {{ $company->trade_name }}</h1>
    <p class="mt-2 text-sm text-slate-600">Tu cuenta quedará disponible de inmediato en esta empresa para compras, POS y fidelización.</p>
    @if(session('success'))<p class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</p>@endif
    @if($errors->any())<p class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ route('loyalty.customer.register.store', $company) }}" class="mt-6 space-y-4">@csrf
        <label class="block text-sm font-semibold">Nombre completo *<input name="name" value="{{ old('name') }}" required maxlength="150" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-4 py-3"></label>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="block text-sm font-semibold">Tipo ID<select name="identification_type" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 py-3"><option value="">—</option><option value="01" @selected(old('identification_type')==='01')>01 Cédula Física</option><option value="02" @selected(old('identification_type')==='02')>02 Jurídica</option><option value="03" @selected(old('identification_type')==='03')>03 DIMEX</option><option value="04" @selected(old('identification_type')==='04')>04 NITE</option><option value="05" @selected(old('identification_type')==='05')>05 Extranjero</option></select></label>
            <label class="block text-sm font-semibold">Identificación<input name="identification" value="{{ old('identification') }}" maxlength="50" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-4 py-3" placeholder="Opcional si no tiene"></label>
        </div>
        <label class="block text-sm font-semibold">Teléfono<input name="phone" value="{{ old('phone') }}" maxlength="30" inputmode="tel" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-4 py-3" placeholder="Ej. 8888-8888"></label>
        <label class="block text-sm font-semibold">Correo<input type="email" name="email" value="{{ old('email') }}" maxlength="150" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-4 py-3" placeholder="Opcional"></label>
        <label class="block text-sm font-semibold">Usuario *<input name="username" value="{{ old('username') }}" required maxlength="100" pattern="[A-Za-z0-9_-]+" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-4 py-3" placeholder="Solo letras, números, _ y -"></label>
        <label class="block text-sm font-semibold">Contraseña *<input type="password" name="password" required autocomplete="new-password" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-4 py-3"></label>
        <label class="block text-sm font-semibold">Confirmar contraseña *<input type="password" name="password_confirmation" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-4 py-3"></label>
        <p class="text-xs text-slate-500">Al registrarte aceptas registrar tu cliente dentro de {{ $company->trade_name }}. Si ya existe un cliente con la misma identificación, teléfono o correo, se enlazará a ese cliente existente.</p>
        <button type="submit" class="min-h-11 w-full rounded-xl bg-slate-900 px-4 py-3 font-semibold text-white">Crear mi cuenta</button>
    </form>
    <a href="{{ route('loyalty.customer.login', $company) }}" class="mt-4 block min-h-11 py-3 text-center text-sm font-semibold text-slate-600">Ya tengo cuenta · Ingresar</a>
</div>
@endsection
