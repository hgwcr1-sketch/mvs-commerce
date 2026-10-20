@php
    // Fuente unica CobrOS: gestion barra=slot bar; cobrador puro barra=quicknav 4+Mas.
    $navigationItems = [
        ['permission' => 'cobros.portal_dueno', 'route' => 'cobros.owner.dashboard', 'label' => 'Inicio / Cartera', 'slot' => 'bar', 'icon' => 'M3 10 12 3l9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z'],
        ['permission' => 'cobros.portal_dueno', 'route' => 'cobros.owner.collectors', 'label' => 'Cobradores', 'slot' => 'bar', 'icon' => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M23 21v-2a4 4 0 0 0-3-3.87 M16 3.13a4 4 0 0 1 0 7.75'],
        ['permission' => 'cobros.asignar', 'route' => 'cobros.owner.assignments', 'label' => 'Asignar cartera', 'slot' => 'bar', 'icon' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M16 3a4 4 0 0 1 0 8'],
        ['permission' => 'cobros.portal_dueno', 'route' => 'cobros.owner.customers', 'label' => 'Clientes', 'slot' => 'bar', 'icon' => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2 M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8'],
        ['permission' => 'cobros.portal_dueno', 'route' => 'cobros.owner.solicitudes.index', 'label' => 'Solicitudes', 'slot' => 'bar', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z'],
        ['permission' => 'cobros.supervisar', 'route' => 'cobros.supervisor.index', 'label' => 'Supervisión', 'slot' => 'bar', 'icon' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M22 21v-2a4 4 0 0 0-3-3.87 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M16 3a4 4 0 0 1 0 8'],
        ['permission' => 'cobros.portal_dueno', 'route' => 'cobros.owner.audit', 'label' => 'Auditoría', 'slot' => 'panel', 'icon' => 'M12 3l8 4v6c0 5-3.5 7.5-8 8-4.5-.5-8-3-8-8V7Z M9 12l2 2 4-4'],
        ['permission' => 'cobros.portal_dueno', 'route' => 'cobros.owner.reports', 'label' => 'Resultados', 'slot' => 'panel', 'icon' => 'M4 19V9 M10 19V5 M16 19v-7 M22 19H2'],
        ['permission' => 'cobros.portal_dueno', 'route' => 'cobros.owner.alerts', 'label' => 'Alertas', 'slot' => 'panel', 'icon' => 'M12 3a6 6 0 0 0-6 6c0 4-2 5-2 5h16s-2-1-2-5a6 6 0 0 0-6-6Z M10 19a2 2 0 0 0 4 0'],
        ['permission' => 'cobros.supervisar', 'route' => 'cobros.supervisor.alerts', 'label' => 'Alertas', 'slot' => 'panel', 'alternativeOf' => 'cobros.owner.alerts', 'icon' => 'M12 3a6 6 0 0 0-6 6c0 4-2 5-2 5h16s-2-1-2-5a6 6 0 0 0-6-6Z M10 19a2 2 0 0 0 4 0'],
        ['permission' => 'cobros.supervisar', 'route' => 'cobros.supervisor.audit', 'label' => 'Auditoría', 'slot' => 'panel', 'alternativeOf' => 'cobros.owner.audit', 'icon' => 'M12 3l8 4v6c0 5-3.5 7.5-8 8-4.5-.5-8-3-8-8V7Z M9 12l2 2 4-4'],
        ['permission' => 'cobros.cobrar', 'route' => 'cobros.visits.today', 'label' => 'Agenda hoy', 'slot' => 'collector', 'icon' => 'M8 2v4M16 2v4M3 9h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z'],
        ['permission' => 'cobros.cobrar', 'route' => 'cobros.collector.index', 'label' => 'Mi cartera', 'slot' => 'collector', 'icon' => 'M3 5h16v4H3z M3 9v11h18V9H3 M16 13h5v4h-5z'],
        ['permission' => 'cobros.cobrar', 'route' => 'cobros.collector.my-customers', 'label' => 'Mis clientes', 'slot' => 'collector', 'icon' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M16 3a4 4 0 0 1 0 7.75'],
        ['permission' => 'cobros.cobrar', 'route' => 'cobros.collector.payments.history', 'label' => 'Mis recibos', 'slot' => 'collector', 'icon' => 'M6 3h12v18l-3-2-3 2-3-2-3 2Z M9 8h6 M9 12h6'],
        ['permission' => 'cobros.cobrar', 'route' => 'cobros.collector.credit-requests.index', 'label' => 'Mis solicitudes', 'slot' => 'collector', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z'],
        ['permission' => 'cobros.cobrar', 'route' => 'cobros.collector.credits.index', 'label' => 'Mis préstamos', 'slot' => 'collector', 'icon' => 'M12 1v22 M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6'],
        ['permission' => 'cobros.cobrar', 'route' => 'cobros.closing.show', 'label' => 'Cierre del día', 'slot' => 'collector', 'icon' => 'M4 4h16v6H4zM4 14h16v6H4zM8 7h.01M8 17h.01'],
    ];

    $navigationItems[] = ['permission' => 'cobros.portal_dueno', 'route' => 'cobros.owner.routes', 'label' => 'Rutas', 'slot' => 'panel', 'icon' => 'M5 5h14v14H5z M5 12h14 M12 5v14'];

    $navigationUser = auth()->user();

    // Mi membresía: SOLO para quien paga, identificado por el campo
    // payer_user_id del contrato ABIERTO de la empresa activa (dato
    // persistido de MVS Billing, nunca por rol). El cobrador cuya licencia
    // paga el prestamista no aparece: su user_id no es payer_user_id.
    $navigationMembershipContract = \App\Models\CobrosBillingContract::query()
        ->where('company_id', $company->id)
        ->whereIn('status', \App\Models\CobrosBillingContract::OPEN_STATUSES)
        ->where('payer_user_id', $navigationUser->id)
        ->orderByDesc('id')
        ->first();

    if ($navigationMembershipContract !== null) {
        $navigationItems[] = [
            'permission' => 'cobros.acceder',
            'route' => 'cobros.payment.methods',
            'url' => route('cobros.payment.methods', $navigationMembershipContract),
            'label' => 'Mi membresía',
            // Gestores (portal del dueño) van al panel "Más"; cobradores al
            // mismo panel de su barra, sin duplicar en la barra inferior.
            'slot' => $navigationUser->hasPermission('cobros.portal_dueno', $company) ? 'panel' : 'collector',
            'icon' => 'M12 3l2.5 5 5.5.8-4 3.9.9 5.5L12 16.5 7.1 18.2l.9-5.5-4-3.9L9.5 8z',
        ];
    }

    // Cambiar ruta: solo visible cuando el cobrador tiene varias rutas disponibles.
    if ($navigationUser->hasPermission('cobros.cobrar', $company)
        && app(\App\Services\Cobros\CobrosRouteContext::class)->available($navigationUser)->count() > 1) {
        $navigationItems[] = ['permission' => 'cobros.cobrar', 'route' => 'cobros.routes.select', 'label' => 'Cambiar ruta', 'slot' => 'collector', 'icon' => 'M4 12h14 M13 6l6 6-6 6 M20 4v16'];
    }

    // BLOQUE 21A-2 — Academia MVS CobrOS: capacitación por modalidad.
    // Última del listado para no desplazar accesos operativos de la barra del
    // cobrador; en gestión aparece en el panel "Más" (slot distinto de 'bar').
    $navigationItems[] = [
        'permission' => 'cobros.acceder',
        'route' => 'cobros.academia.index',
        'label' => 'Academia',
        'slot' => 'collector',
        'icon' => 'M21.42 10.92a1 1 0 0 0-.02-1.84L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.84l8.57 3.9a2 2 0 0 0 1.66 0z M22 10v6 M6 12.5V16a6 3 0 0 0 12 0v-3.5',
    ];

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

    // Gestion: barra minima slot bar. Cobrador puro: barra 4 quicknav + Mas.
    $navigationHasManagement = count(array_filter(
        $navigationAllowed,
        fn (array $item) => $item['slot'] === 'bar'
    )) > 0;

    // Barra inferior: gestión = solo 'bar'; cobrador puro = sus 4 accesos
    // rápidos configurables. Panel "Más": lo demás autorizado, nunca lo que
    // ya está en la barra (sin duplicados barra/Más).
    $navigationBarItems = [];
    $navigationPanelItems = [];
    if ($navigationHasManagement) {
        $navigationBarItems = array_values(array_filter($navigationAllowed, fn (array $x) => $x['slot'] === 'bar'));
        $navigationPanelItems = array_values(array_filter($navigationAllowed, fn (array $x) => $x['slot'] !== 'bar'));
    } else {
        $navigationCollectorItems = array_values(array_filter($navigationAllowed, fn (array $x) => $x['slot'] === 'collector'));
        $navigationQuickRoutes = [];
        try {
            $navigationQuickRoutes = app(\App\Services\Cobros\CobrosQuickNavService::class)
                ->barRoutes($navigationUser, array_column($navigationCollectorItems, 'route'));
        } catch (\Throwable $e) {
            $navigationQuickRoutes = [];
        }
        if ($navigationQuickRoutes === []) {
            $navigationQuickRoutes = array_slice(array_column($navigationCollectorItems, 'route'), 0, 4);
        }
        $navigationByRoute = [];
        foreach ($navigationCollectorItems as $navigationCollectorItem) {
            $navigationByRoute[$navigationCollectorItem['route']] = $navigationCollectorItem;
        }
        $navigationBarItems = array_values(array_filter(array_map(fn (string $r) => $navigationByRoute[$r] ?? null, $navigationQuickRoutes)));
        $navigationPanelItems = array_values(array_filter(
            $navigationCollectorItems,
            fn (array $x) => ! in_array($x['route'], $navigationQuickRoutes, true)
        ));
    }
    $navigationItems[] = ['permission' => 'cobros.acceder', 'route' => 'cobros.referrals.index', 'label' => 'Mis referidos', 'slot' => 'collector', 'icon' => 'M12 20.5S4 16 4 9.5A4.5 4.5 0 0 1 12 7a4.5 4.5 0 0 1 8 2.5C20 16 12 20.5 12 20.5Z'];
    $navigationVisible = $panel ? $navigationPanelItems : $navigationBarItems;
@endphp
<nav class="{{ $panel ? 'cobros-panel-navigation' : 'cobros-navigation' }}" aria-label="{{ $panel ? 'Menú completo CobrOS' : 'Navegación CobrOS' }}">
    @foreach($navigationVisible as $item)
        <a href="{{ $item['url'] ?? route($item['route']) }}" class="cobros-nav-item" title="{{ $item['label'] }}"
           @if(request()->routeIs($item['route'])) aria-current="page" @endif>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $item['icon'] }}" /></svg>
            <span>{{ $item['label'] }}</span>
        </a>
    @endforeach
    @if($panel && ! $navigationHasManagement)
        <a href="{{ route('cobros.quick-nav.edit') }}" class="cobros-nav-item" title="Personalizar accesos rápidos"
           @if(request()->routeIs('cobros.quick-nav.edit')) aria-current="page" @endif>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6" /></svg>
            <span>Personalizar accesos rápidos</span>
        </a>
    @endif
    @unless($panel)
        <button type="button" id="cobros-more" class="cobros-nav-item" aria-haspopup="dialog" aria-controls="cobros-more-panel" aria-expanded="false">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
            <span>Más</span>
        </button>
    @endunless
</nav>
