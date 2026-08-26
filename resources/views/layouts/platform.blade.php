<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') | Panel Maestro MVS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
    <header class="border-b border-slate-800 bg-slate-950 text-white">
        <div class="mx-auto flex min-h-16 max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
            <a href="{{ route('platform.index') }}" class="flex min-h-11 items-center gap-3"><img src="{{ asset('images/logo-mvs-corto.png') }}" alt="" class="h-10 w-10 object-contain"><span><strong class="block text-sm">Panel Maestro MVS</strong><small class="text-slate-400">Administración de plataforma</small></span></a>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="min-h-11 rounded-xl border border-slate-700 px-4 text-sm font-semibold hover:bg-slate-800">Cerrar sesión</button></form>
        </div>
    </header>
    <main class="mx-auto max-w-7xl p-4 pb-10 sm:p-6 lg:p-8">
        @if(session('success'))<div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('success') }}</div>@endif
        @if($errors->any())<div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>@endif
        @yield('content')
    </main>
</body>
</html>
