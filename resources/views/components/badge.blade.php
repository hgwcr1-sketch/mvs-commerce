@props([
    'type' => 'success',
])

@php
    $styles = [
        'success' => 'bg-emerald-100 text-emerald-700',
        'danger'  => 'bg-red-100 text-red-700',
        'warning' => 'bg-amber-100 text-amber-700',
        'info'    => 'bg-amber-100 text-[#B1922D]',
        'gray'    => 'bg-slate-100 text-slate-700',
    ];
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ' . ($styles[$type] ?? $styles['gray'])
]) }}>
    {{ $slot }}
</span>