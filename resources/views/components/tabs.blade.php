@props([
    'tabs' => [],
    'activeTab' => null,
    'variant' => 'default',
    'attributes' => [],
])

@php
    $activeTab = $activeTab ?? ($tabs[0]['id'] ?? null);
    $variantClasses = [
        'default' => 'border-b border-slate-200 bg-white',
        'card' => 'bg-white rounded-t-2xl border-t-4 border-amber-500',
        'pills' => 'bg-transparent',
    ];
    $tabBaseClasses = 'inline-flex items-center justify-center whitespace-nowrap rounded-xl px-4 py-3 text-sm font-semibold transition-all duration-200 min-h-[44px] min-w-[max-content] focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2';
    $activeClasses = [
        'default' => 'text-amber-600 border-b-2 border-amber-600 bg-amber-50',
        'card' => 'text-amber-700 bg-amber-50',
        'pills' => 'bg-amber-500 text-slate-900 shadow-md',
    ];
    $inactiveClasses = [
        'default' => 'text-slate-500 hover:text-slate-700 hover:bg-slate-50',
        'card' => 'text-slate-500 hover:text-slate-700 hover:bg-slate-50',
        'pills' => 'text-slate-500 hover:bg-slate-100',
    ];
@endphp

<div
    {{ $attributes->merge(['class' => 'w-full']) }}
    x-data="mvsTabs({ activeTab: @js($activeTab), tabs: @js($tabs) })"
    x-init="
        const hash = window.location.hash.slice(1);
        if (hash && tabs.some(t => t.id === hash)) {
            activeTab = hash;
        }
        $watch('activeTab', (value) => {
            if (value) {
                history.replaceState(null, '', '#' + value);
            }
        });
    "
    class="w-full"
    role="tablist"
    aria-label="{{ $attributes['aria-label'] ?? 'Pestañas' }}"
    @keydown.right.prevent="focusAdjacentTab(1)"
    @keydown.left.prevent="focusAdjacentTab(-1)"
    @keydown.home.prevent="focusBoundaryTab('first')"
    @keydown.end.prevent="focusBoundaryTab('last')"
>
    <div
        class="overflow-x-auto overscroll-x-contain -mx-4 px-4 pb-2 scrollbar-hide"
        role="presentation"
    >
        <nav
            class="flex gap-2 min-w-max"
            role="presentation"
        >
            @foreach($tabs as $tab)
                @php
                    $isActive = $activeTab === $tab['id'];
                    $tabClasses = $tabBaseClasses . ' ' . ($isActive ? $activeClasses[$variant] : $inactiveClasses[$variant]);
                @endphp
                @if(isset($tab['href']) || isset($tab['route']))
                    <a
                        href="{{ $tab['href'] ?? route($tab['route'], $tab['params'] ?? []) }}"
                        role="tab"
                        :aria-selected="activeTab === '{{ $tab['id'] }}'"
                        aria-controls="panel-{{ $tab['id'] }}"
                        id="tab-{{ $tab['id'] }}"
                        class="{{ $tabClasses }}"
                        @if(isset($tab['disabled']) && $tab['disabled']) aria-disabled="true" tabindex="-1" @endif
                    >
                        @if(isset($tab['icon']))
                            <svg class="h-5 w-5 shrink-0 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $tab['icon'] }}" />
                            </svg>
                        @endif
                        {{ $tab['label'] }}
                        @if(isset($tab['badge']))
                            <span class="ml-2 inline-flex items-center justify-center px-2 py-0.5 rounded-full text-xs font-medium {{ $isActive ? 'bg-amber-200 text-amber-800' : 'bg-slate-100 text-slate-600' }}">
                                {{ $tab['badge'] }}
                            </span>
                        @endif
                    </a>
                @else
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="activeTab === '{{ $tab['id'] }}'"
                        aria-controls="panel-{{ $tab['id'] }}"
                        id="tab-{{ $tab['id'] }}"
                        class="{{ $tabBaseClasses }}"
                        :class="activeTab === '{{ $tab['id'] }}' ? @js($activeClasses[$variant]) : @js($inactiveClasses[$variant])"
                        :tabindex="activeTab === '{{ $tab['id'] }}' ? 0 : -1"
                        @click="setTab('{{ $tab['id'] }}')"
                        @if(isset($tab['disabled']) && $tab['disabled']) aria-disabled="true" @endif
                    >
                        @if(isset($tab['icon']))
                            <svg class="h-5 w-5 shrink-0 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $tab['icon'] }}" />
                            </svg>
                        @endif
                        {{ $tab['label'] }}
                        @if(isset($tab['badge']))
                            <span class="ml-2 inline-flex items-center justify-center px-2 py-0.5 rounded-full text-xs font-medium {{ $isActive ? 'bg-amber-200 text-amber-800' : 'bg-slate-100 text-slate-600' }}">
                                {{ $tab['badge'] }}
                            </span>
                        @endif
                    </button>
                @endif
            @endforeach
        </nav>
    </div>

    @if($variant === 'card')
        <div class="border-l border-r border-b border-slate-200 rounded-b-2xl bg-white">
            <div class="p-4 sm:p-6">
                {{ $slot }}
            </div>
        </div>
    @else
        <div class="pt-4">
            {{ $slot }}
        </div>
    @endif
</div>
