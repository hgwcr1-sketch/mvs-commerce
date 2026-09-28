@props([
    'route',
    'label',
    'parameters' => [],
    'active' => null,
])

<a
    href="{{ route($route, $parameters) }}"
    class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-slate-400 transition hover:bg-slate-800 hover:text-white
    {{ ($active ?? request()->routeIs($route.'*'))
        ? 'bg-slate-800 text-white'
        : '' }}">

    <span class="h-2 w-2 rounded-full bg-amber-500"></span>

    <span>
        {{ $label }}
    </span>

</a>
