<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Registro de MVS CobrOS: revisión de datos.">
    <meta name="theme-color" content="#D4AF37">
    <title>Registro | MVS CobrOS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-black text-white antialiased">
    @include('cobros.public-header')

    <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-10">
        @include('cobros.register-progress', ['step' => $step, 'progressSteps' => $progressSteps])

        <section class="mt-8 rounded-2xl bg-white p-5 text-black sm:p-6">
            <p class="text-xs font-bold uppercase tracking-widest text-black">Paso 3 de 3</p>
            <h1 class="mt-2 text-2xl font-bold">Revisión</h1>
            <p class="mt-2 text-sm leading-6 text-slate-600">Confirme sus datos antes de continuar. Todavía puede corregir cualquier información.</p>

            <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="rounded-xl border border-black p-4">
                    <dt class="text-xs font-bold uppercase text-slate-600">País</dt>
                    <dd class="mt-1 font-semibold">{{ $countries[$registration['country_iso'] ?? ''] ?? '—' }}</dd>
                </div>
                <div class="rounded-xl border border-black p-4">
                    <dt class="text-xs font-bold uppercase text-slate-600">Modalidad</dt>
                    <dd class="mt-1 font-semibold">{{ $modalities[$registration['modality'] ?? '']['label'] ?? '—' }}</dd>
                </div>
                <div class="rounded-xl border border-black p-4">
                    <dt class="text-xs font-bold uppercase text-slate-600">Nombre</dt>
                    <dd class="mt-1 font-semibold">{{ $registration['personal']['name'] ?? '—' }}</dd>
                </div>
                <div class="rounded-xl border border-black p-4">
                    <dt class="text-xs font-bold uppercase text-slate-600">Correo electrónico</dt>
                    <dd class="mt-1 font-semibold">{{ $registration['personal']['email'] ?? '—' }}</dd>
                </div>
                <div class="rounded-xl border border-black p-4">
                    <dt class="text-xs font-bold uppercase text-slate-600">Teléfono</dt>
                    <dd class="mt-1 font-semibold">{{ ($registration['personal']['phone'] ?? '') !== '' && ($registration['personal']['phone'] ?? null) !== null ? $registration['personal']['phone'] : '—' }}</dd>
                </div>
                <div class="rounded-xl border border-black p-4">
                    <dt class="text-xs font-bold uppercase text-slate-600">Referencia de afiliado</dt>
                    <dd class="mt-1 font-semibold">{{ $hasReferral ? 'Capturada automáticamente' : 'Sin referencia' }}</dd>
                </div>
            </dl>

            <div role="status" class="mt-6 rounded-xl border border-black p-4 text-sm leading-6">
                Su cuenta, licencia y acceso operativo se crearán únicamente después del pago confirmado. En esta fase no se genera ningún acceso ni se activa ningún servicio.
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('cobros.register.step', ['paso' => 1]) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-black px-4 py-3 text-sm font-bold">Corregir país y modalidad</a>
                    <a href="{{ route('cobros.register.step', ['paso' => 2]) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-black px-4 py-3 text-sm font-bold">Corregir datos personales</a>
                </div>
                <button type="button" disabled aria-disabled="true" class="inline-flex min-h-11 w-full cursor-not-allowed items-center justify-center rounded-xl border border-black px-5 py-3 text-sm font-bold sm:w-auto">Continuar al pago · Próximamente</button>
            </div>
        </section>
    </main>

    <footer class="border-t border-white py-6">
        <p class="mx-auto max-w-5xl px-4 text-center text-xs text-slate-400 sm:px-6">MVS CobrOS · Profesional por dentro. Sencillo por fuera.</p>
    </footer>
</body>
</html>
