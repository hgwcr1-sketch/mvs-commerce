<?php

namespace App\Services\Modules;

class ModuleRegistry
{
    public const MODULES = [
        'sales' => ['label' => 'Ventas y POS', 'prefixes' => ['pos.', 'ventas.', 'cotizaciones.', 'pedidos.', 'apartados.', 'devoluciones.', 'notas_credito.']],
        'inventory' => ['label' => 'Productos e inventario', 'prefixes' => ['productos.', 'categorias.', 'marcas.', 'unidades.', 'inventario.']],
        'purchases' => ['label' => 'Compras y proveedores', 'prefixes' => ['compras.', 'proveedores.', 'cuentas_pagar.']],
        'customers' => ['label' => 'Clientes y CxC', 'prefixes' => ['clientes.', 'cuentas_cobrar.']],
        'cash' => ['label' => 'Caja', 'prefixes' => ['caja.']],
        'loyalty' => ['label' => 'FidelizaciÃ³n', 'prefixes' => ['fidelidad.']],
        'reports' => ['label' => 'Centro de Datos y reportes', 'prefixes' => ['reportes.']],
        'agenda' => ['label' => 'Agenda', 'prefixes' => ['agenda.']],
        'administration' => ['label' => 'AdministraciÃ³n', 'prefixes' => ['dashboard.', 'usuarios.', 'roles.', 'empresa.', 'configuracion.', 'formas_pago.']],
        'notifications' => ['label' => 'Notificaciones y Alertas', 'prefixes' => ['notificaciones.']],
        'routeos' => ['label' => 'RouteOS', 'prefixes' => ['routeos.']],
        'cobros' => ['label' => 'MVS CobrOS', 'prefixes' => ['cobros.']],
    ];

    public function forPermission(string $permission): ?string
    {
        foreach (self::MODULES as $key => $definition) {
            foreach ($definition['prefixes'] as $prefix) {
                if (str_starts_with($permission, $prefix)) {
                    return $key;
                }
            }
        }

        return null;
    }
}
