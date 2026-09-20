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

## FASE 1B — Identidad propia + PWA base

- Logo oficial: `public/images/logo-mvs-cobros.png`, incorporado sin alterar
  la imagen. No se incluye la extensión duplicada `.png.png`.
- Layout propio con logo, favicon y apple-touch-icon de CobrOS; mantiene
  responsive e independencia de Commerce.
- Manifest estático público: `/manifest-cobros.json`, nombre `MVS CobrOS`,
  nombre corto `CobrOS`, `id`, `start_url` y `scope`: `/cobros`;
  `display: standalone`. Scope sin barra final para incluir la ruta actual
  `/cobros` y sus descendientes sin cambiar rutas ni redirecciones.
  El scope del manifest delimita presentación, no sustituye autorización.
- Reutilizados los cuatro iconos normales/maskable 192/512 ya existentes;
  no se regeneraron. Dorado `#D4AF37` del layout y blanco `#ffffff` existente.
- Sin service worker: se reutiliza el patrón de manifest estático del proyecto;
  no hay registro SW existente. No se añade caché, API offline ni datos locales.
  [MDN](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable)
  no exige SW para instalación. Requiere HTTPS (localhost para desarrollo)
  y navegador compatible; instalación real en dispositivo pendiente.
- Autenticación compartida intacta: `/login` queda fuera del scope; con sesión
  vencida sigue aplicando el flujo de acceso existente.
- Sin funciones del dueño/cobrador, cambios de negocio, RuteOS ni Commerce.
  `tmp_generate_cobros_icons.php` permanece untracked e intacto.

## FASE 1C — Portal del Dueño / Dashboard de cartera de solo lectura

- `GET /cobros/owner`, nombre `cobros.owner.dashboard`, conserva `auth`,
  `active.company`, `company.licensed`, `company.module:cobros` y
  `permission:cobros.acceder`; agrega `permission:cobros.portal_dueno`.
- `/cobros` sigue siendo entrada general: enlace «Consultar cartera» solo
  para dueños autorizados. Cobradores/supervisores sin ese permiso no ven
  métricas globales; no se obliga `active.branch`.
- `PortfolioSummaryService` consulta AccountReceivable, sus relaciones
  Customer/Sale/Branch y AccountReceivablePayment con ámbito empresarial.
  AccountsReceivableService existente solo ofrece métodos de escritura:
  se conserva intacto y no se invoca `pay()` ni `createForSale()`.
- Métricas: saldo total, saldo vencido, pagos de hoy, clientes distintos con
  saldo, cuentas próximas a vencer y cuentas vencidas. Saldo positivo,
  excluyendo paid/cancelled; vencimiento real por fecha, sin depender del
  status guardado. Ventana próxima inclusiva hoy..hoy+credit_alert_days.
- «Hoy» usa zona horaria de la empresa. Pagos: intervalo desde medianoche
  inclusive hasta mañana exclusivo, convertido a zona de almacenamiento de
  la aplicación. Sumas BCMath con casts decimal:4 en lotes de 500, sin float.
  Totales nominales sin conversión monetaria; cada fila conserva su moneda.
- Filtro GET opcional `branch_id`: por defecto todas las sucursales, sin
  cambiar sucursal operativa. Sucursal ajena/inexistente: 404; valor inválido:
  validación de formulario. Métricas y listado comparten el mismo filtro.
- Listado paginado a 20, orden por vencimiento e ID: vencidas primero, luego
  próximas y posteriores. Cliente, venta si existe, sucursal, emisión,
  vencimiento, saldo y estado efectivo calculado solo para presentación.
  El status persistido nunca se modifica.
- UI en vistas CobrOS: tarjetas adaptables móvil/tablet/escritorio, filtros
  de 44px, identidad propia y sin enlaces hacia Commerce. No hay pagos.
- Pagos reales, cobrador, asignaciones, rutas/mapas, notificaciones y offline
  quedan para fases posteriores. Sin migraciones ni cambios de negocio.
