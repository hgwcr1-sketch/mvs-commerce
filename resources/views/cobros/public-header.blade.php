<header class="border-b border-white">
    <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-4 sm:px-6">
        <a href="{{ route('cobros.landing') }}" class="flex items-center gap-3">
            <img src="{{ asset('images/logo-mvs-cobros.png') }}" alt="MVS CobrOS" class="h-10 w-auto" width="160" height="40">
            <span class="text-lg font-bold">MVS CobrOS</span>
        </a>
        {{-- 21D-FIX-1: las pantallas DEMO del visitante no muestran «Iniciar
             sesión» (acceso privado ajeno al tour); la landing pública sí. --}}
        @unless (request()->routeIs('cobros.demo.*'))
            <a href="{{ route('cobros.login') }}" class="inline-flex min-h-11 items-center rounded-xl border border-primary px-4 py-2 text-sm font-bold text-primary transition hover:bg-primary-hover hover:text-white">Iniciar sesión</a>
        @endunless
    </div>
</header>
