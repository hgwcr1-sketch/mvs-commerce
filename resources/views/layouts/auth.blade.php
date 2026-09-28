<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Acceso seguro a MVS Commerce">
    <title>@yield('title') | MVS Commerce</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-900 antialiased">
    <main class="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-8 sm:px-8 lg:px-12" data-auth-shell>
        <div class="pointer-events-none absolute inset-0" aria-hidden="true">
            <div class="absolute -left-24 -top-28 h-72 w-72 rounded-full bg-amber-400/15 blur-3xl sm:h-96 sm:w-96"></div>
            <div class="absolute -bottom-32 -right-24 h-80 w-80 rounded-full bg-slate-400/15 blur-3xl sm:h-[28rem] sm:w-[28rem]"></div>
        </div>
        <section class="relative grid w-full max-w-6xl overflow-hidden rounded-[1.75rem] border border-white/10 bg-white shadow-2xl shadow-black/30 lg:grid-cols-[1.05fr_0.95fr]" aria-labelledby="auth-title">
            <div class="order-2 flex flex-col justify-between bg-slate-900 p-6 text-white sm:p-9 lg:order-1 lg:min-h-[40rem] lg:p-12">
                <div>
                    <img src="{{ asset('images/logo-mvs.png') }}" alt="MVS Commerce" class="h-auto w-48 sm:w-56" width="759" height="503">
                    <p class="mt-8 max-w-md text-2xl font-semibold leading-tight sm:text-3xl">Profesional por dentro.<br><span class="text-amber-400">Sencillo por fuera.</span></p>
                    <p class="mt-4 max-w-md text-sm leading-6 text-slate-300 sm:text-base">Una plataforma segura para centralizar la operación de su empresa, sus sucursales y su equipo.</p>
                </div>
                <div class="mt-10 grid grid-cols-2 gap-3 text-xs text-slate-300 sm:grid-cols-3" aria-label="Beneficios de la plataforma">
                    <span class="rounded-xl border border-white/10 bg-white/5 p-3">Operación centralizada</span>
                    <span class="rounded-xl border border-white/10 bg-white/5 p-3">Acceso protegido</span>
                    <span class="col-span-2 rounded-xl border border-white/10 bg-white/5 p-3 sm:col-span-1">Multiempresa</span>
                </div>
            </div>
            <div class="order-1 flex items-center bg-white p-5 sm:p-10 lg:order-2 lg:p-14">
                <div class="mx-auto w-full max-w-md">
                    <div class="mb-7">
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-600">Acceso seguro</p>
                        <h1 id="auth-title" class="mt-3 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">@yield('heading')</h1>
                        <p class="mt-2 text-sm leading-6 text-slate-600">@yield('intro')</p>
                    </div>
                    @if ($errors->any())
                        <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>
                    @endif
                    @if (session('status'))
                        <div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>
                    @endif
                    @yield('form')
                    <p class="mt-8 text-center text-xs leading-5 text-slate-500">MVS Commerce · Acceso exclusivo para usuarios autorizados</p>
                </div>
            </div>
        </section>
    </main>
    @stack('scripts')
</body>
</html>
