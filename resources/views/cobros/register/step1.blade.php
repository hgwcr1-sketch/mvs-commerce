<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Registro de MVS CobrOS: país, modalidad y datos personales.">
    <meta name="theme-color" content="#D4AF37">
    <title>Registro | MVS CobrOS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-black text-white antialiased">
    @include('cobros.public-header', ['showLogin' => false])

    <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-10">
        @include('cobros.register-progress', ['step' => $step, 'progressSteps' => $progressSteps])
        @include('cobros.register-invitation')

        <section class="mt-8 rounded-2xl bg-white p-5 text-black sm:p-6">
            <p class="text-xs font-bold uppercase tracking-widest text-black">Paso 1 de 3</p>
            @php $modalityLocked = ($registration['modality'] ?? '') !== ''; @endphp
            <h1 class="mt-2 text-2xl font-bold">{{ $modalityLocked ? 'País' : 'País y modalidad' }}</h1>
            <p class="mt-2 text-sm leading-6 text-slate-600">
                @if ($modalityLocked)
                    Elija dónde opera su negocio. La modalidad elegida se conserva automáticamente.
                @else
                    Elija dónde opera y cómo administrará su cobranza. Podrá corregir estos datos antes de la revisión.
                @endif
            </p>

            @if ($errors->any())
                <div role="alert" class="mt-4 rounded-xl border border-black p-4 text-sm">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

                <form method="POST" action="{{ route('cobros.register.country') }}" class="mt-6 space-y-6">
                @csrf

                <fieldset>
                    <legend class="text-sm font-bold">País habilitado</legend>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($countries as $iso => $countryName)
                            <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border border-black p-3">
                                <input type="radio" name="country_iso" value="{{ $iso }}" class="h-5 w-5" @checked(old('country_iso', $registration['country_iso'] ?? '') === $iso) required>
                                <span class="font-semibold">{{ $countryName }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs leading-5 text-slate-600">Los demás países podrán solicitar su habilitación próximamente.</p>
                    @include('cobros.country-cta')
                </fieldset>

                @if ($modalityLocked)
                    {{-- PRE-19B-5C: la modalidad ya fue elegida (bienvenida/invitación);
                         se conserva oculta y NO se vuelve a mostrar la lista. --}}
                    <input type="hidden" name="modality" value="{{ $registration['modality'] }}">
                @else
                <fieldset>
                    <legend class="text-sm font-bold">Modalidad de operación</legend>
                    <div class="mt-3 grid grid-cols-1 gap-3">
                        @foreach ($modalities as $key => $definition)
                            <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-xl border border-black p-3">
                                <input type="radio" name="modality" value="{{ $key }}" class="mt-1 h-5 w-5" @checked(old('modality', $registration['modality'] ?? '') === $key) required>
                                <span>
                                    <span class="font-semibold">{{ $definition['label'] }}</span>
                                    <span class="block text-sm leading-6 text-slate-600">{{ $definition['description'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                @endif

                <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-primary px-5 py-3 font-bold text-black transition hover:bg-primary-hover hover:text-white sm:w-auto">Continuar</button>
            </form>
        </section>
    </main>

    <footer class="border-t border-white py-6">
        <p class="mx-auto max-w-5xl px-4 text-center text-xs text-slate-400 sm:px-6">MVS CobrOS · Profesional por dentro. Sencillo por fuera.</p>
    </footer>
</body>
</html>
