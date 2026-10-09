<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MVS CobrOS: cobranza profesional para prestamistas y cobradores. Regístrese, pruebe la demo o vea los videos.">
    <meta name="theme-color" content="#D4AF37">
    <title>MVS CobrOS | Cobranza profesional</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-black text-white antialiased">
    <header class="border-b border-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-4 sm:px-6">
            <div class="flex items-center gap-3">
                <img src="{{ asset('images/logo-mvs-cobros.png') }}" alt="MVS CobrOS" class="h-10 w-auto" width="160" height="40">
                <span class="text-lg font-bold">MVS CobrOS</span>
            </div>
            <a href="{{ route('cobros.login') }}" class="inline-flex min-h-11 items-center rounded-xl border border-primary px-4 py-2 text-sm font-bold text-primary transition hover:bg-primary-hover hover:text-white">Iniciar sesión</a>
        </div>
    </header>

    <main>
        <section class="mx-auto max-w-5xl px-4 py-12 text-center sm:px-6">
            <p class="text-xs font-bold uppercase tracking-widest text-primary">Cobranza profesional</p>
            <h1 class="mt-4 text-3xl font-black sm:text-5xl">Cobró con orden.<br> Crezca con control.</h1>
            <p class="mx-auto mt-5 max-w-2xl text-lg leading-7 text-slate-300">MVS CobrOS reúne cartera, rutas, cobros y reportes en una sola herramienta, pensada para prestamistas y equipos de cobranza.</p>
        </section>

        <section class="mx-auto max-w-5xl px-4 pb-6 sm:px-6" aria-label="Opciones principales">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <article class="flex flex-col rounded-2xl bg-white p-5 text-black sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-widest text-black">Empiece aquí</p>
                    <h2 class="mt-2 text-xl font-bold">Registrarse</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Cree su espacio de trabajo y configure su operación de cobranza paso a paso.</p>
                    <div class="mt-auto pt-5">
                        <button type="button" disabled aria-disabled="true" class="inline-flex min-h-11 w-full cursor-not-allowed items-center justify-center rounded-xl border border-black px-4 py-3 text-sm font-bold text-black">Próximamente</button>
                    </div>
                </article>

                <article class="flex flex-col rounded-2xl border border-white bg-white p-5 text-black sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-widest text-black">Conozca el producto</p>
                    <h2 class="mt-2 text-xl font-bold">Probar demo</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Recorra MVS CobrOS con datos de ejemplo, sin tocar información real.</p>
                    <div class="mt-auto pt-5">
                        <button type="button" disabled aria-disabled="true" class="inline-flex min-h-11 w-full cursor-not-allowed items-center justify-center rounded-xl border border-black px-4 py-3 text-sm font-bold text-black">Próximamente</button>
                    </div>
                </article>

                <article class="flex flex-col rounded-2xl border border-white bg-white p-5 text-black sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-widest text-black">Aprenda a su ritmo</p>
                    <h2 class="mt-2 text-xl font-bold">Ver videos</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Videos cortos para dominar cada módulo de la plataforma.</p>
                    <div class="mt-auto pt-5">
                        <button type="button" disabled aria-disabled="true" class="inline-flex min-h-11 w-full cursor-not-allowed items-center justify-center rounded-xl border border-black px-4 py-3 text-sm font-bold text-black">Próximamente</button>
                    </div>
                </article>
            </div>
        </section>

        <section class="mx-auto max-w-5xl px-4 py-10 sm:px-6" aria-label="Países disponibles">
            <div class="rounded-2xl border border-primary p-5 sm:p-6">
                <h2 class="text-lg font-bold">Países disponibles</h2>
                <div class="mt-4 flex flex-wrap gap-3">
                    <span class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-primary px-4 py-2 font-semibold">Costa Rica <span class="text-xs font-bold uppercase text-primary">Disponible</span></span>
                    <span class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-primary px-4 py-2 font-semibold">Colombia <span class="text-xs font-bold uppercase text-primary">Disponible</span></span>
                </div>
                <p class="mt-4 text-sm leading-6 text-slate-300">¿Su país aún no está en la lista? Los demás países podrán solicitar su habilitación próximamente.</p>
            </div>
        </section>
    </main>

    <footer class="border-t border-white py-6">
        <p class="mx-auto max-w-5xl px-4 text-center text-xs text-slate-400 sm:px-6">MVS CobrOS · Profesional por dentro. Sencillo por fuera.</p>
    </footer>
</body>
</html>
