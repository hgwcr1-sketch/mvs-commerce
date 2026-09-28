<?php

return [

    'company_name' => env('DEMO_COMPANY_NAME', 'MVS Commerce Demo'),

    'owner_name' => env('DEMO_OWNER_NAME', 'Administrador Demo'),

    'owner_email' => env('DEMO_OWNER_EMAIL', 'demo@mvscommerce.com'),

    'owner_password' => env('DEMO_OWNER_PASSWORD', 'Demo123*'),

    'branches' => [
        ['name' => 'Demo San José', 'code' => 'DSJ'],
        ['name' => 'Demo Liberia', 'code' => 'DLB'],
    ],

    'users' => [
        [
            'name' => 'Administrador Demo',
            'email' => 'demo@mvscommerce.com',
            'password' => 'Demo123*',
            'role' => 'Administrador',
            'permissions' => 'all',
        ],
        [
            'name' => 'Vendedor Demo',
            'email' => 'vendedor.demo@mvscommerce.com',
            'password' => 'Demo123*',
            'role' => 'Vendedor',
            'permissions' => [
                'dashboard.ver', 'pos.acceder', 'pos.aplicar_descuento',
                'clientes.ver', 'clientes.crear', 'clientes.editar',
                'productos.ver', 'ventas.ver', 'ventas.crear',
                'fidelidad.ver', 'fidelidad.oportunidades', 'fidelidad.clientes',
            ],
        ],
        [
            'name' => 'Cajero Demo',
            'email' => 'cajero.demo@mvscommerce.com',
            'password' => 'Demo123*',
            'role' => 'Cajero',
            'permissions' => [
                'dashboard.ver', 'pos.acceder', 'caja.abrir', 'caja.ver', 'caja.movimientos', 'caja.cerrar',
                'clientes.ver', 'clientes.crear',
                'productos.ver', 'ventas.ver', 'ventas.crear',
            ],
        ],
        [
            'name' => 'Bodeguero Demo',
            'email' => 'bodeguero.demo@mvscommerce.com',
            'password' => 'Demo123*',
            'role' => 'Bodeguero',
            'permissions' => [
                'dashboard.ver', 'inventario.ver', 'inventario.ver_otras_sucursales',
                'inventario.ajustar', 'inventario.kardex', 'inventario.conteo.ver',
                'productos.ver', 'productos.crear', 'productos.editar',
                'categorias.ver', 'marcas.ver', 'proveedores.ver',
            ],
        ],
    ],

    'assets_source' => env('DEMO_ASSETS_SOURCE', public_path('demo-assets')),

    'assets_runtime' => env('DEMO_ASSETS_RUNTIME', storage_path('app/public/demo')),

];
