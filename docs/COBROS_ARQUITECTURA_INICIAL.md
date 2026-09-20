# MVS CobrOS — Arquitectura Inicial

Documento de decisiones aprobadas para el módulo CobrOS. Recopilado en la base
`feature/cobros-v1` creada a partir del commit `b8033e2` (R01 MVS RouteOS).

## 1. Identidad del producto

- CobrOS es una aplicación separada para el usuario, compartiendo servicios internos del monolito modular. Decisión definitiva de Fase 1A.1: sin enlaces entre la navegación de Commerce y CobrOS.
- V1 vivirá en el mismo monolito Laravel como módulo delimitado.
- Tendrá tres portales separados:
  - Portal Central MVS (administración de empresa/platforma).
  - Portal del Dueño (empresa propietaria de la ruta/licencia).
  - PWA del cobrador (agente en campo).

## 2. Modelo de datos fundacional

- `Customer` será la identidad única del cliente por empresa.
- `accounts_receivable` será la fuente única del saldo.
- CobrOS no escribirá libremente el saldo; utilizará un servicio financiero.
- Los pagos offline necesitarán una clave idempotente.
- Se necesitará una captura operacional explícita antes de aplicar el pago.
- Se necesitará una liquidación explícita antes de ingresar el efectivo a caja.

## 3. Relación con RouteOS

- CobrOS no dependerá de funciones de RuteOS todavía no implementadas.
- RuteOS y CobrOS podrán compartir geolocalización y auditoría.
- La base compartida incluye:
  - `customers.latitude`, `customers.longitude`, `customers.location_reference`,
    `customers.location_validated_at`, `customers.location_validated_by`.
  - `routeos_audit_logs` como bitácora reutilizable.
  - `routeos.credito.administrar` como permiso existente de crédito.

## 4. Licenciamiento

- Las licencias serán por ruta activa.
- Suspender una licencia no elimina datos.

## 5. Activación y aislamiento (Fase 1A)

- CobrOS se activa mediante el mecanismo existente de `CompanyModule`.
- El módulo usa la clave interna `cobros`, registrada en `ModuleRegistry`
  con nombre visible "MVS CobrOS" y su propia familia de permisos `cobros.*`.
- Los permisos de CobrOS son independientes de `routeos.*`.
- La entrada al portal usa middleware propio: `auth`, `active.company`,
  `company.licensed`, `company.module:cobros`, `permission:cobros.acceder`.
- No se aplica `active.branch` a propósito: el portal del dueño opera a nivel
  de empresa completa; exigir sucursal activa lo bloquearía sin necesidad.
- El Gate `cobros.acceder` combina permiso del rol y módulo contratado
  mediante `hasPermission()` existente; no hay bypass adicional.
- La activación/desactivación del módulo sigue siendo exclusiva de Platform
  Admin vía `platform.modules.update` (middleware `platform.admin`); el
  tenant no puede autoactivar el producto pagado.
- El layout de CobrOS es independiente del layout de Commerce.
- CobrOS no muestra menús de POS, inventario, caja, compras ni módulos
  internos de Commerce.
- Un usuario de otra empresa no puede acceder usando contexto de otra empresa:
  `EnsureActiveCompany` corrige la sesión a sus propias empresas.
- Activar CobrOS no activa automáticamente POS, caja, inventario ni RouteOS.
- Desactivar CobrOS bloquea el acceso sin eliminar datos.

## 6. Estatus de la base

- Base creada a partir del commit `b8033e2` (R01 MVS RouteOS).
- Migraciones aplicadas: 2026_09_16_000001, 2026_09_16_000002,
  2026_09_17_000001, 2026_09_17_000002, 2026_09_17_000003,
  2026_09_17_000004, 2026_09_17_000005, 2026_09_17_100001,
  2026_09_17_100002.
- Todas las migraciones pendientes al crear el worktree fueron aditivas y
  no destructivas.
- Prueba de humo: `RouteosR01Test` 13/13, 78 aserciones, 0 fallos.

## 7. Próximos pasos

- Implementar entidades de cobros sin modificar la base actual.
- Mantener la separación entre RuteOS y CobrOS hasta que la compartición
  de geolocalización y auditoría esté claramente definida.

## 8. Implementación de la Fase 1A (confirmado)

- Ruta: `GET /cobros` → `cobros.index` →
  `App\Http\Controllers\Cobros\CobrosDashboardController` (panel inicial vacío;
  no consulta cartera, caja, pagos, GPS ni rutas).
- Fase 1A.1: retirados el enlace CobrOS de Commerce, el retorno a Commerce y el sufijo Commerce del título propio. La arquitectura interna se conserva.
- Pruebas: `tests/Feature/CobrosModuleAccessTest.php` (12 pruebas,
  55 aserciones) cubren los 11 escenarios requeridos de acceso, perfiles,
  activación y aislamiento.

- Validación Fase 1A.1: 13 pruebas CobrOS, 63 aserciones. company.licensed permanece intacto: licencia empresarial operable y módulo CobrOS son suficientes, sin módulos Commerce adicionales. Detalle de regresión en PROGRESO_COBROS.md.
