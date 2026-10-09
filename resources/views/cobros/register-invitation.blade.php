{{-- BLOQUE 16C-2B f1 — aviso de invitación laboral en el registro público.
     Solo nombre y razón comercial del prestamista: sin token, sin id, sin
     huella y sin datos internos de la empresa. --}}
@if (session('invitation_error'))
    <div role="alert" class="mx-auto mt-6 max-w-5xl rounded-xl border border-red-600 bg-red-950 p-4 text-sm leading-6 text-white">
        {{ session('invitation_error') }}
    </div>
@endif

@if (session('invitation_warning'))
    <div role="status" class="mx-auto mt-6 max-w-5xl rounded-xl border border-primary p-4 text-sm leading-6 text-white">
        {{ session('invitation_warning') }}
    </div>
@endif

@if (! empty($laborInvitation))
    <div role="status" class="mx-auto mt-6 flex max-w-5xl flex-col gap-1 rounded-xl border border-primary bg-primary/10 p-4 text-sm leading-6 text-white sm:flex-row sm:items-center sm:gap-3">
        <span class="inline-flex min-h-11 items-center rounded-lg bg-primary px-3 py-1 text-xs font-bold uppercase tracking-widest text-black sm:min-h-0">Invitación laboral</span>
        <span>
            Te invita <strong>{{ $laborInvitation['lender_name'] }}</strong>@if (($laborInvitation['company'] ?? '') !== '')<span class="text-slate-300"> · {{ $laborInvitation['company'] }}</span>@endif.
            Esta invitación se conserva durante todo su registro.
        </span>
    </div>
@endif
