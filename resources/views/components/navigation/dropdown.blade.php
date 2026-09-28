@props([
    'icon',
    'label',
    'active' => false,
])

<div x-data="{ open: @js($active) }">
    
    <button
        @click="open = !open"
        class="flex w-full items-center justify-between gap-4 rounded-xl px-4 py-3 text-slate-300 transition-all duration-200 hover:bg-slate-800 hover:text-white {{ $active ? 'bg-slate-800 text-white' : '' }}">

        <div class="flex items-center gap-4">

            <div class="flex h-6 w-6 items-center justify-center">

                @switch($icon)

                    @case('settings')

                        <svg xmlns="http://www.w3.org/2000/svg"
                            class="h-6 w-6"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2">

                            <path stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M10.325 4.317a1 1 0 011.35-.936l1.623.541a1 1 0 00.632 0l1.623-.54a1 1 0 011.35.935l.093 1.71a1 1 0 00.29.64l1.21 1.21a1 1 0 010 1.414l-1.21 1.21a1 1 0 00-.29.64l-.093 1.71a1 1 0 01-1.35.936l-1.623-.541a1 1 0 00-.632 0l-1.623.54a1 1 0 01-1.35-.935l-.093-1.71a1 1 0 00-.29-.64l-1.21-1.21a1 1 0 010-1.414l1.21-1.21a1 1 0 00.29-.64l.093-1.71z"/>

                        </svg>

                    @break

                    @default

                        <div class="h-3 w-3 rounded-full bg-slate-500"></div>

                @endswitch

            </div>

            <span class="nav-fade text-sm font-medium">
                {{ $label }}
            </span>

        </div>


        <span aria-hidden="true" :class="open ? 'rotate-180' : ''" class="nav-fade text-xs transition-transform">▼</span>

    </button>


    <div
    x-cloak
    x-show="open"
    x-transition
    class="nav-sub mt-2 space-y-1 pl-10">

    {{ $slot }}

</div>

</div>
