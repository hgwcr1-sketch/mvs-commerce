<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * R01 MVS RouteOS — Pantalla base del módulo.
 *
 * Punto de entrada visible solo para usuarios con routeos.acceder.
 * Los portales (Rutas, Pedidos, Facturación, Cobros, Liquidación) se
 * implementarán en roads posteriores; aquí se presentan como próximos
 * pasos sin simular funcionalidad inexistente.
 */
class RouteosController extends Controller
{
    public function index(Request $request): View
    {
        $company = Company::query()->find(session('active_company_id'));
        $user = $request->user();

        $modules = [
            [
                'label' => 'Rutas',
                'description' => 'Mi ruta de hoy, clientes asignados y visitas.',
                'ready' => false,
                'enabled' => $user->hasPermission('routeos.rutas.ver', $company),
            ],
            [
                'label' => 'Pedidos',
                'description' => 'Crear pedidos desde la ruta y cola de bodega.',
                'ready' => false,
                'enabled' => $user->hasPermission('routeos.pedidos.crear', $company)
                    || $user->hasPermission('routeos.pedidos.bodega', $company),
            ],
            [
                'label' => 'Facturación',
                'description' => 'Revisar y facturar pedidos preparados.',
                'ready' => false,
                'enabled' => $user->hasPermission('routeos.pedidos.facturar', $company),
            ],
            [
                'label' => 'Cobros',
                'description' => 'Abonos de cuentas por cobrar desde carretera.',
                'ready' => false,
                'enabled' => $user->hasPermission('routeos.cobros', $company),
            ],
            [
                'label' => 'Liquidación',
                'description' => 'Cierre diario del agente: ventas, cobros y efectivo.',
                'ready' => false,
                'enabled' => $user->hasPermission('routeos.supervisar', $company),
            ],
            [
                'label' => 'Configuración',
                'description' => 'Parámetros de RouteOS de la empresa.',
                'ready' => false,
                'enabled' => $user->hasPermission('routeos.configuracion', $company),
            ],
        ];

        return view('routeos.index', [
            'company' => $company,
            'modules' => $modules,
        ]);
    }
}
