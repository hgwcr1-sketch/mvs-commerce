<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>@yield('title', 'MVS CobrOS') — MVS Commerce</title>
    <meta name="description" content="@yield('description', '')">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('icons/favicon-32x32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#D4AF37">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100">
    <div class="min-h-screen">
        <nav class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-3 py-3 sm:px-4 sm:py-4">
                <div class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-[#B1922D]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <div>
                        <span class="text-lg font-bold text-slate-900">MVS CobrOS</span>
                        <p class="text-xs text-slate-500">Cobranza Operativa</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 sm:gap-3">
                    @if($company)
                        <span class="text-sm text-slate-600 hidden sm:inline">{{ $company->trade_name }}</span>
                    @endif
                    <a href="{{ route('dashboard') }}"
                       class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100">
                        Volver a MVS Commerce
                    </a>
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
