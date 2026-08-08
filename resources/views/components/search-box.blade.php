@props([
    'action' => '',
    'placeholder' => 'Buscar...',
])

<form method="GET" action="{{ $action }}" class="mb-6">

    <div class="relative">

        <input
            type="text"
            name="search"
            value="{{ request('search') }}"
            placeholder="{{ $placeholder }}"
            class="w-full rounded-lg border border-slate-300 bg-white py-3 pl-4 pr-10 focus:border-amber-500 focus:ring-2 focus:ring-amber-500 outline-none">

        <svg xmlns="http://www.w3.org/2000/svg"
             class="absolute right-3 top-3.5 h-5 w-5 text-slate-400"
             fill="none"
             viewBox="0 0 24 24"
             stroke="currentColor">

            <path stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15z" />

        </svg>

    </div>

</form>