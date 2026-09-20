<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>@yield('title', 'MVS CobrOS')</title>
    <meta name="description" content="@yield('description', '')">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('icons/cobros/favicon-32x32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/cobros/apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('manifest-cobros.json') }}">
    <meta name="theme-color" content="#D4AF37">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/cobros-shell.css') }}?v=1">
</head>
<body class="cobros-app">
    <a class="cobros-skip" href="#cobros-main">Ir al contenido</a>
    <aside class="cobros-sidebar" aria-label="MVS CobrOS">
        <div class="cobros-brand">
            <img src="{{ asset('images/logo-mvs-cobros.png') }}" alt="MVS CobrOS" width="64" height="64">
            <div class="cobros-brand-copy"><strong>MVS CobrOS</strong><span>Cobranza Operativa</span></div>
        </div>
        <div class="cobros-company"><span>Empresa activa</span><strong>{{ $company->trade_name }}</strong></div>
        @include('cobros.components.navigation')
        <div class="cobros-sidebar-footer">MVS CobrOS · Versión 1.0</div>
    </aside>
    <div class="cobros-workspace">
        <header class="cobros-header">
            <div class="cobros-header-company">
                <img src="{{ asset('images/logo-mvs-cobros.png') }}" alt="MVS CobrOS" width="44" height="44">
                <div><span>Empresa activa</span><strong>{{ $company->trade_name }}</strong></div>
            </div>
            <div class="cobros-account">
                <span class="cobros-user">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="cobros-logout">Salir</button>
                </form>
            </div>
        </header>
        <main id="cobros-main" class="cobros-main" tabindex="-1">
            @if(session('success'))
                <div role="status" class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div role="alert" class="mb-4 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
            @endif
            @yield('content')
        </main>
    </div>
</body>
</html>
