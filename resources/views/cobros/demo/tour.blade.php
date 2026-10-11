<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Recorrido guiado DEMO de MVS CobrOS: registro ficticio y elección de modalidad.">
    <meta name="theme-color" content="#D4AF37">
    <title>MVS CobrOS | Recorrido guiado</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-black text-white antialiased">
    @include('cobros.public-header')

    <main class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        {{-- Progreso --}}
        <nav aria-label="Progreso del recorrido" class="mb-8">
            <ol class="flex flex-wrap items-center gap-2 text-xs font-bold uppercase tracking-widest">
                @foreach ($steps as $i => $s)
                    <li class="inline-flex min-h-11 items-center gap-2">
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full {{ $i < $stepIndex ? 'bg-primary text-black' : ($i === $stepIndex ? 'bg-primary-hover text-white' : 'border border-white text-slate-400') }}">{{ $i + 1 }}</span>
                        <span class="{{ $i === $stepIndex ? 'text-primary' : 'text-slate-400' }}">{{ ucfirst($s) }}</span>
                    </li>
                    @if ($i < count($steps) - 1)<li class="h-px w-6 bg-white" aria-hidden="true"></li>@endif
                @endforeach
            </ol>
        </nav>

        @if ($step === 'bienvenida')
            <section class="rounded-2xl border border-primary bg-white p-6 text-black sm:p-8">
                <p class="text-xs font-bold uppercase tracking-widest text-primary">Recorrido guiado · datos ficticios</p>
                <h1 class="mt-3 text-3xl font-black">Bienvenido a su demo guiado</h1>
                <p class="mt-4 text-sm leading-6 text-slate-600">Le acompañaremos por el registro de MVS CobrOS con datos de ejemplo. Nada de lo que escriba aquí se envía a ningún sistema: es un espacio de práctica privado.</p>
                <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                    <form method="POST" action="{{ route('cobros.demo.tour.save', ['step' => 'bienvenida']) }}" class="flex-1">
                        @csrf
                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-primary px-4 py-3 text-sm font-bold text-black transition hover:bg-primary-hover hover:text-white">Comenzar</button>
                    </form>
                    <form method="POST" action="{{ route('cobros.demo.tour.reset') }}" class="flex-1">
                        @csrf
                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-white px-4 py-3 text-sm font-bold text-white transition hover:border-primary hover:text-primary">Reiniciar recorrido</button>
                    </form>
                </div>
            </section>
        @elseif ($step === 'datos')
            <section class="rounded-2xl border border-primary bg-white p-6 text-black sm:p-8">
                <p class="text-xs font-bold uppercase tracking-widest text-primary">Paso 2 · datos ficticios</p>
                <h1 class="mt-3 text-2xl font-black">Sus datos (de ejemplo)</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">Campos coherentes con el registro real. Puede autocompletar con datos ficticios o escribir los suyos de prueba.</p>

                <form method="POST" action="{{ route('cobros.demo.tour.save', ['step' => 'datos']) }}" class="mt-6 space-y-4">
                    @csrf
                    <div>
                        <label for="name" class="block text-sm font-semibold">Nombre completo</label>
                        <input id="name" name="name" type="text" required minlength="3" maxlength="150" value="{{ old('name', $answers['personal']['name'] ?? '') }}" class="mt-1 w-full rounded-xl border border-black px-4 py-3 text-black" placeholder="Ej. María Demo">
                        @error('name')<p class="mt-1 text-xs font-semibold text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-semibold">Correo electrónico</label>
                        <input id="email" name="email" type="email" required maxlength="150" value="{{ old('email', $answers['personal']['email'] ?? '') }}" class="mt-1 w-full rounded-xl border border-black px-4 py-3 text-black" placeholder="Ej. maria.demo@demo-visitante.test">
                        @error('email')<p class="mt-1 text-xs font-semibold text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="phone" class="block text-sm font-semibold">Teléfono (opcional)</label>
                        <input id="phone" name="phone" type="tel" maxlength="50" value="{{ old('phone', $answers['personal']['phone'] ?? '') }}" class="mt-1 w-full rounded-xl border border-black px-4 py-3 text-black" placeholder="Ej. +506 7000-0000">
                        @error('phone')<p class="mt-1 text-xs font-semibold text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex flex-col gap-3 pt-2 sm:flex-row">
                        <a href="{{ route('cobros.demo.tour.back', ['desde' => 'datos']) }}" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl border border-white px-4 py-3 text-sm font-bold text-white transition hover:border-primary hover:text-primary">Anterior</a>
                        <button type="submit" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl bg-primary px-4 py-3 text-sm font-bold text-black transition hover:bg-primary-hover hover:text-white">Siguiente</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('cobros.demo.tour.autofill', ['step' => 'datos']) }}" class="mt-3">
                    @csrf
                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-black px-4 py-2 text-sm font-bold text-black transition hover:bg-black hover:text-white">Autocompletar con datos ficticios</button>
                </form>

                <form method="POST" action="{{ route('cobros.demo.tour.reset') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-white px-4 py-2 text-sm font-bold text-white transition hover:border-primary hover:text-primary">Reiniciar recorrido</button>
                </form>
            </section>
        @elseif ($step === 'modalidad')
            <section class="rounded-2xl border border-primary bg-white p-6 text-black sm:p-8">
                <p class="text-xs font-bold uppercase tracking-widest text-primary">Paso 3 · modalidad</p>
                <h1 class="mt-3 text-2xl font-black">¿Cómo usará CobrOS?</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">Elija el país y la modalidad, igual que en el registro público real.</p>

                <form method="POST" action="{{ route('cobros.demo.tour.save', ['step' => 'modalidad']) }}" class="mt-6 space-y-5">
                    @csrf
                    <div>
                        <span class="block text-sm font-semibold">País</span>
                        <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                            @foreach ($countries as $iso => $label)
                                <label class="inline-flex min-h-11 flex-1 cursor-pointer items-center gap-2 rounded-xl border border-black px-4 py-3 text-sm font-semibold">
                                    <input type="radio" name="country_iso" value="{{ $iso }}" required @checked(old('country_iso', $answers['country_iso'] ?? 'CR') === $iso)>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @error('country_iso')<p class="mt-1 text-xs font-semibold text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <span class="block text-sm font-semibold">Modalidad</span>
                        <div class="mt-2 flex flex-col gap-2">
                            @foreach ($modalities as $key => $label)
                                <label class="inline-flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border border-black px-4 py-3 text-sm font-semibold">
                                    <input type="radio" name="modality" value="{{ $key }}" required @checked(old('modality', $answers['modality'] ?? null) === $key)>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @error('modality')<p class="mt-1 text-xs font-semibold text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex flex-col gap-3 pt-2 sm:flex-row">
                        <a href="{{ route('cobros.demo.tour.back', ['desde' => 'modalidad']) }}" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl border border-white px-4 py-3 text-sm font-bold text-white transition hover:border-primary hover:text-primary">Anterior</a>
                        <button type="submit" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl bg-primary px-4 py-3 text-sm font-bold text-black transition hover:bg-primary-hover hover:text-white">Siguiente</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('cobros.demo.tour.reset') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-white px-4 py-2 text-sm font-bold text-white transition hover:border-primary hover:text-primary">Reiniciar recorrido</button>
                </form>
            </section>
        @elseif ($step === 'resumen')
            {{-- resumen (tras modalidad) --}}
            <section class="rounded-2xl border border-primary bg-white p-6 text-black sm:p-8">
                <p class="text-xs font-bold uppercase tracking-widest text-primary">Recorrido completado</p>
                <h1 class="mt-3 text-2xl font-black">Así quedaría su registro</h1>
                <dl class="mt-6 space-y-3 text-sm">
                    <div class="flex justify-between gap-4 border-b border-slate-200 pb-2"><dt class="font-semibold text-slate-600">Nombre</dt><dd>{{ $answers['personal']['name'] ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4 border-b border-slate-200 pb-2"><dt class="font-semibold text-slate-600">Correo</dt><dd>{{ $answers['personal']['email'] ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4 border-b border-slate-200 pb-2"><dt class="font-semibold text-slate-600">País</dt><dd>{{ $countries[$answers['country_iso'] ?? ''] ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4 border-b border-slate-200 pb-2"><dt class="font-semibold text-slate-600">Modalidad</dt><dd>{{ $modalities[$answers['modality'] ?? ''] ?? '—' }}</dd></div>
                </dl>
                <p class="mt-6 rounded-xl border border-black p-4 text-sm leading-6 text-slate-600">El pago y la activación real NO forman parte de este demo: se implementan en una fase posterior. Aquí solo practica el flujo.</p>
                <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('cobros.demo.tour.back', ['desde' => 'modalidad']) }}" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl border border-white px-4 py-3 text-sm font-bold text-white transition hover:border-primary hover:text-primary">Anterior</a>
                    <a href="{{ route('cobros.demo.tour.step', ['step' => 'cotizacion']) }}" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl bg-primary px-4 py-3 text-sm font-bold text-black transition hover:bg-primary-hover hover:text-white">Ver cotización y pago</a>
                </div>
                <form method="POST" action="{{ route('cobros.demo.tour.reset') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-black px-4 py-2 text-sm font-bold text-black transition hover:bg-black hover:text-white">Reiniciar recorrido</button>
                </form>
            </section>
        @elseif ($step === 'cotizacion')
            <section class="rounded-2xl border border-primary bg-white p-6 text-black sm:p-8">
                <p class="text-xs font-bold uppercase tracking-widest text-primary">Cotización DEMO · tarifas vigentes</p>
                <h1 class="mt-3 text-2xl font-black">Su cotización mensual</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">Calculada con las tarifas vigentes del sistema para <strong>{{ $countries[$answers['country_iso'] ?? ''] ?? '—' }}</strong> y la modalidad <strong>{{ $modalities[$answers['modality'] ?? ''] ?? '—' }}</strong>.</p>

                @if ($quote)
                    <ul class="mt-6 divide-y divide-slate-200">
                        @foreach ($quote->lines as $line)
                            <li class="flex items-start justify-between gap-4 py-3 text-sm">
                                <span class="font-semibold">{{ $line->label }}</span>
                                <span class="font-bold">{{ number_format((float) $line->amount, $quote->currency === 'CRC' ? 2 : 0, ',', '.') }} {{ $quote->currency }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-4 flex items-center justify-between rounded-xl border border-black p-4">
                        <span class="text-sm font-bold uppercase tracking-widest">Total mensual</span>
                        <span class="text-lg font-black">{{ number_format((float) $quote->monthlyTotal, $quote->currency === 'CRC' ? 2 : 0, ',', '.') }} {{ $quote->currency }}</span>
                    </div>
                @endif

                <div class="mt-6 rounded-xl border-2 border-primary bg-black p-4 text-center">
                    <p class="text-sm font-bold text-primary">Pago de demostración — no se cobrará dinero</p>
                    <p class="mt-1 text-xs text-slate-300">La confirmación es simulada: no se usa tarjeta, SINPE, Stripe ni ningún medio de pago real.</p>
                </div>

                <form method="POST" action="{{ route('cobros.demo.tour.pay') }}" class="mt-6">
                    @csrf
                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-primary px-4 py-3 text-sm font-bold text-black transition hover:bg-primary-hover hover:text-white">Confirmar pago simulado</button>
                </form>

                <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('cobros.demo.tour.step', ['step' => 'resumen']) }}" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl border border-white px-4 py-3 text-sm font-bold text-white transition hover:border-primary hover:text-primary">Anterior</a>
                    <a href="{{ route('cobros.demo.home') }}" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl border border-white px-4 py-3 text-sm font-bold text-white transition hover:border-primary hover:text-primary">Volver al inicio</a>
                </div>
            </section>
        @elseif ($step === 'activacion')
            <section class="rounded-2xl border border-primary bg-white p-6 text-black sm:p-8">
                <p class="text-xs font-bold uppercase tracking-widest text-primary">Activación simulada</p>
                <h1 class="mt-3 text-2xl font-black">¡Listo! Su espacio DEMO queda activo</h1>
                <p class="mt-4 text-sm leading-6 text-slate-600">Hemos simulado la activación de su membresía CobrOS. En un registro real, este es el punto donde se habilitaría su licencia y acceso al sistema.</p>

                @if ($payment)
                    <dl class="mt-6 space-y-2 rounded-xl border border-black p-4 text-sm">
                        <div class="flex justify-between gap-4"><dt class="font-semibold text-slate-600">Operación</dt><dd class="font-mono">{{ $payment['operation_id'] }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="font-semibold text-slate-600">Monto</dt><dd>{{ number_format((float) $payment['amount'], $payment['currency'] === 'CRC' ? 2 : 0, ',', '.') }} {{ $payment['currency'] }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="font-semibold text-slate-600">Modalidad</dt><dd>{{ $modalities[$payment['modality']] ?? $payment['modality'] }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="font-semibold text-slate-600">Tipo</dt><dd class="font-bold text-primary">Simulado — sin cobro real</dd></div>
                    </dl>
                @endif

                <p class="mt-6 rounded-xl border border-black p-4 text-sm leading-6 text-slate-600">Hemos simulado la activación de su membresía CobrOS. En un registro real, este es el punto donde se habilitaría su licencia y acceso al sistema. Entre ahora a su entorno DEMO con datos de ejemplo.</p>

                {{-- INTEGRACIÓN-05: botón solo si el DEMO está habilitado
                     (config + entorno permitido); la ruta responde 404 si no. --}}
                @if (\App\Services\Cobros\CobrosDemoAccess::enabled() && \Illuminate\Support\Facades\Route::has('cobros.demo.tour.enter'))
                    <form method="POST" action="{{ route('cobros.demo.tour.enter') }}" class="mt-3">
                        @csrf
                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-primary px-4 py-3 text-sm font-bold text-white transition hover:bg-primary-hover">Entrar al sistema DEMO</button>
                    </form>
                @else
                    <p class="mt-3 text-center text-sm text-slate-600">El acceso al sistema DEMO se habilita en una fase posterior de este demo.</p>
                @endif

                <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('cobros.demo.tour.step', ['step' => 'cotizacion']) }}" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl border border-white px-4 py-3 text-sm font-bold text-white transition hover:border-primary hover:text-primary">Ver cotización</a>
                    <a href="{{ route('cobros.demo.home') }}" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl border border-white px-4 py-3 text-sm font-bold text-white transition hover:border-primary hover:text-primary">Volver al inicio</a>
                </div>

                <form method="POST" action="{{ route('cobros.demo.tour.reset') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-black px-4 py-2 text-sm font-bold text-black transition hover:bg-black hover:text-white">Reiniciar recorrido</button>
                </form>
            </section>
        @endif
    </main>

    <footer class="border-t border-white py-6">
        <p class="mx-auto max-w-3xl px-4 text-center text-xs text-slate-400 sm:px-6">MVS CobrOS · Profesional por dentro. Sencillo por fuera.</p>
    </footer>
</body>
</html>
