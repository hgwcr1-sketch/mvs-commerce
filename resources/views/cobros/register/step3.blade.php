<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Registro de MVS CobrOS: rutas y plan de cartera.">
    <meta name="theme-color" content="#D4AF37">
    <title>Registro | MVS CobrOS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-black text-white antialiased">
    @include('cobros.public-header')

    <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-10">
        @include('cobros.register-progress', ['step' => $step, 'progressSteps' => $progressSteps])

        @php
            $draftRoutes = $registration['routes'] ?? [];
            $editRoute = null;
            if ($editRouteId !== null) {
                foreach ($draftRoutes as $draftRoute) {
                    if ($draftRoute['id'] === $editRouteId) {
                        $editRoute = $draftRoute;
                        break;
                    }
                }
            }
        @endphp

        <section class="mt-8 rounded-2xl bg-white p-5 text-black sm:p-6">
            <p class="text-xs font-bold uppercase tracking-widest text-black">Paso 3 de 4</p>
            <h1 class="mt-2 text-2xl font-bold">Rutas y cartera</h1>
            <p class="mt-2 text-sm leading-6 text-slate-600">Configure una o varias rutas como borrador para {{ $countries[$registration['country_iso'] ?? ''] ?? 'su país' }} y su modalidad. Nada se crea operativamente hasta la activación.</p>

            @if ($errors->any())
                <div role="alert" class="mt-4 rounded-xl border border-black p-4 text-sm">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <h2 class="mt-6 text-sm font-bold uppercase tracking-widest text-black">Rutas en borrador</h2>

            @if (count($draftRoutes) === 0)
                <p class="mt-3 rounded-xl border border-black p-4 text-sm text-slate-600">Aún no ha agregado rutas. Puede agregarlas ahora o comenzar sin cartera.</p>
            @else
                <ul class="mt-3 grid grid-cols-1 gap-3">
                    @foreach ($draftRoutes as $index => $draftRoute)
                        <li class="flex flex-col gap-3 rounded-xl border border-black p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="font-semibold">{{ $index + 1 }}. {{ $draftRoute['name'] }}</p>
                                <p class="text-sm text-slate-600">
                                    @if ($draftRoute['code'] ?? null) Código: {{ $draftRoute['code'] }} · @endif
                                    @if ($draftRoute['lender'] ?? null) Prestamista: {{ $draftRoute['lender'] }} · @endif
                                    País: {{ $countries[$registration['country_iso'] ?? ''] ?? '—' }}
                                </p>
                            </div>
                            <div class="flex shrink-0 gap-2">
                                <a href="{{ route('cobros.register.step', ['paso' => 3, 'editar' => $draftRoute['id']]) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-black px-4 py-2 text-sm font-bold">Editar</a>
                                <form method="POST" action="{{ route('cobros.register.routes.remove') }}">
                                    @csrf
                                    <input type="hidden" name="route_id" value="{{ $draftRoute['id'] }}">
                                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-black px-4 py-2 text-sm font-bold">Eliminar</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('cobros.register.routes.save') }}" class="mt-6 space-y-4">
                @csrf
                @if ($editRoute)
                    <input type="hidden" name="route_id" value="{{ $editRoute['id'] }}">
                    <p class="text-sm font-bold uppercase tracking-widest text-black">Editando: {{ $editRoute['name'] }}</p>
                @endif

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <label class="block text-sm font-bold">Nombre de la ruta
                        <input type="text" name="name" value="{{ old('name', $editRoute['name'] ?? '') }}" required minlength="2" maxlength="100" class="mt-2 min-h-11 w-full rounded-xl border border-black px-4 py-3 font-normal outline-none">
                    </label>
                    <label class="block text-sm font-bold">Código (opcional)
                        <input type="text" name="code" value="{{ old('code', $editRoute['code'] ?? '') }}" maxlength="12" class="mt-2 min-h-11 w-full rounded-xl border border-black px-4 py-3 font-normal outline-none">
                    </label>
                </div>

                @if ($allowsLenderField)
                    <label class="block text-sm font-bold">Prestamista asociado (opcional)
                        <input type="text" name="lender" value="{{ old('lender', $editRoute['lender'] ?? '') }}" maxlength="150" class="mt-2 min-h-11 w-full rounded-xl border border-black px-4 py-3 font-normal outline-none">
                    </label>
                @endif

                <div class="flex flex-col gap-3 sm:flex-row">
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-primary px-5 py-3 font-bold text-black transition hover:bg-primary-hover hover:text-white">{{ $editRoute ? 'Guardar cambios' : 'Agregar ruta' }}</button>
                    @if ($editRoute)
                        <a href="{{ route('cobros.register.step', ['paso' => 3]) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-black px-4 py-3 text-sm font-bold">Cancelar edición</a>
                    @endif
                </div>
            </form>

            <form method="POST" action="{{ route('cobros.register.portfolio') }}" class="mt-8 space-y-5 border-t border-black pt-6">
                @csrf
                <fieldset>
                    <legend class="text-sm font-bold uppercase tracking-widest text-black">Origen de la cartera</legend>
                    <div class="mt-3 grid grid-cols-1 gap-3">
                        @foreach ($portfolioPlans as $planKey => $planLabel)
                            <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border border-black p-3">
                                <input type="radio" name="portfolio_plan" value="{{ $planKey }}" class="h-5 w-5" @checked(old('portfolio_plan', $registration['portfolio_plan'] ?? '') === $planKey) required>
                                <span class="font-semibold">{{ $planLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs leading-5 text-slate-600">La importación con plantillas Excel/CSV y el registro manual se habilitan dentro de su cuenta activa; este paso solo guarda su preferencia.</p>
                </fieldset>

                <div class="mt-4 rounded-xl border border-black p-4">
                    <p class="text-sm font-bold uppercase tracking-widest text-black">Machotes vacíos</p>
                    <p class="mt-1 text-xs leading-5 text-slate-600">Descargue y prepare sus archivos desde ahora. Son plantillas en blanco sin datos: no contienen información de otros usuarios y no importan nada todavía.</p>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <a href="{{ route('cobros.register.templates.customers') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-black px-4 py-3 text-sm font-bold">Descargar machote de clientes</a>
                        <a href="{{ route('cobros.register.templates.credits') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-black px-4 py-3 text-sm font-bold">Descargar machote de créditos</a>
                    </div>
                </div>

                @php
                    $kindLabels = ['customers' => 'Clientes', 'credits' => 'Créditos'];
                    $uploadsByRoute = collect($registration['uploads'] ?? [])
                        ->groupBy('route_id');
                @endphp

                @if (session('upload_status'))
                    <p role="status" class="mt-4 rounded-xl border border-black p-3 text-sm">{{ session('upload_status') }}</p>
                @endif

                @if (count($draftRoutes) > 0)
                    <div class="mt-4 rounded-xl border border-black p-4">
                        <p class="text-sm font-bold uppercase tracking-widest text-black">Archivos de cartera por ruta</p>
                        <p class="mt-1 text-xs leading-5 text-slate-600">Sus archivos se guardan de forma privada junto a su registro. Nada se importa ahora: todo queda «Pendiente de activación» hasta que su cuenta se active.</p>

                        <ul class="mt-3 grid grid-cols-1 gap-3">
                            @foreach ($draftRoutes as $draftRoute)
                                <li class="rounded-xl border border-black p-3">
                                    <p class="text-sm font-semibold">{{ $draftRoute['name'] }}</p>
                                    <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        @foreach (['customers' => 'cobros.register.uploads.customers', 'credits' => 'cobros.register.uploads.credits'] as $kind => $uploadRoute)
                                            @php $upload = ($uploadsByRoute[$draftRoute['id']] ?? collect())->firstWhere('kind', $kind); @endphp
                                            <div class="rounded-lg border border-black p-2">
                                                <p class="text-xs font-bold">{{ $kindLabels[$kind] }}</p>
                                                @if ($upload)
                                                    <p class="mt-1 text-xs leading-5 text-slate-600">{{ $upload['original_name'] }} · {{ $upload['rows'] }} fila(s)</p>
                                                    <p class="text-xs font-bold">Pendiente de activación</p>
                                                    <form method="POST" action="{{ route('cobros.register.uploads.replace', ['kind' => $kind]) }}" class="mt-2 space-y-2">
                                                        @csrf
                                                        <input type="hidden" name="route_id" value="{{ $draftRoute['id'] }}">
                                                        <input type="file" name="upload_file" accept=".csv,.xlsx,.xls,.txt" required class="block w-full text-sm">
                                                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-black px-3 py-2 text-sm font-bold">Reemplazar</button>
                                                    </form>
                                                    <form method="POST" action="{{ route('cobros.register.uploads.delete', ['kind' => $kind]) }}" class="mt-2">
                                                        @csrf
                                                        <input type="hidden" name="route_id" value="{{ $draftRoute['id'] }}">
                                                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-black px-3 py-2 text-sm font-bold">Eliminar</button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route($uploadRoute) }}" class="mt-2 space-y-2">
                                                        @csrf
                                                        <input type="hidden" name="route_id" value="{{ $draftRoute['id'] }}">
                                                        <input type="file" name="upload_file" accept=".csv,.xlsx,.xls,.txt" required class="block w-full text-sm">
                                                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-primary px-3 py-2 text-sm font-bold text-black transition hover:bg-primary-hover hover:text-white">Cargar</button>
                                                    </form>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-primary px-5 py-3 font-bold text-black transition hover:bg-primary-hover hover:text-white sm:w-auto">Continuar a revisión</button>
            </form>

            <p class="mt-5 text-center text-xs leading-5 text-slate-600"><a href="{{ route('cobros.register.recover') }}" class="font-bold underline">¿Perdió su progreso? Recupere su borrador por correo</a></p>
        </section>
    </main>

    <footer class="border-t border-white py-6">
        <p class="mx-auto max-w-5xl px-4 text-center text-xs text-slate-400 sm:px-6">MVS CobrOS · Profesional por dentro. Sencillo por fuera.</p>
    </footer>
</body>
</html>
