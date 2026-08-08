@props([
    'title',
    'value' => 0,
    'color' => 'slate',
])

@php

$colors = [

    'slate' => 'text-slate-800',

    'green' => 'text-green-600',

    'amber' => 'text-amber-500',

    'red' => 'text-red-600',

];

@endphp

<div class="card">

    <p class="text-sm text-slate-500">

        {{ $title }}

    </p>

    <h2 class="mt-2 text-4xl font-bold {{ $colors[$color] ?? $colors['slate'] }}">

        {{ $value }}

    </h2>

</div>