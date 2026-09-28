@props(['company', 'branding' => null])
@php
    $brandName = $branding?->displayName($company) ?? $company->trade_name;
    $brandLogo = $branding?->logoUrl($company) ?? ($company->logo ? asset('storage/'.$company->logo) : null);
@endphp
<div {{ $attributes->class(['flex items-center gap-3']) }}>
    @if($brandLogo)
        <img src="{{ $brandLogo }}" alt="Logo de {{ $brandName }}" class="h-14 w-14 shrink-0 rounded-xl border border-slate-200 bg-white object-contain p-1.5">
    @else
        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl text-xl font-bold text-white" style="background-color:var(--portal-accent)" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($brandName), 0, 1)) }}</div>
    @endif
    <div class="min-w-0"><p class="truncate text-sm font-semibold" style="color:var(--portal-accent)">{{ $brandName }}</p><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Portal de Clientes</p></div>
</div>
