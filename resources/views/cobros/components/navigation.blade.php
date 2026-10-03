@php
    /*
     * Fuente única de opciones autorizadas del shell CobrOS.
     * Cada opción se renderiza UNA sola vez: la barra inferior recibe las
     * opciones primarias del perfil y el panel "Más" recibe el resto.
     * `alternativeOf` marca destinos equivalentes (mismo controlador): si el
     * perfil también alcanza el portal del dueño, la variante de supervisión
     * no se muestra dos veces.
     */
    $navigationItems = [
        ['permission' => 'cobros.portal_dueno', 'route' => 'cobros.owner.dashboard', 'label' => 'Inicio / Cartera', 'slot' => 'bar', 'icon' => 'M3 10 12 3l9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z'],
        ['permission' => 'cobros.portal_dueno', 'route' => 'cobros.owner.collectors', 'label' => 'Cobradores', 'slot' => 'bar', 'icon' => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M23 21v-2a4 4 0 0 0-3-3.87 M16 3.13a4 4 0 0 1 0 7.75'],
        ['permission' => 'cobros.asignar', 'route' => 'cobros.owner.assignments', 'label' => 'Asignar cartera', 'slot' => 'bar', 'icon' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M16 3a4 4 0 0 1 0 8'],
        ['permission' => 'cobros.supervisar', 'route' => 'cobros.supervisor.index', 'label' => 'Supervisión', 'slot' => 'bar', 'icon' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M22 21v-2a4 4 0 0 0-3-3.87 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M16 3a4 4 0 0 1 0 8'],
        ['permission' => 'cobros.portal_dueno', 'route' => 'cobros.owner.audit', 'label' => 'Auditoría', 'slot' => 'panel', 'icon' => 'M12 3l8 4v6c0 5-3.5 7.5-8 8-4.5-.5-8-3-8-8V7Z M9 12l2 2 4-4'],
        ['permission' => 'cobros.portal_dueno', 'route' => 'cobros.owner.reports', 'label' => 'Resultados', 'slot' => 'panel', 'icon' => 'M4 19V9 M10 19V5 M16 19v-7 M22 19H2'],
        ['permission' => 'cobros.portal_dueno', 'route' => 'cobros.owner.alerts', 'label' => 'Alertas', 'slot' => 'panel', 'icon' => 'M12 3a6 6 0 0 0-6 6c0 4-2 5-2 5h16s-2-1-2-5a6 6 0 0 0-6-6Z M10 19a2 2 0 0 0 4 0'],
        ['permission' => 'cobros.supervisar', 'route' => 'cobros.supervisor.alerts', 'label' => 'Alertas', 'slot' => 'panel', 'alternativeOf' => 'cobros.owner.alerts', 'icon' => 'M12 3a6 6 0 0 0-6 6c0 4-2 5-2 5h16s-2-1-2-5a6 6 0 0 0-6-6Z M10 19a2 2 0 0 0 4 0'],
        ['permission' => 'cobros.supervisar', 'route' => 'cobros.supervisor.audit', 'label' => 'Auditoría', 'slot' => 'panel', 'alternativeOf' => 'cobros.owner.audit', 'icon' => 'M12 3l8 4v6c0 5-3.5 7.5-8 8-4.5-.5-8-3-8-8V7Z M9 12l2 2 4-4'],
        ['permission' => 'cobros.cobrar', 'route' => 'cobros.visits.today', 'label' => 'Agenda hoy', 'slot' => 'collector', 'icon' => 'M8 2v4M16 2v4M3 9h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z'],
        ['permission' => 'cobros.cobrar', 'route' => 'cobros.collector.index', 'label' => 'Mi cartera', 'slot' => 'collector', 'icon' => 'M3 5h16v4H3z M3 9v11h18V9H3 M16 13h5v4h-5z'],
        ['permission' => 'cobros.cobrar', 'route' => 'cobros.collector.payments.history', 'label' => 'Mis recibos', 'slot' => 'collector', 'icon' => 'M6 3h12v18l-3-2-3 2-3-2-3 2Z M9 8h6 M9 12h6'],
        ['permission' => 'cobros.cobrar', 'route' => 'cobros.closing.show', 'label' => 'Cierre del día', 'slot' => 'collector', 'icon' => 'M4 4h16v6H4zM4 14h16v6H4zM8 7h.01M8 17h.01'],
    ];

    $navigationUser = auth()->user();
    $navigationAllowed = array_values(array_filter(
        $navigationItems,
        fn (array $item) => $navigationUser->hasPermission($item['permission'], $company)
    ));

    $navigationAllowedRoutes = array_column($navigationAllowed, 'route');
    $navigationAllowed = array_values(array_filter(
        $navigationAllowed,
        fn (array $item) => !isset($item['alternativeOf'])
            || !in_array($item['alternativeOf'], $navigationAllowedRoutes, true)
    ));

    // Perfiles de gestión (dueño/supervisor/asignación): la barra queda mínima y
    // el resto vive solo en "Más". Perfil exclusivo de cobro: la interfaz mínima
    // del cobrador permanece entera en la barra.
    $navigationHasManagement = count(array_filter(
        $navigationAllowed,
        fn (array $item) => $item['slot'] !== 'collector'
    )) > 0;

    $navigationVisible = array_values(array_filter(
        $navigationAllowed,
        fn (array $item) => $panel
            ? ($navigationHasManagement ? $item['slot'] !== 'bar' : false)
            : ($navigationHasManagement ? $item['slot'] === 'bar' : true)
    ));
@endphp
<nav class="{{ $panel ? 'cobros-panel-navigation' : 'cobros-navigation' }}" aria-label="{{ $panel ? 'Menú completo CobrOS' : 'Navegación CobrOS' }}">
    @foreach($navigationVisible as $item)
        <a href="{{ route($item['route']) }}" class="cobros-nav-item" title="{{ $item['label'] }}"
           @if(request()->routeIs($item['route'])) aria-current="page" @endif>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $item['icon'] }}" /></svg>
            <span>{{ $item['label'] }}</span>
        </a>
    @endforeach
    @unless($panel)
        <button type="button" id="cobros-more" class="cobros-nav-item" aria-haspopup="dialog" aria-controls="cobros-more-panel" aria-expanded="false">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
            <span>Más</span>
        </button>
    @endunless
</nav>
