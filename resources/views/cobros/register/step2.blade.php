<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Registro de MVS CobrOS: datos personales.">
    <meta name="theme-color" content="#D4AF37">
    <title>Registro | MVS CobrOS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-black text-white antialiased">
    @include('cobros.public-header')

    <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-10">
        @include('cobros.register-progress', ['step' => $step, 'progressSteps' => $progressSteps])

        <section class="mt-8 rounded-2xl bg-white p-5 text-black sm:p-6">
            <p class="text-xs font-bold uppercase tracking-widest text-black">Paso 2 de 3</p>
            <h1 class="mt-2 text-2xl font-bold">Datos personales</h1>
            <p class="mt-2 text-sm leading-6 text-slate-600">Su progreso se guarda en este dispositivo hasta que confirme el alta. Puede regresar a corregir el paso anterior.</p>

            @if ($errors->any())
                <div role="alert" class="mt-4 rounded-xl border border-black p-4 text-sm">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('cobros.register.personal') }}" class="mt-6 space-y-5">
                @csrf

                <label class="block text-sm font-bold">Nombre completo
                    <input type="text" name="name" value="{{ old('name', $registration['personal']['name'] ?? '') }}" required minlength="3" maxlength="150" autocomplete="name" class="mt-2 min-h-11 w-full rounded-xl border border-black px-4 py-3 font-normal outline-none">
                </label>

                <label class="block text-sm font-bold">Correo electrónico
                    <input type="email" name="email" value="{{ old('email', $registration['personal']['email'] ?? '') }}" required maxlength="150" autocomplete="email" inputmode="email" class="mt-2 min-h-11 w-full rounded-xl border border-black px-4 py-3 font-normal outline-none">
                </label>

                <label class="block text-sm font-bold">Teléfono (opcional)
                    <input type="tel" name="phone" value="{{ old('phone', $registration['personal']['phone'] ?? '') }}" maxlength="50" autocomplete="tel" inputmode="tel" class="mt-2 min-h-11 w-full rounded-xl border border-black px-4 py-3 font-normal outline-none">
                </label>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <a href="{{ route('cobros.register.step', ['paso' => 1]) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-black px-4 py-3 text-sm font-bold">Volver y corregir</a>
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-primary px-5 py-3 font-bold text-black transition hover:bg-primary-hover hover:text-white">Continuar a rutas</button>
                </div>
            </form>
        </section>
    </main>

    <footer class="border-t border-white py-6">
        <p class="mx-auto max-w-5xl px-4 text-center text-xs text-slate-400 sm:px-6">MVS CobrOS · Profesional por dentro. Sencillo por fuera.</p>
    </footer>
</body>
</html>
