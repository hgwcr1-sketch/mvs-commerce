# MVS CobrOS — Registro de avance

> Fuente oficial del orden de fases: `docs/CRONOGRAMA_COBROS.md`
> (Cronograma Maestro de MVS CobrOS). Este registro documenta la evidencia
> de avance de cada fase; el cronograma define el orden y el alcance.

## Fase 1A — Completada: fundación aislada del módulo

Rama `feature/cobros-v1` en el worktree `mvs-commerce-cobros`.

### Archivos creados

- `app/Http/Controllers/Cobros/CobrosDashboardController.php` — panel inicial.
- `app/Http/Middleware/EnsureCompanyModule.php` — middleware reutilizable de
  módulo contratado por empresa (403 si no está activo).
- `resources/views/cobros/layout.blade.php` — layout propio de CobrOS,
  independiente del de Commerce (retorno eliminado en Fase 1A.1).
- `resources/views/cobros/dashboard.blade.php` — panel inicial sin datos
  financieros simulados.
- `tests/Feature/CobrosModuleAccessTest.php` — 12 pruebas, 55 aserciones.
- `docs/PROGRESO_COBROS.md` — este registro.

### Archivos modificados

- `app/Services/Modules/ModuleRegistry.php` — módulo `cobros` registrado
  ("MVS CobrOS", familia `cobros.*`).
- `database/seeders/PermissionSeeder.php` — permisos `cobros.acceder`,
  `cobros.portal_dueno`, `cobros.supervisar`, `cobros.cobrar`.
- `routes/web.php` — grupo de rutas `/cobros` (nombre `cobros.*`) separado.
- `bootstrap/app.php` — alias `company.module` → `EnsureCompanyModule`.
- `resources/views/components/navigation/sidebar.blade.php` — enlace
  "MVS CobrOS" original (eliminado en Fase 1A.1).
- `docs/COBROS_ARQUITECTURA_INICIAL.md` — decisiones técnicas confirmadas.

### Permisos creados (solo fundamentales)

- `cobros.acceder`, `cobros.portal_dueno`, `cobros.supervisar`, `cobros.cobrar`.

### Middleware aplicado a la ruta `cobros.index`

- `auth`
- `active.company` (corrige sesión de empresa ajena a la propia)
- `company.licensed` (licencia empresarial global existente)
- `company.module:cobros` (módulo contratado, 403 si no)
- `permission:cobros.acceder` (403 si no)

Sin `active.branch` a propósito: el portal del dueño opera a nivel de empresa.

### Pruebas

- `CobrosModuleAccessTest`: **12 passed, 55 assertions** (acceso, perfiles,
  activación, aislamiento, navegación).
- Regresión: `RouteosR01Test` 13/13, `CompanyModuleAccessTest` 6/6,
  `CompanyLicenseTest` 4/4, `UserRoleSecurityTest` 8/8, `CompanyOnboardingTest`
  3/3, `CompanyProvisioningTest` 6/6, `CustomerCodeTest` 9/9,
  `CustomerCompanyIsolationTest` 4/4 — **54 passed, 237 aserciones**.
- Fallo preexistente documentado: `OrderPermissionSeederTest` (falla igual en
  el commit base; el seeder ahora asigna permisos solo a roles
  `is_super_admin` desde `7fe7e79`, el test espera el comportamiento antiguo).
  No se corrige en esta fase.

### Riesgos pendientes

- Los 4 permisos `cobros.*` solo existen si se ejecuta el seeder (o se crean
  por pruebas); las empresas reales los recibirán al actualizar el catálogo
  de permisos por el mecanismo normal.
- `OrderPermissionSeederTest` desactualizado respecto al flag
  `is_super_admin` (preexistente, corrección futura aparte).
- Pendiente histórico de 1A: PWA y logo; identidad y manifest base entregados en Fase 1B (ver abajo), offline fuera de alcance.
## Fase 1A.1 — Separación visual y navegacional (2026-09-20)

Decisión definitiva: CobrOS es una aplicación separada para el usuario,
compartiendo servicios internos del monolito modular.

- Eliminado CobrOS del sidebar compartido de Commerce (escritorio y menú móvil).
- Eliminados el retorno a Commerce y el sufijo Commerce del título de CobrOS.
- RuteOS, arquitectura interna, autenticación, iconos y licenciamiento intactos.
- `company.licensed` no requiere módulos Commerce: verifica que la licencia
  empresarial sea operable. Prueba HTTP con contrato exclusivamente `cobros`,
  licencia activa y `cobros.acceder`: acceso 200, sin sucursal en sesión;
  al suspender la licencia redirige a `license.status`.
- Sin módulo o sin permiso, CobrOS mantiene el rechazo 403.

Validación en SQLite en memoria:
- `CobrosModuleAccessTest`: 13/13, 63 aserciones.
- `CompanyLicenseTest`, `CompanyModuleAccessTest`, `RouteosR01Test` y nueve
  casos de navegación de `ResponsiveNavigationTest` (bottom_bar, sidebar,
  layout_includes_mobile, tenant_shell, pos_mobile, master_panel): 32/32,
  149 aserciones.
- Ejecución con `ResponsiveNavigationTest` completo: 46/47, 220 aserciones.
  Falla `test_tenant_header_uses_company_logo_or_neutral_initial_and_keeps_mvs_brand_separate`
  al esperar `/storage/companies/header-test-*.png`. Renderiza directamente
  `components.header`; test, encabezado y modelo Company están sin cambios
  respecto a HEAD. Se documenta la limitación ajena al alcance, sin corregirla.
- Revisión conceptual 360/768/1280: solo se retiran elementos; se conservan
  breakpoints y contenedores existentes. Sin validación visual en navegador.

Usuario autoriza el commit con la excepción de regresión documentada; sin push ni merge.

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

### Validación Fase 1B

- `CobrosModuleAccessTest|CobrosManifestTest`: 14/14, 98 aserciones.
- Regresión dirigida de 1A.1: `CompanyLicenseTest|CompanyModuleAccessTest|RouteosR01Test`
  y los nueve casos de navegación de `ResponsiveNavigationTest`: 32/32,
  149 aserciones. No se reejecutó el fallo ajeno del logo Commerce.
- HTTP local sin autenticación: manifest 200/application-json y siete PNG
  200/image-png (logo, favicon, apple-touch-icon y cuatro iconos del manifest).
- `git diff --check` correcto. Iconos existentes y navegación Commerce intactos.
- `npm.cmd run build` no ejecutable: falta `node_modules/.bin/vite.cmd`.
  Sin instalación de dependencias. Las clases h-16, w-16, shrink-0 y
  object-contain ya están presentes en el CSS compilado del worktree;
  no se modificaron fuentes CSS/JS.
- Responsive revisado conceptualmente a 360/768/1280; logo de 64x64 con
  object-contain y ancho fijo, sin alterar breakpoints. No se verificó
  instalación PWA ni apariencia en dispositivo/navegador real.
- Pruebas de acceso con SQLite en memoria; sin cambios a BD de desarrollo,
  migraciones del producto, push ni merge.

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

### Validación Fase 1C

- `CobrosOwnerDashboardTest|CobrosModuleAccessTest|CobrosManifestTest`:
  **22/22, 151 aserciones** (8 pruebas nuevas del dueño).
- Regresión `CompanyLicenseTest|CompanyModuleAccessTest|RouteosR01Test` y
  nueve casos de navegación responsive ya usados en 1B: **32/32, 149 aserciones**.
- Dos empresas, sucursal ajena 404, permiso dueño obligatorio, módulo/base
  obligatorios, sin sucursal asignada, filtro independiente de sesión,
  vencimiento real y límites inclusivos, pagos en ambos límites del día,
  decimales, estado efectivo y paginación con filtro conservado.
- Query log del GET: cero INSERT/UPDATE/DELETE/DDL; snapshots de cuentas,
  pagos y cajas intactos. Mock del servicio financiero impide pay/createForSale.
  Fixtures exclusivamente SQLite en memoria, sin modificar BD de desarrollo.
- Pint de tres PHP nuevos y `git diff --check`: correctos.
- Vite sigue ausente en este worktree (limitación de 1B): sin build nuevo ni
  instalación de dependencias. Las 40 clases estáticas de la nueva vista
  existen en el CSS compilado disponible. Responsive conceptual 360/768/1280;
  validación visual en navegador/dispositivo pendiente.
- Sin cambios a Commerce/RuteOS ni corrección de fallos ajenos. Sin ejecutar
  migraciones sobre BD del proyecto, sin push ni merge.

## FASE 1D — Estructura operativa de cobradores

Alcance aprobado por el usuario para 1D: reutilizar estructura existente,
SIN alta de usuarios paralelos, pivotes nuevos, migraciones ni seeders.
Esta instrucción sustituye la previsión inicial del cronograma de crear
pivote/permisos y UI de alta: no se implementa esa previsión en esta fase.

- User existente = identidad del cobrador.
- company_user = relación empresa/rol; branch_user = sucursales operativas
  del cobrador. No existe CobrosCollector ni estado activo duplicado.
- `CollectorAccessService` es de solo lectura: `branchesForCollecting()` y
  `canCollectIn()` reutilizan `User::isActive()` y `hasPermission()` con
  `cobros.cobrar` en la empresa recibida. Este último ya valida pertenencia,
  rol de esa empresa activo, permiso activo y módulo contratado.
- Toda consulta de sucursales asignadas añade `branches.company_id` y
  `branches.is_active`; un branch_user ajeno/contaminado nunca concede acceso.
  `active_branch_id` NO autoriza cobranza. No se lee desde el servicio.
- Supervisor = User + cobros.supervisar; Dueño = User + cobros.portal_dueno.
  Supervisor consulta sucursales de la empresa activa sin exigir asignación
  individual; dueño conserva dashboard 1C y su filtro empresarial.
- `/cobros` redirige según permisos EN la empresa activa, en este orden:
  Dueño → `/cobros/owner`; Supervisor → `/cobros/supervisor`;
  Cobrador → `/cobros/collector`; sin permiso operativo → 403.
- Rutas específicas conservan grupo base auth/active.company/company.licensed/
  company.module:cobros/permission:cobros.acceder y exigen su permiso propio.
  `EnsureActiveUser` solo en CobrOS usa isActive() para bloquear 403 incluso
  sesiones existentes de usuarios inactivos; no modifica middleware/modelos
  compartidos. Era necesario porque hasPermission no comprueba is_active
  del usuario (sí el del rol).
- Landings mínimas con identidad/layout CobrOS, sucursales permitidas y aviso
  de fase pendiente: supervisor 4A, cobrador 2A después de 1E. Sin métricas,
  clientes, asignaciones, visitas, pagos ni datos simulados.
- Pruebas previas de entrada adaptadas a las redirecciones; dashboard 1C,
  sus métricas y control de lectura se conservan.

### Revisión focal de seguridad y validación 1D

- Suite CobrOS: **34/34, 247 aserciones**, incluidas 12 pruebas nuevas 1D,
  ocho pruebas 1C, accesos previos e identidad/manifest.
- Cubiertos permisos directos y prioridad, falta de permiso operativo,
  pertenencia a empresa, pivote contaminado, cambio de contexto del mismo
  usuario con roles distintos, usuario/rol/sucursal inactivos, ausencia de
  asignación, módulo/licencia deshabilitados e independencia de sucursal
  Commerce. IDs enviados en request no sustituyen el contexto validado.
- Query log del servicio: cero escrituras. Sin cambios a modelos compartidos,
  finanzas, UI Commerce, RuteOS o BD persistente; fixtures SQLite en memoria.
- Pint de PHP nuevos/controlador y diff-check correctos. Revisión responsive
  conceptual 360/768/1280: texto adaptable y listado sin tabla ni acciones.
  Las 19 clases de la landing existen en el CSS compilado disponible.
  Se conserva limitación 1B: Vite no instalado; sin nuevo build ni validación
  visual real. No se amplió PWA ni se instalaron dependencias.
- `tmp_generate_cobros_icons.php` intacto y fuera del commit. Sin push ni merge.

## UI base — Shell independiente antes de 1E (2026-09-20)

- Recuperación post-apagado: caso C, rama feature/cobros-v1, HEAD inicial
  a8fb2a8. Sin cambios UI ni commit previo de shell; único untracked inicial
  tmp_generate_cobros_icons.php, preservado y excluido.
- Shell inspirado en la UX Commerce, con navegación completamente propia:
  Inicio / Cartera, Supervisión y Mi cartera, filtrados con hasPermission
  contra la empresa activa. Logo CobrOS, empresa, usuario y salida compartidos
  por los tres perfiles. Sin enlaces Commerce ni módulos futuros.
- Azul oscuro, dorado oficial y blanco. Barra inferior móvil, rail tablet y
  sidebar escritorio; controles de 44px, foco visible y salto al contenido.
  CSS público exclusivo de CobrOS, cargado solo por su layout, sin cambiar
  fuentes CSS/JS compartidas. No depende de un build nuevo para estos estilos.
- Dashboard conserva las seis métricas, filtros, paginación y consultas 1C.
  Total por cobrar destacado, filtro compacto y cuentas en tarjetas adaptables.
  Landings 1D mantienen sucursales reales y aviso de disponibilidad futura.
- Dinero UI: exactamente 2 decimales, separador de miles y código monetario
  en cada cuenta (ejemplo CRC 1,234.56). Totales nominales conservan la semántica
  1C, sin atribuirles una moneda ni convertirlos. Helper Money + parcial Blade
  reutilizable, redondeo visual de mitades alejándose de cero usando cadenas
  y BCMath; normaliza cero negativo y soporta importes grandes sin float.
  Precisión interna DECIMAL(19,4)/decimal:4 + BCMath intacta.
- Suite CobrOS: **48/48 pruebas, 327 aserciones**, SQLite exclusivamente en
  memoria. Incluye casos monetarios, HTML sin .0000 ni texto de cuatro
  decimales, enlaces y permisos por empresa activa, aislamiento 1D y métricas
  y cero escrituras del dashboard 1C. Sin cambios a servicios financieros.
- Responsive revisado conceptualmente en 360/768/1280. Vite no instalado en
  este worktree; build nuevo y validación visual real en navegador pendientes.
  No se instalaron dependencias. Esto no certifica apariencia ni PWA real.
- Fase 1E NO iniciada. Sin migraciones/seeders sobre BD persistente, cambios
  de BD de desarrollo, pagos/caja/recibos nuevos, push, merge ni despliegue.
