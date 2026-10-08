@extends('cobros.layout')

@section('title', $customer->name.' — Clientes — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <a href="{{ route('cobros.owner.customers') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Clientes</a>

    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold text-slate-900"><span class="inline-flex items-center gap-2">@include('cobros.components.credit-risk', ['customer' => $customer, 'company' => $company, 'size' => 'lg'])<span>{{ $customer->name }}</span></span></h1>
                <p class="mt-1 break-words text-sm text-slate-500">
                    {{ $customer->identification ?: 'Sin identificación' }}
                    @if($customer->phone || $customer->mobile)
                        · {{ $customer->phone ?: $customer->mobile }}
                    @endif
                </p>
                <p class="mt-1 text-sm">
                    Cobrador asignado:
                    @if($collector)
                        <a href="{{ route('cobros.owner.collectors.show', $collector->id) }}" class="font-semibold text-emerald-700 underline">{{ $collector->name }}</a>
                    @else
                        <span class="font-semibold text-slate-500">Sin asignar</span>
                    @endif
                </p>
            </div>
            <div class="text-right">
                <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Saldo total</span>
                <span class="text-2xl font-bold text-slate-900">@include('cobros.components.money', ['amount' => $totalBalance, 'currency' => $company->currency])</span>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <a href="{{ route('cobros.owner.customers.edit', $customer) }}" class="inline-flex min-h-[44px] items-center rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover">Editar</a>
            <a href="{{ route('cobros.owner.assignments') }}" class="inline-flex min-h-[44px] items-center rounded-xl border border-slate-300 px-5 text-sm font-semibold hover:bg-slate-50">Asignar cartera</a>
        </div>
    </div>

    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" aria-labelledby="customer-data">
        <h2 id="customer-data" class="text-lg font-semibold text-slate-900">Datos del cliente</h2>
        <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Correo</dt>
                <dd class="mt-0.5 break-words font-semibold text-slate-800">{{ $customer->email ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Tipo de cliente</dt>
                <dd class="mt-0.5 font-semibold text-slate-800">{{ $customer->customer_type === 'company' ? 'Empresa' : 'Persona física' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Dirección</dt>
                <dd class="mt-0.5 break-words font-semibold text-slate-800">{{ $customer->address ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Estado</dt>
                <dd class="mt-0.5 font-semibold {{ $customer->is_active ? 'text-emerald-700' : 'text-slate-500' }}">{{ $customer->is_active ? 'Activo' : 'Inactivo' }}</dd>
            </div>
        </dl>
        @if($customer->notes)
            <p class="mt-3 whitespace-pre-line break-words text-sm text-slate-600">{{ $customer->notes }}</p>
        @endif
    </section>

    <section aria-labelledby="cxc-open">
        <h2 id="cxc-open" class="text-lg font-semibold text-slate-900">Cuentas por cobrar abiertas ({{ $openAccounts->count() }})</h2>
        @if($openAccounts->isEmpty())
            <p class="mt-3 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">No hay cuentas por cobrar abiertas para este cliente.</p>
        @else
            <ul class="mt-3 space-y-3">
                @foreach($openAccounts as $account)
                    <li class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-900">{{ $account->sale?->sale_number ?: ('CxC #'.$account->id) }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">{{ $account->branch?->name ?: '—' }} · Emitida {{ $account->issued_at?->format('d/m/Y') }}</p>
                            </div>
                            <div class="text-right">
                                <span class="block text-xs uppercase tracking-wide text-slate-400">Saldo</span>
                                <span class="text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $account->balance_due, 'currency' => $company->currency])</span>
                            </div>
                        </div>
                        <p class="mt-2 text-sm {{ $account->due_date && $account->due_date->isPast() ? 'font-semibold text-red-700' : 'text-slate-600' }}">
                            @if($account->due_date)
                                Vence {{ $account->due_date->format('d/m/Y') }}{{ $account->due_date->isPast() ? ' · vencida' : '' }}
                            @else
                                Sin fecha de vencimiento
                            @endif
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="payments-history">
        <h2 id="payments-history" class="text-lg font-semibold text-slate-900">Historial de cobros</h2>
        @if($payments->isEmpty())
            <p class="mt-3 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">Este cliente todavía no tiene cobros registrados.</p>
        @else
            <ul class="mt-3 space-y-3">
                @foreach($payments as $payment)
                    <li class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900">
                                    {{ $payment->paid_at?->format('d/m/Y H:i') }}
                                    @if($payment->paymentMethod)
                                        · {{ $payment->paymentMethod->name }}
                                    @endif
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ $payment->user?->name ?: '—' }}
                                    @if($payment->cash_session_id)
                                        · con sesión de caja
                                    @else
                                        · sin sesión de caja
                                    @endif
                                </p>
                            </div>
                            <span class="text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $payment->amount, 'currency' => $company->currency])</span>
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                {{ $payments->links() }}
            </div>
        @endif
    </section>

    <details open class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <summary class="flex min-h-[48px] cursor-pointer items-center text-lg font-semibold text-slate-900">Expediente</summary>
        @if($expediente)
            <p class="mt-2 text-sm text-slate-700">{{ $expediente->occupation ? 'Completo' : 'Incompleto' }} · Trabajo: {{ $expediente->occupation ?? '—' }} · Referencias: {{ $expediente->references->count() }} · Documentos: {{ $expediente->documents->count() }}</p>
        @else
            <p class="mt-2 text-sm text-slate-700">Sin expediente.</p>
        @endif
        <a href="{{ route('cobros.owner.customers.expediente', $customer) }}" class="mt-3 inline-flex min-h-[44px] items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold">VER / EDITAR EXPEDIENTE</a>
    </details>

    <details class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <summary class="flex min-h-[48px] cursor-pointer items-center text-lg font-semibold text-slate-900">Solicitudes ({{ $creditRequests->count() }})</summary>
        @forelse($creditRequests as $req)
            <p class="mt-2 text-sm border-t border-slate-100 pt-2"><strong>{{ $req->created_at?->format('d/m/Y') }} · {{ $req->statusLabel() }}</strong> · <a class="text-amber-700 underline" href="{{ route('cobros.owner.solicitudes.show', ['id' => $req->id]) }}">Ver</a></p>
        @empty
            <p class="mt-2 text-sm text-slate-600">Sin solicitudes.</p>
        @endforelse
    </details>

    <details class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <summary class="flex min-h-[48px] cursor-pointer items-center text-lg font-semibold text-slate-900">Préstamos ({{ $credits->count() }})</summary>
        @forelse($credits as $cr)
            <p class="mt-2 text-sm border-t border-slate-100 pt-2"><strong>{{ $cr->public_reference ?? ('Préstamo #'.$cr->id) }} · {{ $cr->status }}</strong> · <a class="text-amber-700 underline" href="{{ route('cobros.owner.creditos.show', ['credit' => $cr->id]) }}">Ver</a></p>
        @empty
            <p class="mt-2 text-sm text-slate-600">Sin préstamos.</p>
        @endforelse
    </details>

    <section aria-labelledby="receipts-list">
        <h2 id="receipts-list" class="text-lg font-semibold text-slate-900">Últimos recibos vigentes ({{ $receipts->count() }})</h2>
        @if($receipts->isEmpty())
            <p class="mt-3 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">No hay recibos vigentes para este cliente.</p>
        @else
            <ul class="mt-3 space-y-3">
                @foreach($receipts as $receipt)
                    <li class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900">Recibo {{ $receipt->receipt_number }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">Emitido {{ $receipt->paid_at?->format('d/m/Y H:i') }}</p>
                            </div>
                            <span class="text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $receipt->amount, 'currency' => $receipt->currency_code ?: $company->currency])</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
@endsection
