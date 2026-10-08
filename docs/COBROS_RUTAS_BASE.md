# CobrOS: base estructural por ruta — 2026-10-04

Decisión vigente: **Prestamista → Rutas → Cobradores → Clientes**. `CobrosRoute`
es independiente de `Branch`; ninguna sucursal concede acceso a una ruta.
Una persona puede tener varias membresías activas, con una sola ruta operativa
seleccionada por sesión. La ruta y la empresa se revalidan al resolver el contexto.

## Base implementada

- Migración aditiva `2026_10_04_000006_create_cobros_routes`: rutas con nombre único
  por empresa, membresías múltiples `cobros_route_user`, licencias por ruta y
  `cobros_route_id` nullable en asignaciones, CxC y recibos. Claves compuestas
  impiden vincular rutas o usuarios de otra empresa, incluso mediante SQL directo
  en las tablas de membresías. No se modifican `branch_id` ni filas existentes.
- Modelos `CobrosRoute`, `CobrosRouteUser`, `CobrosRouteLicense`; relaciones de
  empresa/usuario/ruta y scopes `forCompany`/`forRoute`. Las consultas nuevas sin
  empresa activa fallan cerradas; trabajos internos deben declarar `forCompany`.
  No se permite cambiar la empresa de las nuevas entidades.
- Cada ruta necesita su propia licencia: compartir cobrador, cupo empresarial o
  administrador gratuito no concede otra licencia. Se reutilizan el ciclo de
  estados y vigencias (`HasCobrosLicenseLifecycle`), `CollectorLicenseService`,
  la licencia/módulo de empresa y `company_license_events`. La renovación exige
  Platform Admin, bloquea la ruta en transacción y registra auditoría. No hay
  emisión automática al crear una ruta ni transferencia de licencia entre rutas.
- `CobrosRouteContext` es una API interna, sin endpoint: exige empresa activa,
  membresía de empresa y ruta, permiso `cobros.cobrar`, usuario/ruta activos y
  licencia válida. Selección mediante un único `active_cobros_route_id` de sesión.

## Límite de esta entrega

Solo base estructural. Los controladores, consultas operativas y licencias por
cobrador existentes conservan su comportamiento. La integración de cartera,
cobros, agenda y cierre con el contexto de ruta queda para el siguiente bloque;
esta base no certifica aislamiento operativo de esos flujos aún no migrados.
Tampoco incluye UI, selector HTTP, agenda, backfill ni corrección del saldo migrado.
La unicidad existente de asignación por empresa/cliente se conserva.
Las migraciones solo se ejecutaron en SQLite `:memory:` durante los tests.
El rollback de esta migración se rechaza para impedir pérdida de atribución.

## Validación local

`CobrosRouteFoundationTest`, `CobrosIndividualLicenseTest`,
`CobrosPortfolioAssignmentTest`, `CobrosPaymentTest` y
`CobrosLicensingContractTest`: **95 tests, 455 aserciones** en SQLite.
PHP lint sobre los 16 PHP tocados y `git diff --check`; salida nativa comprobada
tras cada comando (equivalente fail-fast/pipefail en PowerShell).
Sin pruebas PostgreSQL, migración de datos locales, commit, push ni despliegue.
