@extends('cobros.layout')

@section('title', 'Cargar mi cartera actual — MVS CobrOS')

@section('content')
{{-- Cartera inicial POR COBRADOR: hasta finalizar es su pantalla principal. --}}
<div class="mx-auto max-w-2xl space-y-4">
    <a href="{{ route('cobros.collector.portfolio') }}" class="inline-flex min-h-[44px] items-center gap-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
        ← MI CARTERA
    </a>

    <div class="rounded-xl border-2 border-primary bg-slate-900 p-4 text-white sm:p-6">
        <p class="text-xs font-medium uppercase tracking-wide text-amber-300">Onboarding extraordinario</p>
        <h1 class="mt-1 break-words text-2xl font-bold sm:text-3xl">Cargar mi cartera actual</h1>
        <p class="mt-3 text-sm leading-relaxed text-slate-200">
            Únicamente para cargar los clientes que usted tenía <strong>antes de CobrOS</strong>.
            Puede salir y continuar después sin perder nada. Al finalizar, la opción
            <strong class="text-amber-300">desaparecerá</strong> y quedará registrada su foto de apertura.
        </p>
        <p class="mt-2 text-xs leading-relaxed text-slate-300">
            Todo lo cargado queda marcado de por vida como
            <strong>CARTERA INICIAL · INFORMACIÓN DECLARADA</strong>, no como historial verificado por CobrOS.
        </p>
    </div>

    @if(session('success'))
        <div role="status" class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-800">{{ session('success') }}</div>
    @endif

    @if($state === \App\Services\Cobros\InitialPortfolioMigrationService::STATE_AVAILABLE)
        <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
            <p class="text-base font-semibold text-slate-800">Estado: <span class="text-green-700">Disponible</span></p>
            <form method="POST" action="{{ route('cobros.initial-portfolio.start') }}" class="mt-4">
                @csrf
                <button type="submit" class="flex min-h-[56px] w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-4 text-lg font-bold text-slate-950 hover:bg-primary-hover">
                    COMENZAR A CARGAR MI CARTERA
                </button>
            </form>
        </div>
    @elseif($state === \App\Services\Cobros\InitialPortfolioMigrationService::STATE_IN_PROGRESS)
        <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
            <p class="text-base font-semibold text-slate-800">Estado: <span class="text-amber-600">En progreso</span></p>
            <p class="mt-1 text-sm text-slate-500">
                Iniciada el {{ $migration?->started_at?->timezone($company->timezone)->format('d/m/Y H:i') }}.
                Clientes cargados: {{ $loadedClients }}.
            </p>

            <a href="{{ route('cobros.initial-portfolio.client') }}"
               class="mt-4 flex min-h-[56px] w-full items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-4 text-lg font-bold text-amber-300 hover:bg-slate-800">
                ➕ AGREGAR CLIENTE
            </a>

            <div class="mt-6 rounded-xl border-2 border-red-300 bg-red-50 p-4">
                <p class="text-sm font-bold uppercase tracking-wide text-red-700">Cierre definitivo</p>
                <p class="mt-1 text-sm text-red-800">
                    Al finalizar se congela la carga, se genera su foto de apertura y pasará
                    a trabajar y cobrar normalmente (sin aprobación de nadie).
                </p>
                <form method="POST" action="{{ route('cobros.initial-portfolio.finalize') }}" class="mt-3 space-y-3">
                    @csrf
                    <label for="confirm" class="block text-sm font-semibold text-slate-700">
                        Escriba <strong>FINALIZAR MIGRACIÓN</strong> para confirmar
                    </label>
                    <input id="confirm" name="confirm" type="text" autocomplete="off" required placeholder="FINALIZAR MIGRACIÓN"
                           class="mt-1 min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-3 text-base">
                    @error('confirm')
                        <p role="alert" class="text-sm font-semibold text-red-600">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="flex min-h-[56px] w-full items-center justify-center rounded-xl bg-red-700 px-4 py-4 text-lg font-bold text-white hover:bg-red-800">
                        FINALIZAR MIGRACIÓN
                    </button>
                </form>
            </div>
        </div>
    @else
        <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
            <p class="text-base font-semibold text-slate-800">Estado: <span class="text-slate-500">Finalizada</span></p>
            <p class="mt-1 text-sm text-slate-600">
                Congelada el {{ $migration?->finalized_at?->timezone($company->timezone)->format('d/m/Y H:i') }}.
                Ya puede trabajar y cobrar; los datos iniciales ya no se editan.
            </p>

            @if($snapshot)
                <div class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                    <p class="font-bold uppercase tracking-wide">Foto de apertura (inmutable)</p>
                    <p>Clientes: {{ $snapshot->payload['clients_count'] ?? 0 }}</p>
                    <p>Saldo inicial declarado: @include('cobros.components.money', ['amount' => $snapshot->payload['total_balance'] ?? '0', 'currency' => $company->currency])</p>
                    <p>Cuotas iniciales: @include('cobros.components.money', ['amount' => $snapshot->payload['total_installment'] ?? '0', 'currency' => $company->currency])</p>
                    <p class="mt-1 text-xs">Capturada: {{ $snapshot->captured_at->timezone($company->timezone)->format('d/m/Y H:i') }}</p>
                </div>
            @endif

            @if($migration && $migration->reviewed_at)
                <p class="mt-3 rounded-lg bg-green-50 px-3 py-2 text-sm font-semibold text-green-800">
                    Revisada por el prestamista el {{ $migration->reviewed_at->timezone($company->timezone)->format('d/m/Y H:i') }}.
                </p>
            @else
                <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">Pendiente de revisión por el prestamista (no afecta su trabajo).</p>
            @endif
        </div>

        @if($records->isNotEmpty())
            <h2 class="text-base font-bold text-slate-900">Mis clientes cargados ({{ $records->count() }})</h2>
            @foreach($records as $record)
                <details class="rounded-xl border border-slate-200 bg-white p-3">
                    <summary class="min-h-[48px] cursor-pointer list-none text-sm font-bold text-slate-800">
                        {{ $record->customer?->name }} · saldo @include('cobros.components.money', ['amount' => $record->balance, 'currency' => $company->currency])
                    </summary>
                    @if($state === \App\Services\Cobros\InitialPortfolioMigrationService::STATE_FINALIZED)
                        <form method="POST" action="{{ route('cobros.initial-portfolio.correction.request', $record) }}" class="mt-3 space-y-2">
                            @csrf
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Solicitar corrección (dato congelado)</p>
                            <select name="field" required class="min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                <option value="original_amount">Monto original</option>
                                <option value="balance">Saldo inicial</option>
                                <option value="installment">Cuota</option>
                                <option value="frequency">Frecuencia</option>
                                <option value="next_charge_date">Próxima fecha</option>
                                <option value="prior_credits">Créditos anteriores</option>
                                <option value="declared_behavior">Comportamiento declarado</option>
                                <option value="historical_note">Observación histórica</option>
                            </select>
                            <input name="requested_value" type="text" required maxlength="60" placeholder="Valor correcto"
                                   class="min-h-[48px] w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <textarea name="reason" rows="2" required minlength="8" maxlength="250" placeholder="Motivo"
                                      class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                            <button type="submit" class="flex min-h-[48px] w-full items-center justify-center rounded-xl border-2 border-primary bg-white px-3 py-2 text-sm font-bold text-slate-900 hover:bg-primary/10">
                                SOLICITAR CORRECCIÓN
                            </button>
                        </form>
                    @else
                        <p class="mt-2 text-xs text-slate-500">Disponible al finalizar la carga.</p>
                    @endif
                </details>
            @endforeach
        @endif
    @endif
</div>
@endsection
