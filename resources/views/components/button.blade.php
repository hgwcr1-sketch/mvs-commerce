@props([
    'type' => 'button',
    'color' => 'primary',
])

@php
    $colors = [
        'primary' => 'bg-amber-500 hover:bg-amber-600 text-slate-900',
        'secondary' => 'bg-slate-600 hover:bg-slate-700 text-white',
        'success' => 'bg-emerald-600 hover:bg-emerald-700 text-white',
        'danger' => 'bg-red-600 hover:bg-red-700 text-white',
    ];
@endphp

<button
    type="{{ $type }}"
    {{ $attributes->merge([
        'class' => 'inline-flex items-center justify-center rounded-lg px-4 py-2 font-semibold transition duration-200 ' . ($colors[$color] ?? $colors['primary'])
    ]) }}>
    {{ $slot }}
</button>