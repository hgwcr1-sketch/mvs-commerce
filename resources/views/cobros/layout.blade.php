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
</head>
<body class="min-h-screen bg-slate-100">
    <div class="min-h-screen">
        <nav class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-3 py-3 sm:px-4 sm:py-4">
                <div class="flex items-center gap-3">
                    <img src="{{ asset('images/logo-mvs-cobros.png') }}" alt="MVS CobrOS"
                         width="64" height="64" class="h-16 w-16 shrink-0 object-contain">
                    <div>
                        <span class="text-lg font-bold text-slate-900">MVS CobrOS</span>
                        <p class="text-xs text-slate-500">Cobranza Operativa</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 sm:gap-3">
                    @if($company)
                        <span class="text-sm text-slate-600 hidden sm:inline">{{ $company->trade_name }}</span>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-lg bg-slate-200 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-300">
                            Salir
                        </button>
                    </form>
                </div>
            </div>
        </nav>

        <main class="mx-auto max-w-3xl px-3 py-6 sm:px-4 sm:py-8">
            @if(session('success'))
                <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="mb-4 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                    {{ session('error') }}
                </div>
            @endif

            @yield('content')
        </main>

        <footer class="border-t border-slate-200 bg-white px-3 py-4 text-center text-xs text-slate-400 sm:px-4">
            MVS CobrOS — Cobranza Operativa · Versión 1.0
        </footer>
    </div>
</body>
</html>
