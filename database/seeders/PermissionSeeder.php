<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Catálogo oficial de permisos de MVS Commerce.
     */
    public function run(): void
    {
        $permissions = [

            // Dashboard
            ['name' => 'dashboard.ver', 'label' => 'Ver dashboard', 'module' => 'Dashboard'],

            // POS
            ['name' => 'pos.acceder', 'label' => 'Acceder al POS', 'module' => 'POS'],

            // Clientes
            ['name' => 'clientes.ver', 'label' => 'Ver clientes', 'module' => 'Clientes'],
            ['name' => 'clientes.crear', 'label' => 'Crear clientes', 'module' => 'Clientes'],
            ['name' => 'clientes.editar', 'label' => 'Editar clientes', 'module' => 'Clientes'],
            ['name' => 'clientes.eliminar', 'label' => 'Eliminar clientes', 'module' => 'Clientes'],

            // Productos
            ['name' => 'productos.ver', 'label' => 'Ver productos', 'module' => 'Productos'],
            ['name' => 'productos.crear', 'label' => 'Crear productos', 'module' => 'Productos'],
            ['name' => 'productos.editar', 'label' => 'Editar productos', 'module' => 'Productos'],
            ['name' => 'productos.eliminar', 'label' => 'Eliminar productos', 'module' => 'Productos'],
            // Categorías
['name' => 'categorias.ver', 'label' => 'Ver categorías', 'module' => 'Productos'],
['name' => 'categorias.crear', 'label' => 'Crear categorías', 'module' => 'Productos'],
['name' => 'categorias.editar', 'label' => 'Editar categorías', 'module' => 'Productos'],
['name' => 'categorias.eliminar', 'label' => 'Eliminar categorías', 'module' => 'Productos'],

// Marcas
['name' => 'marcas.ver', 'label' => 'Ver marcas', 'module' => 'Productos'],
['name' => 'marcas.crear', 'label' => 'Crear marcas', 'module' => 'Productos'],
['name' => 'marcas.editar', 'label' => 'Editar marcas', 'module' => 'Productos'],
['name' => 'marcas.eliminar', 'label' => 'Eliminar marcas', 'module' => 'Productos'],

// Unidades
['name' => 'unidades.ver', 'label' => 'Ver unidades de medida', 'module' => 'Productos'],
['name' => 'unidades.crear', 'label' => 'Crear unidades de medida', 'module' => 'Productos'],
['name' => 'unidades.editar', 'label' => 'Editar unidades de medida', 'module' => 'Productos'],
['name' => 'unidades.eliminar', 'label' => 'Eliminar unidades de medida', 'module' => 'Productos'],

            // Proveedores
            ['name' => 'proveedores.ver', 'label' => 'Ver proveedores', 'module' => 'Proveedores'],
            ['name' => 'proveedores.crear', 'label' => 'Crear proveedores', 'module' => 'Proveedores'],
            ['name' => 'proveedores.editar', 'label' => 'Editar proveedores', 'module' => 'Proveedores'],
            ['name' => 'proveedores.eliminar', 'label' => 'Eliminar proveedores', 'module' => 'Proveedores'],

            // Inventario
            ['name' => 'inventario.ver', 'label' => 'Ver inventario', 'module' => 'Inventario'],
            ['name' => 'inventario.ver_otras_sucursales', 'label' => 'Ver inventario de otras sucursales', 'module' => 'Inventario'],
            ['name' => 'inventario.ajustar', 'label' => 'Realizar ajustes de inventario', 'module' => 'Inventario'],
            ['name' => 'inventario.kardex', 'label' => 'Ver Kardex', 'module' => 'Inventario'],
            ['name' => 'inventario.transferir', 'label' => 'Realizar transferencias', 'module' => 'Inventario'],

            // Compras
            ['name' => 'compras.ver', 'label' => 'Ver compras', 'module' => 'Compras'],
            ['name' => 'compras.crear', 'label' => 'Registrar compras', 'module' => 'Compras'],
            ['name' => 'compras.editar', 'label' => 'Editar compras', 'module' => 'Compras'],
            ['name' => 'compras.anular', 'label' => 'Anular compras', 'module' => 'Compras'],
            ['name' => 'compras.ordenes', 'label' => 'Administrar órdenes de compra', 'module' => 'Compras'],

            // Ventas
            ['name' => 'ventas.ver', 'label' => 'Ver ventas', 'module' => 'Ventas'],
            ['name' => 'ventas.crear', 'label' => 'Realizar ventas', 'module' => 'Ventas'],
            ['name' => 'ventas.editar', 'label' => 'Editar ventas', 'module' => 'Ventas'],
            ['name' => 'ventas.anular', 'label' => 'Anular ventas', 'module' => 'Ventas'],

            // Cotizaciones
            ['name' => 'cotizaciones.ver', 'label' => 'Ver cotizaciones', 'module' => 'Cotizaciones'],
            ['name' => 'cotizaciones.crear', 'label' => 'Crear cotizaciones', 'module' => 'Cotizaciones'],
            ['name' => 'cotizaciones.editar', 'label' => 'Editar cotizaciones', 'module' => 'Cotizaciones'],
            ['name' => 'cotizaciones.eliminar', 'label' => 'Eliminar cotizaciones', 'module' => 'Cotizaciones'],

            // Facturación
            ['name' => 'facturacion.ver', 'label' => 'Ver facturas', 'module' => 'Facturación'],
            ['name' => 'facturacion.crear', 'label' => 'Emitir facturas', 'module' => 'Facturación'],
            ['name' => 'facturacion.anular', 'label' => 'Anular facturas', 'module' => 'Facturación'],
            ['name' => 'facturacion.reenviar', 'label' => 'Reenviar facturas', 'module' => 'Facturación'],

            // Apartados
            ['name' => 'apartados.ver', 'label' => 'Ver apartados', 'module' => 'Apartados'],
            ['name' => 'apartados.crear', 'label' => 'Crear apartados', 'module' => 'Apartados'],
            ['name' => 'apartados.abonar', 'label' => 'Registrar abonos', 'module' => 'Apartados'],
            ['name' => 'apartados.cancelar', 'label' => 'Cancelar apartados', 'module' => 'Apartados'],

            // Devoluciones
            ['name' => 'devoluciones.ver', 'label' => 'Ver devoluciones', 'module' => 'Devoluciones'],
            ['name' => 'devoluciones.crear', 'label' => 'Registrar devoluciones', 'module' => 'Devoluciones'],
            ['name' => 'devoluciones.aprobar', 'label' => 'Aprobar devoluciones', 'module' => 'Devoluciones'],

            // Cuentas por cobrar
            ['name' => 'cuentas_cobrar.ver', 'label' => 'Ver cuentas por cobrar', 'module' => 'Cuentas por Cobrar'],
            ['name' => 'cuentas_cobrar.abonar', 'label' => 'Registrar abonos', 'module' => 'Cuentas por Cobrar'],
            ['name' => 'cuentas_cobrar.editar', 'label' => 'Editar cuentas por cobrar', 'module' => 'Cuentas por Cobrar'],

            // Cuentas por pagar
            ['name' => 'cuentas_pagar.ver', 'label' => 'Ver cuentas por pagar', 'module' => 'Cuentas por Pagar'],
            ['name' => 'cuentas_pagar.pagar', 'label' => 'Registrar pagos', 'module' => 'Cuentas por Pagar'],
            ['name' => 'cuentas_pagar.editar', 'label' => 'Editar cuentas por pagar', 'module' => 'Cuentas por Pagar'],

            // Usuarios
            ['name' => 'usuarios.ver', 'label' => 'Ver usuarios', 'module' => 'Usuarios'],
            ['name' => 'usuarios.crear', 'label' => 'Crear usuarios', 'module' => 'Usuarios'],
            ['name' => 'usuarios.editar', 'label' => 'Editar usuarios', 'module' => 'Usuarios'],
            ['name' => 'usuarios.desactivar', 'label' => 'Activar o desactivar usuarios', 'module' => 'Usuarios'],
            ['name' => 'usuarios.eliminar', 'label' => 'Retirar usuarios de la empresa', 'module' => 'Usuarios'],

            // Roles y permisos
            ['name' => 'roles.ver', 'label' => 'Ver roles', 'module' => 'Roles y Permisos'],
            ['name' => 'roles.crear', 'label' => 'Crear roles', 'module' => 'Roles y Permisos'],
            ['name' => 'roles.editar', 'label' => 'Editar roles', 'module' => 'Roles y Permisos'],
            ['name' => 'roles.eliminar', 'label' => 'Eliminar roles', 'module' => 'Roles y Permisos'],
            ['name' => 'roles.permisos', 'label' => 'Asignar permisos a roles', 'module' => 'Roles y Permisos'],

            // Empresa
            ['name' => 'empresa.ver', 'label' => 'Ver información de empresa', 'module' => 'Empresa'],
            ['name' => 'empresa.editar', 'label' => 'Editar información de empresa', 'module' => 'Empresa'],

            // Configuración
            ['name' => 'configuracion.ver', 'label' => 'Ver configuración', 'module' => 'Configuración'],
            ['name' => 'configuracion.editar', 'label' => 'Modificar configuración', 'module' => 'Configuración'],
            ['name' => 'formas_pago.administrar', 'label' => 'Administrar formas de pago', 'module' => 'Configuración'],

            // Agenda
            ['name' => 'agenda.ver', 'label' => 'Ver agenda', 'module' => 'Agenda'],
            ['name' => 'agenda.crear', 'label' => 'Crear citas', 'module' => 'Agenda'],
            ['name' => 'agenda.editar', 'label' => 'Editar citas', 'module' => 'Agenda'],
            ['name' => 'agenda.eliminar', 'label' => 'Eliminar citas', 'module' => 'Agenda'],

            // Reportes
            ['name' => 'reportes.ver', 'label' => 'Ver reportes', 'module' => 'Reportes'],
            ['name' => 'reportes.exportar', 'label' => 'Exportar reportes', 'module' => 'Reportes'],
        ];

        foreach ($permissions as $permission) {

            Permission::updateOrCreate(
                ['name' => $permission['name']],
                [
                    'label' => $permission['label'],
                    'module' => $permission['module'],
                    'description' => null,
                    'is_active' => true,
                ]
            );
        }

        $administratorPermissionIds = Permission::query()
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        \App\Models\Role::query()
            ->where('name', 'Administrador')
            ->where('is_active', true)
            ->each(function (\App\Models\Role $role) use ($administratorPermissionIds) {
                $role->permissions()->syncWithoutDetaching(
                    $administratorPermissionIds
                );
            });
    }
}
