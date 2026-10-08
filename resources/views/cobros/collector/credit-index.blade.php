@extends('cobros.layout')

@section('title', 'Mis préstamos - MVS CobrOS')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold sm:text-3xl">Mis préstamos</h1>
        <p class="mt-1 text-sm text-slate-500">Créditos desembolsados a tu nombre en la empresa activa.</p>
    </div>

    @if ($credits->isEmpty())
        <div class="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-500 shadow-sm">
            Aún no tienes préstamos desembolsados.
        </div>
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                        <th class="px-3 py-3">Cliente</th>
                        <th class="px-3 py-3 text-right">Principal</th>
                        <th class="px-3 py-3">Estado</th>
                        <th class="px-3 py-3">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($credits as $credit)
                        <tr>
                            <td class="px-3 py-3 font-semibold text-slate-800"><span class="inline-flex items-center gap-1.5">@if($credit->customer)@include('cobros.components.credit-risk', ['customer' => $credit->customer, 'company' => $company])@endif<span>{{ $credit->customer?->name ?? 'Cliente' }}</span></span></td>
                            <td class="px-3 py-3 text-right">
                                @include('cobros.components.money', ['amount' => $credit->principal, 'currency' => $company->currency])
                            </td>
                            <td class="px-3 py-3">
                                <span class="rounded-full px-2 py-1 text-xs font-bold {{ $credit->status === \App\Models\CobrosCredit::STATUS_DISBURSED ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $credit->status === \App\Models\CobrosCredit::STATUS_DISBURSED ? 'Desembolsado' : 'Aprobado' }}
                                </span>
                            </td>
                            <td class="px-3 py-3">
                                @if ($credit->status === \App\Models\CobrosCredit::STATUS_DISBURSED)
                                    <a class="font-semibold text-primary underline" href="{{ route('cobros.collector.creditos.comprobante', $credit->id) }}">Comprobante</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
