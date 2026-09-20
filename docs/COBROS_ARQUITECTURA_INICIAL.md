# MVS CobrOS — Arquitectura Inicial

Documento de decisiones aprobadas para el módulo CobrOS. Recopilado en la base
`feature/cobros-v1` creada a partir del commit `b8033e2` (R01 MVS RouteOS).

## 1. Identidad del producto

- CobrOS será un producto independiente visualmente dentro de MVS Commerce.
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
- El módulo usa la clave interna `cobros`.
- Los permisos de CobrOS son independientes de `routeos.*`.
- La entrada al portal usa middleware propio: `auth`, `active.company`,
  `company.module:cobros`, `permission:cobros.acceder`.
- El layout de CobrOS es independiente del layout de Commerce.
- CobrOS no muestra menús de POS, inventario, caja, compras ni módulos
  internos de Commerce.
- Un usuario de otra empresa no puede acceder usando contexto de otra empresa.
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
