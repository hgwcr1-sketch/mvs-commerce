<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
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
            ['name' => 'dashboard.admin', 'label' => 'Administrar dashboard', 'module' => 'Dashboard'],

            // POS
            ['name' => 'pos.acceder', 'label' => 'Acceder al POS', 'module' => 'POS'],
            ['name' => 'pos.aplicar_descuento', 'label' => 'Aplicar descuentos en el POS', 'module' => 'POS'],
            ['name' => 'pos.cambiar_precio', 'label' => 'Cambiar precio en el POS', 'module' => 'POS'],

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
            ['name' => 'productos.etiquetas.imprimir', 'label' => 'Preparar e imprimir etiquetas', 'module' => 'Productos'],
            ['name' => 'productos.etiquetas.configurar', 'label' => 'Configurar etiquetas por sucursal', 'module' => 'Productos'],
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
            ['name' => 'inventario.conteo.ver', 'label' => 'Ver tomas de inventario', 'module' => 'Inventario'],
            ['name' => 'inventario.conteo.iniciar', 'label' => 'Iniciar tomas de inventario', 'module' => 'Inventario'],
            ['name' => 'inventario.conteo.contar', 'label' => 'Contar en tomas de inventario', 'module' => 'Inventario'],
            ['name' => 'inventario.conteo.revisar', 'label' => 'Revisar tomas de inventario', 'module' => 'Inventario'],
            ['name' => 'inventario.conteo.confirmar', 'label' => 'Confirmar tomas de inventario', 'module' => 'Inventario'],
            ['name' => 'inventario.conteo.cancelar', 'label' => 'Cancelar tomas de inventario', 'module' => 'Inventario'],
            ['name' => 'inventario.kardex', 'label' => 'Ver Kardex', 'module' => 'Inventario'],
            ['name' => 'inventario.transferir', 'label' => 'Realizar transferencias', 'module' => 'Inventario'],

            // Compras
            ['name' => 'compras.ver', 'label' => 'Ver compras', 'module' => 'Compras'],
            ['name' => 'compras.crear', 'label' => 'Registrar compras', 'module' => 'Compras'],
            ['name' => 'compras.editar', 'label' => 'Editar compras', 'module' => 'Compras'],
            ['name' => 'compras.anular', 'label' => 'Anular compras', 'module' => 'Compras'],
            ['name' => 'compras.ordenes', 'label' => 'Administrar órdenes de compra', 'module' => 'Compras'],
            ['name' => 'compras.recepcion.asignar', 'label' => 'Asignar verificación de mercadería', 'module' => 'Compras'],
            ['name' => 'compras.recepcion.verificar', 'label' => 'Verificar recepción de mercadería', 'module' => 'Compras'],
            ['name' => 'compras.recepcion.resolver', 'label' => 'Resolver y cerrar verificaciones', 'module' => 'Compras'],

            // Ventas
            ['name' => 'ventas.ver', 'label' => 'Ver ventas', 'module' => 'Ventas'],
            ['name' => 'ventas.crear', 'label' => 'Realizar ventas', 'module' => 'Ventas'],
            ['name' => 'ventas.editar', 'label' => 'Editar ventas', 'module' => 'Ventas'],
            ['name' => 'ventas.anular', 'label' => 'Anular ventas', 'module' => 'Ventas'],

            // Pedidos
            ['name' => 'pedidos.ver', 'label' => 'Ver pedidos', 'module' => 'Pedidos'],
            ['name' => 'pedidos.crear', 'label' => 'Crear pedidos', 'module' => 'Pedidos'],
            ['name' => 'pedidos.aprobar', 'label' => 'Aprobar pedidos', 'module' => 'Pedidos'],
            ['name' => 'pedidos.rechazar', 'label' => 'Rechazar pedidos', 'module' => 'Pedidos'],
            ['name' => 'pedidos.cancelar', 'label' => 'Cancelar pedidos', 'module' => 'Pedidos'],
            ['name' => 'pedidos.preparar_compra', 'label' => 'Preparar pedidos a proveedor', 'module' => 'Pedidos'],

            // Caja
            ['name' => 'caja.abrir', 'label' => 'Abrir caja', 'module' => 'Caja'],
            ['name' => 'caja.ver', 'label' => 'Ver caja', 'module' => 'Caja'],
            ['name' => 'caja.movimientos', 'label' => 'Registrar movimientos de caja', 'module' => 'Caja'],
            ['name' => 'caja.cerrar', 'label' => 'Cerrar caja', 'module' => 'Caja'],
            ['name' => 'caja.ver_todas', 'label' => 'Ver todas las cajas', 'module' => 'Caja'],
            ['name' => 'caja.autorizar_diferencia', 'label' => 'Autorizar diferencias de caja', 'module' => 'Caja'],
            ['name' => 'caja.administrar', 'label' => 'Administrar cajas y configuración', 'module' => 'Caja'],

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
            ['name' => 'apartados.entregar', 'label' => 'Entregar apartados', 'module' => 'Apartados'],

            // Devoluciones
            ['name' => 'devoluciones.ver', 'label' => 'Ver devoluciones', 'module' => 'Devoluciones'],
            ['name' => 'devoluciones.crear', 'label' => 'Registrar devoluciones', 'module' => 'Devoluciones'],
            ['name' => 'devoluciones.aprobar', 'label' => 'Aprobar devoluciones', 'module' => 'Devoluciones'],

            // Notas de crédito
            ['name' => 'notas_credito.crear', 'label' => 'Crear notas de crédito', 'module' => 'Notas de Crédito'],
            ['name' => 'notas_credito.aplicar', 'label' => 'Aplicar notas de crédito', 'module' => 'Notas de Crédito'],
            ['name' => 'notas_credito.configurar', 'label' => 'Configurar vigencia de notas de crédito', 'module' => 'Notas de Crédito'],
            ['name' => 'notas_credito.regenerar_codigo', 'label' => 'Regenerar códigos de notas de crédito a consumidor final', 'module' => 'Notas de Crédito'],

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

            // Fidelidad
            ['name' => 'fidelidad.ver', 'label' => 'Ver Kardex de Fidelidad', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.dashboard', 'label' => 'Ver dashboard de Fidelidad', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.oportunidades', 'label' => 'Ver oportunidades de Fidelidad', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.clientes', 'label' => 'Ver clientes y puntos de Fidelidad', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.whatsapp', 'label' => 'Abrir WhatsApp asistido', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.contactar', 'label' => 'Registrar contacto de Fidelidad', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.configuracion', 'label' => 'Configurar comunicaciones de Fidelidad', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.multiplicadores', 'label' => 'Administrar multiplicadores de Fidelidad', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.premios', 'label' => 'Administrar premios de Fidelidad', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.canjes', 'label' => 'Registrar y consultar canjes de premios de Fidelidad', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.ajustes', 'label' => 'Ajustar manualmente puntos de Fidelidad', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.portal', 'label' => 'Administrar accesos al portal de Fidelidad', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.promociones', 'label' => 'Administrar promociones del portal de Fidelidad', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.portal.ver', 'label' => 'Ver y previsualizar Portal de Clientes', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.portal.configurar', 'label' => 'Configurar Portal de Clientes', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.portal.contenido', 'label' => 'Gestionar contenido del Portal de Clientes', 'module' => 'Fidelidad'],
            ['name' => 'fidelidad.portal.enlaces', 'label' => 'Gestionar enlaces del Portal de Clientes', 'module' => 'Fidelidad'],

            // MVS Print (impresión local por terminal)
            ['name' => 'mvs.print.configurar', 'label' => 'Configurar terminales de impresión local', 'module' => 'MVS Print'],
            ['name' => 'mvs.print.imprimir', 'label' => 'Imprimir por terminal local (QZ Tray)', 'module' => 'MVS Print'],

            // Notificaciones y Alertas MVS
            ['name' => 'notificaciones.ver', 'label' => 'Ver Centro de Notificaciones', 'module' => 'Notificaciones'],
            ['name' => 'notificaciones.inventario', 'label' => 'Recibir alertas de inventario', 'module' => 'Notificaciones'],
            ['name' => 'notificaciones.traslados', 'label' => 'Recibir alertas de traslados', 'module' => 'Notificaciones'],
            ['name' => 'notificaciones.compras', 'label' => 'Recibir alertas de compras', 'module' => 'Notificaciones'],
            ['name' => 'notificaciones.caja', 'label' => 'Recibir alertas de caja', 'module' => 'Notificaciones'],
            ['name' => 'notificaciones.apartados', 'label' => 'Recibir alertas de apartados', 'module' => 'Notificaciones'],
            ['name' => 'notificaciones.fidelidad', 'label' => 'Recibir alertas de fidelización', 'module' => 'Notificaciones'],
            ['name' => 'notificaciones.cxc', 'label' => 'Recibir alertas de cuentas por cobrar', 'module' => 'Notificaciones'],
            ['name' => 'notificaciones.cxp', 'label' => 'Recibir alertas de cuentas por pagar', 'module' => 'Notificaciones'],
            ['name' => 'notificaciones.administracion', 'label' => 'Recibir alertas de administración', 'module' => 'Notificaciones'],
            ['name' => 'notificaciones.configurar', 'label' => 'Configurar preferencias de notificación', 'module' => 'Notificaciones'],
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

        Permission::query()
            ->where('name', 'pedidos.confirmar')
            ->each(function (Permission $permission) {
                $permission->roles()->detach();
                $permission->delete();
            });

        $administratorPermissionIds = Permission::query()
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        // Sincronizar todos los permisos activos a los roles marcados como super admin.
        // Ya no depende del nombre "Administrador", sino del flag is_super_admin.
        Role::query()
            ->where('is_super_admin', true)
            ->where('is_active', true)
            ->each(function (Role $role) use ($administratorPermissionIds) {
                $role->permissions()->syncWithoutDetaching(
                    $administratorPermissionIds
                );
            });

        // Notas de crédito: asignación explícita a Administrador y Administrador Local
        $ncPermissionIds = Permission::query()
            ->where('is_active', true)
            ->where('name', 'like', 'notas_credito.%')
            ->pluck('id')
            ->all();

        Role::query()
            ->where(function ($query) {
                $query->where('name', 'Administrador')
                    ->orWhere('name', 'Administrador Local');
            })
            ->where('is_active', true)
            ->each(function (Role $role) use ($ncPermissionIds) {
                $role->permissions()->syncWithoutDetaching($ncPermissionIds);
            });

        // Notas de crédito: el rol Cajero únicamente puede aplicar notas de
        // crédito (incluidas las emitidas a consumidor final). No crea,
        // configura ni regenera; la emisión de devoluciones sigue su propio
        // permiso devoluciones.crear.
        $cashierApplyId = Permission::query()
            ->where('is_active', true)
            ->where('name', 'notas_credito.aplicar')
            ->value('id');

        Role::query()
            ->where('name', 'Cajero')
            ->where('is_active', true)
            ->each(function (Role $role) use ($cashierApplyId) {
                if ($cashierApplyId !== null) {
                    $role->permissions()->syncWithoutDetaching([$cashierApplyId]);
                }
            });
    }
}
