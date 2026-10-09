<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Registro de MVS CobrOS: revisión de datos y precios de referencia.">
    <meta name="theme-color" content="#D4AF37">
    <title>Registro | MVS CobrOS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-black text-white antialiased">
    @include('cobros.public-header')

    <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-10">
        @include('cobros.register-progress', ['step' => $step, 'progressSteps' => $progressSteps])
        @include('cobros.register-invitation')

        @php
            $draftRoutes = $registration['routes'] ?? [];
        @endphp

        <section class="mt-8 rounded-2xl bg-white p-5 text-black sm:p-6">
            <p class="text-xs font-bold uppercase tracking-widest text-black">Paso 4 de 4</p>
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
                    <dd class="mt-1 font-semibold">{{ ($registration['personal']['phone'] ?? null) !== null && ($registration['personal']['phone'] ?? '') !== '' ? $registration['personal']['phone'] : '—' }}</dd>
                </div>
                <div class="rounded-xl border border-black p-4">
                    <dt class="text-xs font-bold uppercase text-slate-600">Referencia de afiliado</dt>
                    <dd class="mt-1 font-semibold">{{ $hasReferral ? 'Capturada automáticamente' : 'Sin referencia' }}</dd>
                </div>
                <div class="rounded-xl border border-black p-4">
                    <dt class="text-xs font-bold uppercase text-slate-600">Rutas (borrador)</dt>
                    <dd class="mt-1 font-semibold">
                        @if (count($draftRoutes) === 0)
                            Sin rutas en borrador
                        @else
                            <ul class="list-disc pl-5">
                                @foreach ($draftRoutes as $draftRoute)
                                    <li>{{ $draftRoute['name'] }}{{ ($draftRoute['code'] ?? null) ? ' ('.$draftRoute['code'].')' : '' }}{{ ($draftRoute['lender'] ?? null) ? ' · Prestamista: '.$draftRoute['lender'] : '' }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </dd>
                </div>
                <div class="rounded-xl border border-black p-4">
                    <dt class="text-xs font-bold uppercase text-slate-600">Origen de cartera</dt>
                    <dd class="mt-1 font-semibold">{{ $portfolioPlans[$registration['portfolio_plan'] ?? ''] ?? '—' }}</dd>
                </div>
            </dl>

            @if ($priceSummary)
                <div class="mt-6 rounded-xl border border-black p-4">
                    <p class="text-xs font-bold uppercase text-slate-600">Precios de referencia ({{ $countries[$registration['country_iso'] ?? ''] ?? '' }})</p>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <p class="rounded-xl border border-black p-3 text-sm"><span class="block font-bold">Administración</span>{{ $priceSummary['administration'] }}</p>
                        <p class="rounded-xl border border-black p-3 text-sm"><span class="block font-bold">Por ruta</span>{{ $priceSummary['route'] }}</p>
                        <p class="rounded-xl border border-black p-3 text-sm"><span class="block font-bold">Total referencial ({{ $priceSummary['routeCount'] }} ruta(s) + administración)</span>{{ $priceSummary['total'] }}</p>
                    </div>
                    <p class="mt-3 text-xs leading-5 text-slate-600">Informativo: en esta fase no se realiza ningún cobro ni se acreditan recompensas.</p>
                </div>
            @endif

            <div role="status" class="mt-6 rounded-xl border border-black p-4 text-sm leading-6">
                Su cuenta, licencia y acceso operativo se crearán únicamente después del pago confirmado. En esta fase no se genera ningún acceso ni se activa ningún servicio.
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('cobros.register.step', ['paso' => 1]) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-black px-4 py-3 text-sm font-bold">Corregir país y modalidad</a>
                    <a href="{{ route('cobros.register.step', ['paso' => 2]) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-black px-4 py-3 text-sm font-bold">Corregir datos personales</a>
                    <a href="{{ route('cobros.register.step', ['paso' => 3]) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-black px-4 py-3 text-sm font-bold">Corregir rutas</a>
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
