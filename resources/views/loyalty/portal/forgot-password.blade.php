@extends('layouts.portal')
@section('title', 'Recuperar acceso · '.$company->trade_name)
@section('content')
<div class="mx-auto max-w-md rounded-2xl border bg-white p-5 shadow-sm sm:p-8"><h1 class="text-2xl font-bold">Recuperar acceso</h1><p class="mt-2 text-sm text-slate-600">Te enviaremos un enlace seguro si el correo está registrado.</p>@if(session('success'))<p class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm">{{ session('success') }}</p>@endif<form method="POST" action="{{ route('loyalty.customer.password.email', $company) }}" class="mt-6 space-y-4">@csrf<label class="block text-sm font-semibold">Correo<input type="email" name="email" required class="mt-2 min-h-11 w-full rounded-xl border-slate-300"></label><button class="min-h-11 w-full rounded-xl bg-slate-900 text-white">Enviar enlace</button></form></div>
@endsection
