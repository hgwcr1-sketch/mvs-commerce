@extends('layouts.app')

@section('title', 'Ayuda y Soporte')

@section('content')
@php
    $company = \App\Models\Company::find(session('active_company_id'));
    $branch = session('active_branch_id') ? \App\Models\Branch::find(session('active_branch_id')) : null;
    $message = 'Hola, necesito ayuda con MVS Commerce.'.($company ? " Empresa: {$company->trade_name}." : '').($branch ? " Sucursal: {$branch->name}." : '');
@endphp
<div class="mx-auto max-w-3xl px-3 py-6 sm:px-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <p class="text-sm font-bold uppercase tracking-wide text-primary">MVS Commerce</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-900">Ayuda y soporte</h1>
        <p class="mt-2 text-slate-600">Nuestro equipo está listo para ayudarle con su operación.</p>
        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <a href="mailto:soporte@mvscommerse.com?subject={{ rawurlencode('Solicitud de soporte MVS Commerce') }}&body={{ rawurlencode($message) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-primary px-5 py-3 font-bold text-slate-900 hover:bg-primary-hover">Enviar ticket por correo</a>
            <a href="https://wa.me/50688133806?text={{ rawurlencode($message) }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-5 py-3 font-bold text-slate-700 hover:bg-slate-50">WhatsApp</a>
        </div>
    </div>
</div>
@endsection
