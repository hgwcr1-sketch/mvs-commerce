@extends('layouts.app')

@section('title', 'Detalle de sesión de Caja')

@section('content')
@php
    $opened = $cashSession->opened_at?->timezone($companyTimezone);
    $closed = $cashSession->closed_at?->timezone($companyTimezone);
    $duration = $cashSession->opened_at
        ? $cashSession->opened_at->diffForHumans($cashSession->closed_at ?? now(), true)
        : null;

    $money = fn ($value) => '₡'.number_format((float) $value, 0, ',', '.');
@endphp

<div class="mx-auto max-w-6xl space-y-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h2 class="text-2xl font-semibold">
                Sesión {{ $cashSession->session_number }}
            </h2>

            <p class="text-sm text-slate-600">
                {{ $cashSession->company?->trade_name }}
                · {{ $cashSession->branch?->name }}
                · {{ $cashSession->cashRegister?->name }}
            </p>
        </div>

        <a
            href="{{ route('cash.history.index') }}"
            class="rounded-lg border border-slate-300 px-4 py-2"
        >
            Volver
        </a>
    </div>

    @if(session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-red-800">
            {{ $errors->first() }}
        </div>
    @endif

    <x-card>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

            <div>
                <span class="text-xs uppercase text-slate-500">Estado</span>
                <strong class="block">{{ $cashSession->status }}</strong>
            </div>

            <div>
                <span class="text-xs uppercase text-slate-500">Cajero</span>
                <strong class="block">{{ $cashSession->openedBy?->name }}</strong>
            </div>

            <div>
                <span class="text-xs uppercase text-slate-500">Apertura</span>
                <strong class="block">{{ $opened?->format('d/m/Y H:i') }}</strong>
            </div>

            <div>
                <span class="text-xs uppercase text-slate-500">Cierre</span>
                <strong class="block">
                    {{ $closed?->format('d/m/Y H:i') ?? 'Pendiente' }}
                </strong>
            </div>

            <div>
                <span class="text-xs uppercase text-slate-500">Inició cierre</span>
                <strong class="block">
                    {{ $cashSession->closingStartedBy?->name ?? '—' }}
                </strong>
            </div>

            <div>
                <span class="text-xs uppercase text-slate-500">Cerró</span>
                <strong class="block">
                    {{ $cashSession->closedBy?->name ?? '—' }}
                </strong>
            </div>

            <div>
                <span class="text-xs uppercase text-slate-500">Duración</span>
                <strong class="block">{{ $duration ?? '—' }}</strong>
            </div>

            @if($sensitive)
                <div>
                    <span class="text-xs uppercase text-slate-500">Fondo inicial</span>
                    <strong class="block">
                        {{ $money($cashSession->opening_amount) }}
                    </strong>
                </div>
            @endif

        </div>
    </x-card>

    @if(!$sensitive)

        <x-card>
            <p class="text-slate-600">
                El detalle financiero y el estado técnico de los correos requieren
                el permiso de administración de Caja.
            </p>
        </x-card>

    @else

        <x-card>
            <div class="grid gap-4 sm:grid-cols-3">

                <div>
                    <span class="text-xs uppercase text-slate-500">Esperado</span>
                    <strong class="block text-xl">
                        {{ $cashSession->closing_submitted_at
                            ? $money($cashSession->expected_cash)
                            : 'Pendiente' }}
                    </strong>
                </div>

                <div>
                    <span class="text-xs uppercase text-slate-500">Contado</span>
                    <strong class="block text-xl">
                        {{ $cashSession->closing_submitted_at
                            ? $money($cashSession->counted_cash)
                            : 'Pendiente' }}
                    </strong>
                </div>

                <div>
                    <span class="text-xs uppercase text-slate-500">Diferencia</span>
                    <strong class="block text-xl">
                        {{ $cashSession->closing_submitted_at
                            ? $money($cashSession->difference_amount)
                            : 'Pendiente' }}
                    </strong>
                </div>

            </div>

            @if($cashSession->closing_notes)
                <p class="mt-4 whitespace-pre-line text-sm">
                    <strong>Notas:</strong> {{ $cashSession->closing_notes }}
                </p>
            @endif
        </x-card>

        <div class="grid gap-6 lg:grid-cols-2">

            <x-card>
                <x-slot:header>
                    <h3 class="font-semibold">Ventas completadas</h3>
                </x-slot:header>

                <p class="mb-3 text-lg font-semibold">
                    {{ $cashSession->sales->count() }} ventas
                    · {{ $money($cashSession->sales->sum('total')) }}
                </p>

                @foreach($cashSession->sales as $sale)
                    <div class="border-t py-2">
                        <strong>{{ $sale->sale_number }}</strong>

                        <span class="float-right">
                            {{ $money($sale->total) }}
                        </span>

                        @foreach($sale->payments as $payment)
                            <div class="text-sm text-slate-600">
                                {{ $payment->paymentMethod?->name }}:
                                {{ $money($payment->amount) }}
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </x-card>

            <x-card>
                <x-slot:header>
                    <h3 class="font-semibold">Movimientos</h3>
                </x-slot:header>

                @forelse($cashSession->movements->sortByDesc('occurred_at') as $movement)
                    <div class="border-t py-2">
                        <strong>
                            {{ [
                                'entry' => 'Entrada',
                                'exit' => 'Salida',
                                'withdrawal' => 'Retiro',
                            ][$movement->type] ?? $movement->type }}
                        </strong>

                        <span class="float-right">
                            {{ $money($movement->amount) }}
                        </span>

                        <div class="text-sm text-slate-600">
                            {{ $movement->reason }}
                            · {{ $movement->createdBy?->name }}
                        </div>
                    </div>
                @empty
                    <p class="text-slate-500">Sin movimientos.</p>
                @endforelse
            </x-card>

        </div>

        <x-card>
            <x-slot:header>
                <h3 class="font-semibold">Conteo por denominación</h3>
            </x-slot:header>

            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr>
                            <th class="p-3 text-left">Denominación</th>
                            <th class="p-3 text-right">Valor snapshot</th>
                            <th class="p-3 text-right">Cantidad</th>
                            <th class="p-3 text-right">Subtotal</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($cashSession->countDetails->sortBy('cashDenomination.sort_order') as $detail)
                            <tr class="border-t">
                                <td class="p-3">
                                    {{ $detail->cashDenomination?->label ?? 'Histórica' }}
                                </td>

                                <td class="p-3 text-right">
                                    {{ $money($detail->denomination_value) }}
                                </td>

                                <td class="p-3 text-right">
                                    {{ $detail->quantity }}
                                </td>

                                <td class="p-3 text-right">
                                    {{ $money($detail->total_amount) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="p-4 text-center text-slate-500">
                                    Conteo pendiente.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-card>
            <x-slot:header>
                <h3 class="font-semibold">Conciliaciones</h3>
            </x-slot:header>

            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr>
                            <th class="p-3 text-left">Método snapshot</th>
                            <th class="p-3 text-right">Ventas</th>
                            <th class="p-3 text-right">CxC</th>
                            <th class="p-3 text-right">Apartados</th>
                            <th class="p-3 text-right">CxP</th>
                            <th class="p-3 text-right">Esperado</th>
                            <th class="p-3 text-right">Reportado</th>
                            <th class="p-3 text-right">Diferencia</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($cashSession->paymentReconciliations as $item)
                            <tr class="border-t">
                                <td class="p-3">
                                    {{ $item->payment_method_name_snapshot }}
                                    ({{ $item->payment_method_code_snapshot }})
                                </td>

                                <td class="p-3 text-right">{{ $money($item->sales_amount) }}</td>
                                <td class="p-3 text-right">{{ $money($item->receivables_amount) }}</td>
                                <td class="p-3 text-right">{{ $money($item->layaways_amount) }}</td>
                                <td class="p-3 text-right">{{ $money($item->payables_amount) }}</td>

                                <td class="p-3 text-right">
                                    {{ $money($item->expected_amount) }}
                                </td>

                                <td class="p-3 text-right">
                                    {{ $money($item->reported_amount) }}
                                </td>

                                <td class="p-3 text-right">
                                    {{ $money($item->difference_amount) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="p-4 text-center text-slate-500">
                                    Sin conciliaciones.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="grid gap-6 lg:grid-cols-2">

            <x-card>
                <x-slot:header>
                    <h3 class="font-semibold">Autorización y eventos</h3>
                </x-slot:header>

                <p class="mb-3">
                    <strong>Autorizó diferencia:</strong>
                    {{ $cashSession->differenceAuthorizedBy?->name ?? 'No aplica' }}
                </p>

                @foreach($cashSession->events->sortByDesc('occurred_at') as $event)
                    <div class="border-t py-2">
                        <strong>{{ $event->event_type }}</strong>

                        <div class="text-sm text-slate-600">
                            {{ $event->user?->name ?? 'Sistema' }}
                            ·
                            {{ $event->occurred_at?->timezone($companyTimezone)->format('d/m/Y H:i:s') }}
                        </div>
                    </div>
                @endforeach
            </x-card>

            <x-card>
                <x-slot:header>
                    <h3 class="font-semibold">Notificaciones</h3>
                </x-slot:header>

                @forelse($cashSession->mailNotifications as $notification)
                    <div class="border-t py-3">

                        <div>
                            <strong>{{ $notification->notification_type }}</strong>

                            <span class="float-right rounded-full bg-slate-100 px-2 py-1 text-xs">
                                {{ $notification->status }}
                            </span>
                        </div>

                        <dl class="mt-2 grid gap-1 text-sm">

                            <div>
                                <dt class="inline font-medium">Intentos:</dt>
                                <dd class="inline">{{ $notification->attempts }} / 5</dd>
                            </div>

                            <div>
                                <dt class="inline font-medium">Destinatarios:</dt>
                                <dd class="inline">
                                    {{ implode(', ', $notification->recipients ?? []) ?: 'Ninguno' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="inline font-medium">Entregados:</dt>
                                <dd class="inline">
                                    {{ implode(', ', $notification->delivered_recipients ?? []) ?: 'Ninguno' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="inline font-medium">Disponible:</dt>
                                <dd class="inline">
                                    {{ $notification->available_at?->timezone($companyTimezone)->format('d/m/Y H:i:s') ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="inline font-medium">Enviado:</dt>
                                <dd class="inline">
                                    {{ $notification->sent_at?->timezone($companyTimezone)->format('d/m/Y H:i:s') ?? '—' }}
                                </dd>
                            </div>

                            @if($notification->last_error)
                                <div>
                                    <dt class="inline font-medium">Último error:</dt>
                                    <dd class="inline break-words">
                                        {{ \Illuminate\Support\Str::limit(
                                            strip_tags($notification->last_error),
                                            300
                                        ) }}
                                    </dd>
                                </div>
                            @endif

                        </dl>

                        @if($notification->isAdministrativelyRetriable())
                            <form
                                method="POST"
                                action="{{ route(
                                    'cash.history.mail.retry',
                                    [$cashSession, $notification]
                                ) }}"
                                class="mt-3"
                            >
                                @csrf

                                <button
                                    class="rounded-lg bg-amber-500 px-4 py-2 font-normal text-black hover:bg-amber-600"
                                >
                                    Reintentar correo
                                </button>
                            </form>
                        @endif

                    </div>
                @empty
                    <p class="text-slate-500">Sin notificaciones.</p>
                @endforelse
            </x-card>

        </div>

    @endif

</div>
@endsection
