<!DOCTYPE html>
<html lang="es">

<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

    <title>{{ config('app.name', 'MVS Commerce ERP') }}</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

</head>

<body class="bg-slate-100 antialiased">

<div class="flex h-screen overflow-hidden">

    <div class="flex min-w-0 flex-1 flex-col overflow-hidden">

        {{-- HEADER --}}
        @include('components.header')

        {{-- CONTENIDO --}}
        <main
            class="flex-1 overflow-x-hidden overflow-y-auto bg-slate-100">

            <div class="p-4 pb-28 md:p-6 md:pb-28">

                @yield('content')

            </div>

        </main>

    </div>

</div>

    {{-- Navegación operativa tenant compartida por escritorio, tablet y móvil --}}
    @include('components.navigation.bottom-bar')

    {{-- SHEET "MÁS": reutiliza el sidebar real como fuente única de menú y permisos --}}
    <div x-data="{ open: false }"
         @mvs-open-nav.window="open = true"
         @keydown.escape.window="open = false">

        <div x-cloak x-show="open"
             x-transition.opacity.duration.150ms
             @click="open = false"
             class="fixed inset-0 z-[45] bg-slate-950/60"
             aria-hidden="true"></div>

        <aside x-cloak x-show="open"
            x-transition:enter="transition-transform duration-200 ease-out"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition-transform duration-150 ease-in"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="fixed inset-y-0 right-0 z-50 flex w-[360px] max-w-[90vw] flex-col bg-slate-900 shadow-2xl"
            role="dialog"
            aria-modal="true"
            aria-label="Menú de navegación">

            <header class="flex h-14 shrink-0 items-center justify-between border-b border-slate-800 px-3">
                <span class="text-sm font-semibold text-white">Menú</span>
                <button type="button" @click="open = false"
                    class="flex h-11 w-11 items-center justify-center rounded-xl text-2xl text-slate-300 transition hover:bg-slate-800 hover:text-white"
                    aria-label="Cerrar menú">×</button>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto">
                @include('components.navigation.sidebar', ['context' => 'sheet'])
            </div>
        </aside>
    </div>
@stack('scripts')

</body>

</html>
