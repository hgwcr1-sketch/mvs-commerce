@props([
    'icon',
    'label',
    'route',
    'active' => false,
])

<a
    href="{{ route($route) }}"
    class="flex items-center gap-4 rounded-xl px-4 py-3 transition-all duration-200
    {{ $active
        ? 'bg-amber-500 text-slate-900 shadow-md'
        : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">

    <div class="flex h-6 w-6 items-center justify-center shrink-0">

        @switch($icon)

    @case('home')
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10.5L12 3l9 7.5M5.25 9.75V21h13.5V9.75"/>
        </svg>
    @break
    
            @case('cube')
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 16V8l-9-5-9 5v8l9 5 9-5z"/>
                </svg>
            @break

            @case('tag')
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 12l-8 8-8-8V4h8l8 8z"/>
                </svg>
            @break

            @case('users')
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2M16 3.13a4 4 0 010 7.75M23 21v-2a4 4 0 00-3-3.87M9 7a4 4 0 110 8 4 4 0 010-8z"/>
                </svg>
            @break

            @default
                <div class="h-3 w-3 rounded-full bg-slate-500"></div>

        @endswitch

    </div>

    <span class="text-sm font-medium whitespace-nowrap">
        {{ $label }}
    </span>

</a>