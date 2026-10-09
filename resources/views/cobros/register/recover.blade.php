<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Recupere el borrador de registro de MVS CobrOS.">
    <meta name="theme-color" content="#D4AF37">
    <title>Recuperar registro | MVS CobrOS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-black text-white antialiased">
    @include('cobros.public-header')

    <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-10">
        <section class="mt-4 max-w-lg rounded-2xl bg-white p-5 text-black sm:p-6">
            <p class="text-xs font-bold uppercase tracking-widest text-black">Borrador de registro</p>
            <h1 class="mt-2 text-2xl font-bold">Recuperar mi progreso</h1>
            <p class="mt-2 text-sm leading-6 text-slate-600">Ingrese el correo con el que avanzó su registro y le enviaremos un enlace temporal de un solo uso para continuar donde quedó.</p>

            @if (session('recover_status'))
                <div role="status" class="mt-4 rounded-xl border border-black p-4 text-sm">{{ session('recover_status') }}</div>
            @endif

            @if (session('recover_error'))
                <div role="alert" class="mt-4 rounded-xl border border-black p-4 text-sm">{{ session('recover_error') }}</div>
            @endif

            @if ($errors->any())
                <div role="alert" class="mt-4 rounded-xl border border-black p-4 text-sm">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('cobros.register.recover.store') }}" class="mt-6 space-y-5">
                @csrf
                <label class="block text-sm font-bold">Correo electrónico
                    <input type="email" name="email" value="{{ old('email') }}" required maxlength="150" autocomplete="email" inputmode="email" class="mt-2 min-h-11 w-full rounded-xl border border-black px-4 py-3 font-normal outline-none">
                </label>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-primary px-5 py-3 font-bold text-black transition hover:bg-primary-hover hover:text-white">Enviar enlace de recuperación</button>
                    <a href="{{ route('cobros.register.step', ['paso' => 1]) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-black px-4 py-3 text-sm font-bold">Volver al registro</a>
                </div>
            </form>

            <p class="mt-5 text-xs leading-5 text-slate-600">El enlace caduca en una hora y solo puede usarse una vez. Nunca incluiremos sus datos personales ni contraseñas en el enlace.</p>
        </section>
    </main>

    <footer class="border-t border-white py-6">
        <p class="mx-auto max-w-5xl px-4 text-center text-xs text-slate-400 sm:px-6">MVS CobrOS · Profesional por dentro. Sencillo por fuera.</p>
    </footer>
</body>
</html>
