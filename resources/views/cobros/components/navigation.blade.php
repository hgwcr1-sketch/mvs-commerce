<nav class="{{ $panel ? 'cobros-panel-navigation' : 'cobros-navigation' }}" aria-label="{{ $panel ? 'Menú completo CobrOS' : 'Navegación CobrOS' }}">
    @foreach([
        ['permission' => 'cobros.portal_dueno', 'route' => 'cobros.owner.dashboard', 'label' => 'Inicio / Cartera', 'icon' => 'M3 10 12 3l9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z'],
        ['permission' => 'cobros.supervisar', 'route' => 'cobros.supervisor.index', 'label' => 'Supervisión', 'icon' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M22 21v-2a4 4 0 0 0-3-3.87 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M16 3a4 4 0 0 1 0 8'],
        ['permission' => 'cobros.cobrar', 'route' => 'cobros.collector.index', 'label' => 'Mi cartera', 'icon' => 'M3 5h16v4H3z M3 9v11h18V9H3 M16 13h5v4h-5z'],
    ] as $item)
        @if(auth()->user()->hasPermission($item['permission'], $company))
            <a href="{{ route($item['route']) }}" class="cobros-nav-item" title="{{ $item['label'] }}"
               @if(request()->routeIs($item['route'])) aria-current="page" @endif>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $item['icon'] }}" /></svg>
                <span>{{ $item['label'] }}</span>
            </a>
        @endif
    @endforeach
    @unless($panel)
        <button type="button" id="cobros-more" class="cobros-nav-item" aria-haspopup="dialog" aria-controls="cobros-more-panel" aria-expanded="false">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
            <span>Más</span>
        </button>
    @endunless
</nav>
