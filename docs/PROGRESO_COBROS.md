# MVS CobrOS — Registro de avance

## 6B — Preparación local, piloto BLOQUEADO (2026-10-01)

Base `feature/cobros-v1`, `9b0a218`. Informe reproducible, inventario de
migraciones, revisión PG, brechas V1 y respaldo/aplicación/build/smoke/recuperación
sin borrar pagos: [COBROS_6B_PREPARACION_PILOTO.md](COBROS_6B_PREPARACION_PILOTO.md).
Suite focal previa: 279/279, 2277 aserciones; pruebas nuevas 6B: 2/2, 21.
Probe multiproceso SQLite exclusivo PASS: un pago/recibo, retry y caja intacta.
Worker con red fallida simulada PASS; build/Pint PASS. No funciones nuevas.
PG real pendiente (sin pdo_pgsql); Chrome CDP falló dos veces, sin aprobación
360/768/1280 ni instalación. Hallazgos bloqueantes: asset PWA ausente/no incluido
en build y floats en servicio financiero compartido; brechas de cupos,
liquidación/custodia, dispositivos/GPS. Auditoría/usuario pendientes.
6B/DoD no completada. Sin SSH/push/merge/deploy ni cambios a base de revisión;
servidor y temporales previos preservados. Commit limitado a pruebas/documentos.

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

## Corrección exacta de navegación y símbolo monetario (2026-09-20)

- Base f0e31be limpia salvo el temporal excluido. Se inspeccionó el Commerce
  actual: barra inferior horizontal en todos los tamaños y menú oscuro bajo
  demanda desde Más. La indicación específica del usuario sustituye el rail
  tablet/sidebar permanente de la UI base anterior.
- CobrOS adopta ese patrón sin copiar rutas: iconos con texto debajo, opciones
  autorizadas y Más con hamburguesa; una única fuente Blade para barra y panel.
  El diálogo nativo inicia cerrado, contiene logo/empresa/usuario/menú/salida,
  mantiene el foco dentro y cierra con botón, Escape o backdrop, restaurando
  foco y scroll. No reserva ancho lateral en ningún breakpoint.
- Dashboard, cards, header, filtro y landings conservados. Las tres métricas
  monetarias ahora llevan ₡; CRC en cuentas también usa ₡ y otras monedas
  conservan su código. Conteos enteros sin símbolo. Money/BCMath, precisión
  interna DECIMAL(19,4), consultas y contratos financieros 1C/1D intactos.
- Suite CobrOS: **49/49, 360 aserciones**, SQLite en memoria; incluye moneda,
  conteos, permisos de ambas navegaciones, aislamiento y dashboard read-only.
- Chrome headless sobre HTML real renderizado por prueba HTTP y assets locales:
  PASS a 360/768/1280, barra horizontal, ancho útil completo, cero overflow,
  controles >=44px, panel inicialmente oculto, apertura y los tres cierres,
  retorno de foco y métricas. Capturas 360/1280 revisadas. No es certificación
  de PWA instalada ni prueba manual con datos reales.
- Reproducible: establecer COBROS_SHELL_HTML al archivo temporal deseado antes
  de ejecutar CobrosOwnerDashboardTest; después ejecutar
  node tests/js/cobros-shell-browser.cjs con esa misma variable. Node 24 y
  Chrome local usados, sin dependencias nuevas; COBROS_CHROME_PATH permite
  indicar otra instalación de Chrome. Capturas en el directorio temporal.
- Vite sigue ausente; no se hizo build nuevo. CSS/JS CobrOS públicos versionados
  cargan directamente. Sin 1E, BD persistente, migraciones, seeders, cambios
  Commerce/RuteOS, push ni merge. tmp_generate_cobros_icons.php intacto.

## Fase 1E — Autenticación propia (2026-09-20)

Login standalone de CobrOS reutilizando auth web/User existente. Sin duplicación
de User, passwords, guard ni autenticación.

### Archivos creados

- `app/Http/Controllers/Cobros/LoginController.php` — extiende el controlador
  compartido de Commerce, sobrescribe `authenticated()`, `failedLoginRedirect()`
  y `loggedOut()` para rutas CobrOS.
- `app/Http/Middleware/Cobros/Authenticate.php` — middleware de autenticación
  que redirige no autenticados a `cobros.login`.
- `app/Http/Middleware/Cobros/RedirectIfAuthenticated.php` — redirige
  usuarios autenticados desde login a `cobros.index`.
- `app/Http/Requests/Cobros/LoginRequest.php` — validación con redirect
  a `cobros.login`.
- `resources/views/cobros/auth/login.blade.php` — vista standalone con
  branding CobrOS, sin elementos visuales de Commerce.
- `public/css/cobros-auth.css` — estilos exclusivos del login CobrOS.
- `tests/Feature/CobrosAuthenticationTest.php` — 16 pruebas, 179 aserciones.
- `tests/js/cobros-login-browser.cjs` — prueba visual Chrome headless.

### Archivos modificados

- `app/Http/Controllers/Auth/LoginController.php` — refactor para sobreescribir
  con hooks (`authenticated()`, `failedLoginRedirect()`, `loggedOut()`) que
  CobrOS aprovecha sin duplicar lógica.
- `resources/views/cobros/layout.blade.php` — logout apunta a `cobros.logout`.
- `routes/web.php` — rutas de login/logout CobrOS fuera del grupo `auth`
  compartido; rutas protegidas migradas con middleware propio.
- `tests/Feature/CobrosModuleAccessTest.php` — redirección invitado → `cobros.login`.
- `tests/Feature/CobrosOwnerDashboardTest.php` — redirección invitado → `cobros.login`.

### Comportamiento

- `/cobros/login` autónomo: branding CobrOS, CSRF, rate limiting, regeneración
  de sesión, recordar-me. Sin enlaces a Commerce ni recuperación de contraseña.
- Login exitoso → `/cobros/index` resuelve permisos (dueño/supervisor/cobrador).
- Logout desde CobrOS → `/cobros/login` (invalida sesión, regenera token).
- Login/logout normales de Commerce intactos, rutas y rutas protegidas no
  afectadas. Rate limiter compartido (misma clave email+IP).
- Usuario ya autenticado en `/cobros/login` → redirige a `/cobros`.
- Responsive 360/768/1280 verificada con Chrome headless.

### Validación

- CobrosAuthenticationTest: **16/16, 179 aserciones**.
- CobrosModuleAccessTest: **13/13, 77 aserciones**.
- CobrosOwnerDashboardTest: **9/9, 81 aserciones**.
- CobrosManifestTest: **1/1, 30 aserciones**.
- Total CobrOS: **39/39, 367 aserciones**.
- Regresión CompanyLicenseTest|CompanyModuleAccessTest|RouteosR01Test: **13/13, 78 aserciones**.
- ResponsiveNavigationTest: **10/11** (fallo preexistente del logo Company
  documentado desde Fase 1A.1, no relacionado).
- Sin migraciones ni cambios de BD. Sin push ni merge.
- `tmp_generate_cobros_icons.php` intacto.

## Ajustes visuales finales (2026-09-20)

Correcciones puntuales de UI sin rediseño ni cambio funcional.

### Cambios

- Login: botón ojo mostrar/ocultar contraseña con SVG, aria-label y
  aria-pressed. Táctil >=44px. JS inline, sin dependencias. Logo aumentado
  a 120×120px. CSS versionado v2.
- Navegación inferior: item activo cambiado de bloque dorado sólido a
  cambio de color textual discreto (`color: var(--cobros-gold)`), imitando
  el patrón de navegación Commerce (text-amber-600).
- Panel "Más": botón cerrar sesión reestilizado con fondo transparente,
  borde sutil, color coherente con el panel oscuro e icono SVG inline.
  Eliminado el rectángulo blanco vacío.

### Validación

- CobrOS suite: **65/65, 539 aserciones**.
- Regresión CompanyLicense|CompanyModuleAccess|RouteosR01|UserRoleSecurity:
  **9/9, 36 aserciones**.
- Chrome headless 360/768/1280: PASS con logo 120px, password toggle
  show/hide, controles >=44px, sin overflow, branding aislado.
- Sin 1E, BD, migraciones, push ni merge.
- `tmp_generate_cobros_icons.php` intacto.

## 1E-A — Contrato de licenciamiento CobrOS (2026-09-20)

Base contractual/licenciamiento con dos modalidades controladas por Panel Maestro.

### Modalidades

**A) Individual:**
- Licencias individuales por cobrador.
- 3 cobradores + 1 dueño/admin = 4 licencias potenciales.
- Platform Admin controla cobradores/licencias.
- Campo `admin_license_charged` indica si la licencia del dueño se cobra.
- Dueño NO crea cobradores (eso es 1E-B).

**B) Company cupo:**
- Una licencia empresa.
- Platform Admin define `collector_limit`.
- Dueño podrá crear cobradores hasta ese límite en 1E-B.
- Dueño NO puede modificar modalidad ni límite.

### Archivos creados

- `database/migrations/2026_09_20_000001_add_cobros_contract_fields_to_company_modules_table.php` —
  migración aditiva: `license_mode`, `collector_limit`, `admin_license_charged`.
- `tests/Feature/CobrosLicensingContractTest.php` — 17 pruebas, 60 aserciones.

### Archivos modificados

- `app/Models/CompanyModule.php` — fillable, casts, const LICENSE_MODES.
- `app/Services/CompanyLicenseService.php` — `updateCobrosContract()`,
  `getCobrosContract()`.
- `app/Http/Controllers/PlatformAdminController.php` — `updateCobrosContract()`,
  `show()` pasa `cobrosContract`.
- `resources/views/platform/show.blade.php` — sección de contrato CobrOS.
- `routes/web.php` — ruta `platform.cobros-contract.update`.

### Comportamiento

- Solo Platform Admin puede configurar el contrato CobrOS.
- Modalidad individual: `collector_limit` se limpia a null.
- Modalidad company_cupo: `collector_limit` es obligatorio (min:1, max:999).
- `admin_license_charged` siempre requerido (boolean).
- Configurar el contrato habilita automáticamente el módulo cobros.
- Cada cambio genera evento de auditoría con snapshot completo.
- Aislamiento multiempresa: contrato A no afecta contrato B.
- Sin migraciones destructivas. Sin push ni merge.
- `tmp_generate_cobros_icons.php` intacto.

### Validación

- CobrosLicensingContractTest: **17/17, 60 aserciones**.
- CobrOS suite completa: **82/82, 599 aserciones**.
- CompanyLicenseTest|CompanyModuleAccessTest: **6/6, 20 aserciones**.
- Sin cambios en rutas/auth/dashboard Commerce.
- Sin 1E-B, 1E-C, 2A ni pagos.

## 1E-B — Gestión de cobradores según contrato (2026-09-21) — COMPLETADO

Gestión de cobradores controlada por la modalidad de licenciamiento de la empresa.

### Modalidades

**A) company_cupo:** El dueño crea y administra cobradores hasta el `collector_limit`.
**B) individual:** Solo Platform Admin gestiona cobradores; el dueño ve una vista de solo lectura.

### Archivos creados

- `app/Services/Cobros/CollectorManagementService.php` — servicio centralizado
  de gestión: getContract, listCollectors, getCollectorCount, getRemainingSlots,
  canCreateCollector, isOwnerReadOnly, createCollector (nuevos y existentes,
  con $skipLimit para Platform Admin), toggleCollector, assignBranches, replaceBranches.
- `app/Http/Controllers/Cobros/OwnerCollectorController.php` — controlador
  del portal del dueño: index, store, toggle, updateBranches.
- `resources/views/cobros/owner/collectors.blade.php` — vista de gestión
  con tarjetas de cobradores, modal de creación y acciones de activar/desactivar.
- `tests/Feature/CobrosCollectorManagementTest.php` — 23 pruebas, 57 aserciones.

### Archivos modificados

- `app/Services/Cobros/CollectorAccessService.php` — getListingsByCompany
  adaptado para incluir coletores inactivos con estado visual.
- `app/Http/Controllers/PlatformAdminController.php` — sección de cobradores
  en la vista de detalle de empresa para Platform Admin; storeCollector,
  toggleCollector y updateCollectorBranches para gestión individual.
- `resources/views/platform/show.blade.php` — sección de cobradores con
  conteo, tabla, formulario de creación y acciones de toggle/sucursales
  para modalidad individual; solo lectura para company_cupo.
- `routes/web.php` — rutas CRUD de cobradores del dueño + 3 rutas Platform
  Admin (store, toggle, branches) bajo prefijo `platform.`.
- `resources/views/components/navigation/sidebar.blade.php` — enlace
  "Cobradores" para dueños con permiso `cobros.portal_dueno`.

### Comportamiento

- OwnerCollectorController valida `isOwnerReadOnly()` antes de escrituras.
- createCollector: email existente dentro de la empresa → reutiliza el usuario.
- company_cupo: createCollector valida `canCreateCollector()` antes de crear.
- toggleCollector: activa/desactiva el usuario; inactivos no cuentan para el cupo.
- assignBranches: valida que las sucursales pertenezcan a la empresa.
- replaceBranches: reemplaza sucursales del cobrador (Platform Admin).
- Platform Admin en panel individual: crear, activar/desactivar y gestionar
  sucursales de cobradores; validación de licencia `individual` en cada mutación.
- Platform Admin en panel company_cupo: solo vista de conteo (lectura).
- Sin migraciones nuevas. Sin push ni merge. Sin 1E-C, 2A ni pagos.
- `tmp_generate_cobros_icons.php` intacto.

### Completado: onboarding de credenciales temporales (2026-09-21)

Cierre del onboarding de cobradores 1E-B: contraseña temporal, primer login
forzado y envío por WhatsApp en ambas modalidades.

**Archivos creados:**
- `app/Services/Cobros/CollectorPasswordService.php` — generación segura de
  contraseña temporal: 6 caracteres, 1 mayúscula + 1 minúscula + 4 dígitos,
  sin caracteres especiales, Fisher-Yates shuffle.
- `app/Http/Controllers/Cobros/ForceChangePasswordController.php` — vista y
  POST de cambio obligatorio de contraseña en primer ingreso.
- `resources/views/cobros/auth/force-change.blade.php` — vista standalone
  con branding CobrOS, ojo mostrar/ocultar en ambos campos, validación
  `min:8 + mixedCase + numbers + confirmed`.
- `database/migrations/2026_09_21_000001_add_must_change_password_to_users_table.php` —
  columna boolean `must_change_password` en tabla `users` (pendiente de
  ejecutar en BD de desarrollo).
- `tests/Feature/CobrosCollectorOnboardingTest.php` — 26 pruebas, 589
  aserciones.

**Archivos modificados:**
- `app/Models/User.php` — atributo `must_change_password` en fillable y cast
  boolean; cast `hashed` en password garantiza que la temporal nunca se
  persista en texto plano.
- `app/Services/Cobros/CollectorManagementService.php` — createCollector y
  regenerateAccess generan contraseña temporal, setean `must_change_password`
  y retornan `plain_password` solo en memoria.
- `app/Http/Controllers/Cobros/LoginController.php` — `authenticated()`:
  si `must_change_password` es true, redirige a `cobros.password.force`.
- `app/Http/Controllers/Cobros/OwnerCollectorController.php` — store y
  regenerateAccess envían `collector_plain_password`, `collector_email`,
  `collector_name`, `collector_phone` por session flash.
- `app/Http/Controllers/PlatformAdminController.php` — storeCollector y
  regenerateCollectorAccess envían los mismos datos por session flash.
- `resources/views/cobros/owner/collectors.blade.php` — muestra tarjeta
  "Acceso generado" con contraseña visible, botón copiar, WhatsApp normalizado
  con prefijo 506 y mensaje de bienvenida.
- `resources/views/platform/show.blade.php` — misma tarjeta para Platform
  Admin en modalidad individual.
- `routes/web.php` — rutas `cobros.password.force` y `cobros.password.force.store`.
- `tests/Feature/CobrosCollectorManagementTest.php` — adaptado al cambio de
  firma de createCollector (retorna array con plain_password).

**Comportamiento verificado:**
- Contraseña temporal: 1 upper + 1 lower + 4 digits, exactamente 6 chars.
- Validación de formato rechaza cadenas inválidas.
- Hash bcrypt nunca almacena texto plano; `password` cast `hashed` en User.
- Primer login → redirección a cambio obligatorio; tras cambiar, temporal
  inoperativa; nueva contraseña funciona.
- "Nuevo acceso" regenera temporal, invalida la anterior, setea must_change.
- Ojo SVG en campos de contraseña (login y force-change) con aria-label.
- WhatsApp: normalización 8 dígitos → 506+; mensaje con URL login, usuario,
  temporal e instrucción de cambio; botón `wa.me` solo si hay teléfono.
- Modalidad individual: Platform Admin crea/cambia; dueño solo lectura.
- Modalidad company_cupo: dueño crea/cambia dentro del cupo.

**Migración pendiente en BD de desarrollo:**
- `2026_09_21_000001_add_must_change_password_to_users_table` — ejecutar
  `php artisan migrate` antes de usar en BD real. Tests usan
  `RefreshDatabase` y no lo necesitan.

### Validación

- CobrosCollectorOnboardingTest: **26/26, 589 aserciones**.
- CobrOS suite completa: **132/132, 1258 aserciones**.
- Auth/Login regression: **27/27, 165 aserciones**.
- Regresión CompanyLicenseTest|CompanyModuleAccessTest|RouteosR01Test
  y responsive: **13/13, 78 aserciones**.
- `git diff --check` limpio. Sin cambios en rutas/auth/dashboard Commerce.

## 2A — Mi Cartera (2026-09-23) — COMPLETADA / CERTIFICADA.

Certificación: BUILD completo; validación manual PASS (Mario ve solo
CLIENTE PRUEBA COBROS 1 ₡25,000.00; no asignado ₡40,000.00 oculto; detalle
CxC/saldo/sucursal/emisión/vencimiento correcto); IDOR manual
/cobros/collector/portfolio/3 → 404 PASS; auditoría final PASS; tests 2A
10/10; suite CobrOS 178/178, 1453 aserciones. Sin migraciones.

**Alcance entregado (solo lectura, sin cobros):**
- `app/Services/Cobros/CollectorPortfolioService.php` — cartera real del
  cobrador: autoridad SIEMPRE empresa activa de sesión + collector_user_id =
  auth()->id(); nunca acepta collector_id del request. Saldos con BCMath
  (escala 4, presentación CRC 2 decimales vía Money). Excluye
  paid/cancelled/balance_due<=0. Último pago solo con fuente fiable
  (payment_method_id y cash_session_id presentes).
- `app/Http/Controllers/Cobros/CollectorPortfolioController.php` — index/show.
- Rutas: `cobros.collector.index` = `/cobros/collector` (ahora cartera real,
  reemplaza la pantalla "próximamente") y `cobros.collector.portfolio` +
  `cobros.collector.portfolio.show` = `/cobros/collector/portfolio/{customer}`,
  ambas con `permission:cobros.cobrar`. Cliente ajeno, sin asignar o
  cross-company → 404 (nunca 403, no revela existencia).
- Vistas mobile-first 360/768/1280 con identidad CobrOS:
  `cobros/collector/portfolio.blade.php` (buscador superior ?q= por
  nombre/comercial/identificación/teléfono, tarjetas compactas, saldo
  destacado, CxC abiertas, sucursal(es), próximo vencimiento con due_date
  real, último pago, paginación 20, botón "Ver cliente") y
  `cobros/collector/customer.blade.php` (identificación/contacto, saldo total,
  CxC con sale_number/issued_at/due_date/balance_due/sucursal, pagos históricos
  solo lectura; SIN enlaces a clientes.show de Commerce; SIN botones
  Cobrar/Pagar).
- `app/Models/Customer.php` — relación `cobrosAssignments()` (solo lectura).

**Pruebas:**
- Nuevo `tests/Feature/CobrosCollectorPortfolioTest.php` — 10/10, 49
  aserciones: aislamiento Mario/Juan, sin asignar oculto, cross-company
  oculto, IDOR detalle 404 (incl. collector_id en request ignorado),
  búsqueda no amplía cartera y usa campos reales, multi-CxC con saldos
  BCMath correctos, exclusión paid/cancelled/0, detalle con pagos fiables,
  snapshots AR/pagos/assignments intactos, permisos/navegación.
- `CobrosCollectorAccessTest` — adaptado a la nueva vista real
  (`branches` se conserva como contrato del shell; 13/13).
- `CobrosAuthenticationTest`, `CobrosCollectorOnboardingTest`,
  `CobrosModuleAccessTest` — actualizados de "Portal del cobrador"
  (pantalla previa) a "Mi cartera" (landing real del cobrador).

**Suite CobrOS completa: 178/178, 1453 aserciones (VERDE).**

**Fuera de alcance (no implementado):** botones Cobrar/Pagar, comprobantes,
tarjetas, impresión, WhatsApp, agenda/visitas/geolocalización, 2B/2C.
Sin migraciones, sin commit, sin push. `tmp_generate_cobros_icons.php` intacto.

## 2B — Agenda diaria y gestión de visitas (2026-09-23) — COMPLETADA / CERTIFICADA.

**Cierre:** validación manual PASS + auditoría final PASS. Tests 2B y suite focal CobrOS verdes. Commit `feat(cobros): complete visits agenda phase 2B`.

**Resumen certificado:**
- Agenda de hoy con acciones Visitado / No estaba / Promesa / Otro día.
- Programación simple desde historial: solo fecha + observación; estado `pendiente` forzado server-side.
- Historial y trazabilidad (`cobros_visit_logs`); reprogramación conserva visita original y crea nueva.
- Aislamiento company/collector, cartera asignada, `collector_id` ignorado, IDOR/cross-company → 404.
- 2B NO modifica AR, saldos ni pagos.

**Alcance entregado (sin tocar dinero):**
- Migración aditiva `2026_09_23_000001_create_cobros_visits_tables.php`:
  `cobros_visits` (company/branch/customer/collector, scheduled_for,
  visited_at, status, promise_date, promise_amount informativo, notes,
  rescheduled_from_visit_id, account_receivable_id nullable) +
  `cobros_visit_logs` (trazabilidad quién/cuándo/de-a qué estado). Modelos
  `CobrosVisit` y `CobrosVisitLog`.
- `Cobros\VisitService`: schedule/record/reschedule/today/historyFor +
  closePendingForCustomer. Autoridad SIEMPRE sesión + auth()->id();
  collector_id del request ignorado; cliente ajeno/sin asignar/cross-company
  y visita ajena → 404. Reprogramación trazada: original queda
  `reprogramada` y nace visita nueva pendiente vinculada. Al reasignar o
  desasignar cartera, las visitas pendientes NO se transfieren: se cierran
  como `reprogramada` con log e historial preservado.
- `VisitController` + rutas CobrOS (`cobros.visits.today`,
  `cobros.visits.customer` GET/POST, `cobros.visits.update` PATCH), todas con
  stack completo + `permission:cobros.cobrar` (reutilizado; NO se creó
  cobros.visitas). Supervisores/dueño: solo lectura de facto (PATCH exige ser
  el cobrador propietario de la visita → 404).
- Vistas mobile-first 360/768/1280: `cobros/visits/today` (agenda de hoy =
  SOLO visitas programadas hoy; botones táctiles ≥44px Visitado/No
  localizado/Promesa/Reprogramar; promesa con fecha obligatoria y monto
  opcional informativo; observaciones) y `cobros/visits/history`
  (programar/registrar gestión + historial cronológico por cliente).
  Enlace "Agenda hoy" en navegación CobrOS y CTA en ficha 2A.
- Estados: pendiente, visitado, no_localizado, promesa_pago, reprogramada.
  2B NO modifica AccountReceivable, balance_due, pagos, asignaciones ni
  ventas; promise_amount es solo dato informativo. Sin cobro real, medios de
  pago, recibos, impresión, envío, GPS/geocercas (2C/3A quedan fuera).

**Pruebas:**
- Nuevo `tests/Feature/CobrosVisitTest.php` — 9/9, 52 aserciones:
  programar con fecha explícita; validaciones (fecha obligatoria, promesa
  exige fecha); agenda de hoy solo visitas de hoy; transición de estados con
  controles rápidos; promesa con monto BCMath; reprogramación trazada con
  bitácora y visita nueva; historial cronológico aislado; IDOR 404
  (cliente/visita ajena, no asignada, cross-company, collector_id ignorado);
  reasignación cierra pendientes sin transferencia silenciosa; permisos y
  snapshots financieros intactos (0 pagos/0 AR/1 asignación).
- Regresión: asignaciones/reasignación de 1E verificadas contra el nuevo
  cierre de visitas pendientes.

**Suite CobrOS completa: 187/187, 1507 aserciones (VERDE).**
`git diff --check` limpio. Sin commit, sin push. `tmp_generate_cobros_icons.php` intacto.

### 2B.1 — Corrección UX de la Agenda (2026-09-23)
- Agenda de hoy reescrita: 4 botones sólidos con identidad CobrOS
  (✓ Visitado, ✕ No estaba, 🤝 Promesa, 📅 Otro día), ≥48px, hover/focus
  visibles. Una decisión = una pantalla: los campos de promesa y
  reprogramación son paneles mutuamente excluyentes que solo aparecen al
  elegir la opción correspondiente (JS mínimo inline).
- Corregido el texto Blade escapado (`@open(... ? true : false)` eliminado).
- Etiquetas humanas en agenda e historial ("No estaba", "Otro día", "Pagará
  el …"); gestión cerrada ya no muestra botones ni formularios, solo estado
  y acceso al historial. Estados de BD y migraciones sin cambios.
- Nuevo `CobrosVisitRenderTest` 4/4: botones presentes y con contraste,
  expresiones Blade crudas prohibidas (regresión), gestión cerrada sin
  formularios, paneles ocultos por defecto.
- Suite CobrOS: 191/191, 1534 aserciones (VERDE).

## 3A — Gate seguro para iniciar cobro (2026-09-29) — COMPLETADA LOCALMENTE

Alcance autorizado: únicamente autorización centralizada y pantalla previa;
sustituye para esta fase la previsión financiera original del cronograma.
Se revisó y completó el trabajo local existente sobre `552a0a6`.

- `CobrosPaymentGate` + `PaymentGateDecision`: usuario activo, empresa activa,
  licencia operable y vigente, módulo CobrOS habilitado, permiso explícito
  `cobros.cobrar` sin herencia por super admin, cartera asignada vigente,
  cliente/cuenta de la empresa, sucursal activa autorizada mediante
  `CollectorAccessService`, saldo positivo BCMath y cuenta no pagada/cancelada.
- GET protegido `cobros.collector.payments.create`; cuenta/cliente ajeno →
  404. Si llega una visita, se valida empresa, cobrador, cliente y vínculo
  opcional a cuenta. Los IDs adicionales no conceden autoridad.
- COBRAR condicionado por el mismo gate en Mi cartera, ficha y agenda.
  Pantalla propia con cliente, saldo a dos decimales, estado cotidiano,
  VOLVER, IR AL CLIENTE si tiene coordenadas y CONTINUAR CON EL COBRO
  deshabilitado. No existe campo fiable de cuota esperada en la cuenta:
  no se inventa ni se confunde con una promesa de visita.
- Revisión conceptual responsive 360/768/1280: columna fluida, ancho máximo,
  nombres/montos ajustables, controles de 44–56px y dorado con texto oscuro.
  No equivale a certificación visual en navegador/dispositivo.
- Auditoría del diff: sin modelos/servicios financieros modificados,
  migraciones ni escrituras de pagos, saldos, estados o caja. No hay POST
  para guardar cobros; pruebas verifican rechazo y snapshots financieros.
- Validación final: **220/220 pruebas, 1679 aserciones**, filtro
  `Cobros|CustomerCompanyIsolation` (18 pruebas nuevas del gate, regresión
  completa CobrOS incluyendo 2A/2B/2C, permisos y aislamiento).
  Pint en archivos nuevos y `git diff --check` correctos.
  3B pendiente y sin iniciar; idempotencia,
  captura financiera y recibos requieren su fase y autorización propias.

## 3B — Cobros reales y recibos (2026-09-30) — COMPLETADA LOCALMENTE

Alcance entregado: pagos parciales/totales con confirmación explícita,
recibos únicos por empresa e idempotencia por token. Sin anulaciones ni
liquidaciones (3C en adelante). Sin push/merge/deploy.

- **Migraciones aditivas:** `2026_09_30_000001` hace nullable
  `accounts_receivable_payments.cash_session_id` (custodia sin caja) y
  `2026_09_30_000002` crea `cobros_receipts` con UNIQUE
  `(company_id, client_token)`, `(company_id, receipt_number)` y
  `payment_id` único.
- **`Cobros\SecurePaymentService`:** reutiliza el Gate 3A (re-evaluado en
  servidor antes de escribir) y `AccountsReceivableService::payInCustody()`
  (nuevo método que comparte el mismo cuerpo transaccional/BCMath de
  `pay()`, solo sin sesión de caja). Orden: resolución de idempotencia
  primero → Gate → validaciones → transacción. Mismo token + mismo payload
  devuelve el mismo pago/recibo; token con payload distinto (o de otro
  cobrador) → 409; carrera concurrente cubierta por la restricción única y
  resuelta como replay o 409. Montos `\d+(\.\d{1,4})?` + BCMath, límite contra
  saldo, métodos de la empresa activos y sin crédito/puntos, referencia
  obligatoria si el método la exige, visita revalidada por empresa/cobrador/
  cliente.
- **Custodia explícita:** el pago se registra con `cash_session_id = null`,
  `affects_cash_snapshot = false` y `cash_effect_amount = 0`; ninguna caja
  recibe efectivo automáticamente y las expectativas de cierre existentes
  (filtradas por sesión) no cambian. El dinero queda en custodia del
  cobrador hasta una liquidación futura (3C+); así lo dice el recibo.
- **Recibos:** `CompanySequence::COBROS_RECEIPT` → `RCB-%08d` único por
  empresa; pantalla de recibo con detalle, historial paginado propio y
  acceso del dueño (`cobros.portal_dueno`) al recibo de su empresa; las
  rutas exigen `cobros.cobrar` y todo recurso ajeno/cross-company → 404.
- **UI CobrOS:** `payment-create` pasó de pantalla previa bloqueada a captura
  (monto parcial/total, forma de pago, referencia condicionada, nota, token
  oculto) + confirmación en servidor con detalle (`confirm=1`): sin
  confirmación no hay escritura alguna. Vista de recibo (Imprimir/Compartir)
  e historial; enlace "Mis recibos" en la navegación fuente CobrOS.
  Mobile-first, controles ≥44px, dorado oficial `#D4AF37`.
- **Pruebas:** nuevo `tests/Feature/CobrosPaymentTest.php` — **14/14, 91
  aserciones**: parcial, total, monto mayor al saldo, replay idéntico → un
  solo pago, payload distinto → 409, sin confirmación → cero escrituras,
  permiso `cobros.cobrar`, cuenta/recibo cross-company → 404, método
  inválido/ajeno/referencia faltante, números `RCB-00000001/2` por empresa,
  Gate re-evaluado antes de escribir, historial/detalle aislados, dueño con
  portal_dueno y snapshots financieros/cash intactos.
- **Validación final:** suite focal `Cobros|CustomerCompanyIsolation` →
  **234/234, 1772 aserciones (VERDE)**; Pint aplicado a los archivos PHP
  nuevos/modificados de 3B y `git diff --check` limpio. `CobrosPaymentGateTest`
  solo adaptó la expectativa del CTA (`REVISAR COBRO` sustituye al botón
  deshabilitado de 3A). Sin validación visual en navegador ni build Vite.
  `tmp_generate_cobros_icons.php` y los `tmp_*` quedan fuera del commit.

## 3C — Auditoría financiera y anulaciones (2026-09-30) — COMPLETADA LOCALMENTE

Alcance entregado: auditoría completa de cobros, anulaciones seguras y
trazabilidad inmutable. Sin liquidaciones de efectivo ni Fase 4.

- **Anulación con reverso:** `Cobros\CobrosAnnulmentService` nunca borra ni
  modifica `accounts_receivable_payments` (test con snapshot byte-idéntico):
  crea `cobros_payment_reversals` (`RVC-%08d` por empresa) con monto, saldo
  antes/después, motivo, cobrador original y anulador. `UNIQUE(payment_id)`
  + revisión con `lockForUpdate` dentro de la transacción bloquean cualquier
  doble anulación → 409.
- **Idempotencia de anulación:** `client_token` único por empresa; mismo
  token + mismo payload devuelve el mismo reverso (flash `cobros_reversal`),
  payload distinto o de otro actor → 409; carrera cubierta también por la
  restricción única. Motivo obligatorio (8–250 caracteres).
- **Saldo y concurrencia:** cuenta bloqueada con `forCompany` + `forBranch` +
  `lockForUpdate`, devolución con BCMath sin exceder `original_amount` y
  estado recalculado (pending/partial/overdue). Recibo pasa a
  `annulled_at/annulled_by/annul_reason` sin tocar su contenido original.
- **Custodia y caja intactos:** pago con sesión de caja → 409 (nunca se anula
  nada de Commerce); reverso no crea movimientos ni sesiones; recibo conserva
  su token/payload_hash y un reintento del cobro devuelve el mismo recibo sin
  pagos nuevos.
- **Auditoría con `RouteosAuditService` (reutilizado):**
  `cobros.payment.registered` (en la misma transacción del cobro) y
  `cobros.payment.annulled` con actor, empresa, sucursal, entidad, saldos y
  metadata (recibo/reverso/pago/monto/motivo) y **sin tokens**.
- **Permiso dedicado `cobros.anular`:** en `PermissionSeeder` + provisioning
  aditivo `2026_09_30_000005` (solo roles con `cobros.portal_dueno` o
  `cobros.supervisar`, nunca Cobrador). Ruta anular con
  `permission:cobros.anular`; cobrador → 403. Formulario de anulación solo en
  el recibo y solo si el pago no está anulado.
- **Consulta de auditoría:** `CobrosAuditController` (solo lectura, por
  empresa) en `cobros.owner.audit` (portal_dueno) y `cobros.supervisor.audit`
  (supervisar) con vista mobile-first `cobros/owner/audit.blade.php` y enlace
  "Auditoría" en la navegación fuente. Visibilidad del recibo ampliada a
  dueño o titular de `cobros.anular` (ruta ahora exige `cobros.acceder` y el
  controlador aplica dueño/propietario/anulador → 404 en otro caso).
- **Migraciones aditivas:** `2026_09_30_000003` (`cobros_payment_reversals`),
  `2026_09_30_000004` (campos de anulación en `cobros_receipts`) y
  `2026_09_30_000005` (permiso). Ninguna destructiva.
- **Pruebas:** nuevo `tests/Feature/CobrosPaymentAnnulmentTest.php` —
  **11/11, 97 aserciones**: reverso + saldo + pago intacto, permiso, doble
  anulación 409, replay idéntico, payload distinto 409, motivo, cross-company
  404, pago total restaurado, auditoría con actor/empresa sin tokens, custodia
  e idempotencia intactas y pantalla de auditoría acotada/permisada.
- **Validación final:** suite focal `Cobros|CustomerCompanyIsolation` →
  **245/245, 1873 aserciones (VERDE)**; Pint limpio en los archivos PHP
  nuevos/de 3C (`CompanySequence` conserva 1 deuda de estilo preexistente en
  la base) y `git diff --check` limpio. Sin validación visual en navegador ni
  build Vite; `tmp_*` fuera del commit.

## 4A — Panel operativo del supervisor (2026-09-30) — COMPLETADA LOCALMENTE

Alcance entregado: supervisión operativa diaria en solo lectura. Sin
reasignaciones nuevas, sin reportes históricos ni Fase 4B.

- **`SupervisorPanelService` (solo lectura):** consolida cartera por
  cobrador (clientes asignados de `cobros_assignments`, saldo real de CxC y
  cobrado hoy), agenda del día con conteos por estado, incidencias (no
  localizados hoy y promesas vencidas), recibos y anulaciones de hoy y los
  últimos 5 eventos de auditoría. Reutiliza `CollectorManagementService::
  listCollectors` y `PortfolioSummaryService` (su `decimalSum` BCMath pasó a
  público); no crea reglas financieras nuevas ni duplica saldos.
- **Filtro por sucursal:** GET `branch_id` validado contra la empresa activa
  y contra el alcance del supervisor (`filterableBranches`: sus sucursales
  vinculadas; sin vínculo explícito, toda la empresa activa) → fuera de
  alcance 404. Cada consulta sigue acotada por `company_id`.
- **Permisos/tenant:** ruta única `cobros.supervisor.index` con
  `permission:cobros.supervisar` (antes placeholder de 4A) + stack CobrOS;
  dueño/supervisor/cobrador sin el permiso → 403; el panel no ofrece
  funciones de dueño (`owner.*` → 403 para el supervisor) ni acciones
  financieras (sin POST, sin escrituras).
- **UI:** `resources/views/cobros/supervisor/panel.blade.php` mobile-first,
  dorado oficial, targets ≥44px, tarjetas de indicadores, cartera por
  cobrador, agenda/incidencias, recibos-anulaciones, auditoría reciente con
  enlace a la auditoría completa 3C. Filtro de sucursal con formularo GET.
- **Pruebas:** nuevo `tests/Feature/CobrosSupervisorPanelTest.php` —
  **8/8, 58 aserciones**: panel completo con cobro y anulación reales,
  alcance del filtro por sucursal (incl. ajena a empresa → 404), alcance de
  sucursales del supervisor, permisos, ausencia de funciones de dueño,
  solo-lectura vía query log + snapshot financiero y aislamiento multiempresa,
  estados vacíos y enlaces confines `/cobros`.
- **Adaptación mínima de tests 2A/1E:** dos aserciones de
  `CobrosCollectorAccessTest` que esperaban el placeholder "Disponible
  próximamente" ahora esperan "Panel del supervisor" (el resto intacto).
- **Validación final:** suite focal `Cobros|CustomerCompanyIsolation` →
  **253/253, 1932 aserciones (VERDE)**; Pint limpio en archivos 4A y
  `git diff --check` limpio. Sin migraciones, sin visual en navegador ni
  build Vite; `tmp_*` fuera del commit.

## 4B — Panel avanzado del dueño (2026-09-30) — COMPLETADA LOCALMENTE

Alcance entregado: reportes del dueño con período y sucursal. Sin
exportación de archivos, sin comisiones ni Fase 5.

- **Ruta/vista:** `cobros.owner.reports` (`permission:cobros.portal_dueno`) +
  `resources/views/cobros/owner/reports.blade.php` mobile-first, con enlace
  "Resultados" en la navegación fuente (evita las etiquetas reservadas del
  shell). Solo lectura: sin POST y sin escrituras financieras.
- **`OwnerReportService`:** reutiliza `PortfolioSummaryService::summarize`
  (consistencia exacta con el dashboard 1C y su `decimalSum` BCMath) y
  `CollectorManagementService::listCollectors`. Entrega: cobrado del
  período (monto, pagos, clientes atendidos), recibos, anulaciones y
  eventos de auditoría del período; ranking de cobradores ordenado por
  cobrado (cobrado, anulado, saldo en cartera, recibos, clientes
  asignados); antigüedad de vencimientos (Vigente / 1-30 / 31-60 / 61-90 /
  +90 con monto y cantidad); custodia pendiente neta (pagos sin sesión de
  caja − sus reversos) y cartera total consistente con 1C.
- **Filtros:** sucursal (validada contra `company_id` vía `summarize` → 404
  si es de otra empresa o desconocida) y período `from`/`to` (`date_format
  Y-m-d`, `after_or_equal`, por defecto primer día del mes → hoy en
  timezone de la empresa). Cada consulta acotada por `company_id`.
- **Permisos:** solo dueño (`cobros.portal_dueno`); supervisor/cobrador →
  403; sin acciones de escritura (cobros y anulaciones siguen en 3B/3C con
  sus propios permisos).
- **Pruebas:** nuevo `tests/Feature/CobrosOwnerReportsTest.php` —
  **8/8, 88 aserciones**: “¿cuánto recuperó cada cobrador este mes?” con
  suma consistente y ranking, filtros de sucursal/período (ajena → 404,
  inválidos → errores), cinco cubos de antigüedad, custodia neta tras
  anulación, contadores de recibos/anulaciones/auditoría, permisos,
  solo-lectura vía query log + snapshot y aislamiento multiempresa, y
  consistencia 1C vs 4B (`total_due`/`total_overdue` idénticos).
- **Validación final:** suite focal `Cobros|CustomerCompanyIsolation` →
  **261/261, 2022 aserciones (VERDE)**; Pint limpio en archivos 4B y
  `git diff --check` limpio. Sin migraciones, sin visual en navegador ni
  build Vite; `tmp_*` fuera del commit.

**Pendiente declarado de 4B:** exportación de resultados (CSV/imprenta)
según la arquitectura de exportación del proyecto — fuera de este alcance.

## Fase 5A — Notificaciones y alertas CobrOS (2026-09-30) — COMPLETADA LOCALMENTE

Alcance entregado: centro de notificaciones con el canal `CobrOS` y los
6 tipos (`payment_registered`, `payment_annulled`, `promise_overdue`,
`customer_not_found`, `custody_pending`, `license_expiring`), permiso
`notificaciones.cobros`, ámbito empresa y deduplicación en `AlertDispatcher`.

- **Panel de alertas:** `CobrosAlertController` (`cobros.alerts`) en solo
  lectura para dueño/supervisor, sin escrituras financieras (query log y
  `financialSnapshot` verificados).
- **Comando:** `cobros:evaluate-alerts` para evaluación programada.
- **Reglas:** permiso `notificaciones.cobros` + rol CobrOS; una preferencia
  nunca concede acceso, solo filtra; enero recibo/alerta no escribe dinero.

**Evidencia:** `CobrosAlertsTest` 9/9 (87 aserciones) y suite focal
`Cobros|CustomerCompanyIsolation` **270/270, 2117 aserciones (VERDE)**;
Pint limpio y `git diff --check` correctos. Sin migraciones, sin visual en
navegador ni build Vite. `tmp_*` fuera del commit.

**Siguiente bloque:** 5B (PWA móvil del cobrador y conectividad), según
`docs/CRONOGRAMA_COBROS.md`.

## Fase 5B — PWA móvil del cobrador y conectividad (2026-10-01) — COMPLETADA LOCALMENTE

Alcance entregado: PWA instalable de CobrOS, estado de conexión visible y
tolerancia a señal deficiente. Sin GPS, sin mapas offline y sin pagos
offline.

- **Service worker propio** `public/sw-cobros.js`: scope estricto `/cobros`
  (jamás intercepta rutas de Commerce), caché del shell estático, estado
  `self.cobrosOnline` y receptáculo de borradores en IndexedDB. El
  `fetch` NO responde a POST/pagos: las escrituras financieras siguen
  siendo exclusivas del servidor vía 3A/3B.
- **Cliente PWA** `resources/js/cobros-pwa.js`: registro del SW solo bajo
  `/cobros`, `beforeinstallprompt` (botón de instalación oculto hasta que
  el navegador lo ofrezca) y seguimiento `online`/`offline` con
  `window.cobrosOnline`.
- **Banner de conexión** `resources/views/cobros/components/connection.blade.php`
  incluido en `cobros/layout.blade.php`: aviso móvil-safe "Sin conexión"
  con la regla explícita de que los cobros se aplican en línea con
  confirmación del servidor. Nunca muestra éxito sin respuesta del
  servidor (no se replica ningún `assertRedirect` localmente).
- **Sin nuevas dependencias ni migraciones**; reutiliza identidad 1B
  (manifest, iconos, `cobros-shell.css`, navegación fuente) y cierra su
  pendiente de build/instalación solo en la parte ejecutable en este
  worktree.

**Evidencia:** suite focal `Cobros|CustomerCompanyIsolation` →
**270/270, 2117 aserciones (VERDE)**; `CobrosManifestTest` 1/1 (30
aserciones); `CobrosOwnerDashboardTest` 9/9 (81 aserciones); `php -l` en
Blade y `node --check` en `cobros-pwa.js`/`sw-cobros.js` limpios;
`git diff --check` limpio.

**Validaciones pendientes declaradas:** build Vite (el worktree no tiene
`node_modules` y no se instalan dependencias sin autorización),
instalación PWA en dispositivo real y revisión visual en navegador 360/
768/1280. Fallos preexistentes no atribuibles: `CobrosCollectorManagementTest`
(fatal de proceso PHP) y el logo de `ResponsiveNavigationTest`, ambos
documentados antes de 5B. `tmp_*` y `output.txt` fuera del commit.

**Siguiente bloque:** 5C (Seguridad endurecida), según
`docs/CRONOGRAMA_COBROS.md`.

## Fase 5C — Seguridad endurecida (2026-10-01) — COMPLETADA LOCALMENTE (alcance acotado)

Alcance entregado: rate limiting en rutas financieras, revisión
CSRF/sesión, casos negativos de revocación y endurecimiento de lo
entregado en 5B. Sin migraciones.

- **Rate limiting (reutiliza `throttle` del stack web):**
  `cobros.collector.payments.store` → `throttle:30,1`;
  `cobros.collector.payments.annul` → `throttle:10,1`;
  `cobros.password.force.store` → `throttle:10,1` (login ya tenía
  `throttle:10,1`). Las rutas financieras conservan `permission:*` +
  stack `web` (CSRF/sesión) — verificado por recolección de middleware.
- **Casos negativos (nuevo `tests/Feature/CobrosSecurityHardeningTest.php`
  — 6 pruebas, 66 aserciones):** 11.º intento de anulación → 429 sin
  llegar al controlador y sin reversos; escritura financiera sin token
  CSRF → 419 con cero pagos y con token → pasa al flujo normal;
  revocaciones (usuario inactivado, rol desactivado, permiso desprendido,
  módulo deshabilitado, licencia suspendida) → 403/`license.status` con
  saldo intacto; sesión con empresa ajena → corrige contexto y cobro
  contra cuenta ajena → 404 sin escrituras.
- **Revisión 5B (mismo archivo de pruebas):** `sw-cobros.js` precachea
  SOLO manifest + iconos públicos (sin HTML autenticado, sin `cache.put`
  de respuestas dinámicas); borradores con `owner` obligatorio
  (`usuario:empresa` desde `data-cobros-owner` del layout) y
  `cobros:clear-drafts` al enviar el logout; scrub de credenciales
  (`_token`, `password`, `authorization`, `cookie`…) con `client_token`
  de idempotencia preservado; ni SW ni cliente referencian
  `payments.store` (cero confirmación offline).
- **Endurecimiento del SW (corrección sobre 5B):** precache raíz errónea
  `/cobros/...` sustituida por rutas públicas reales; página honesta
  "Sin conexión" ante fallo de red en navegación; activación limpia de
  caches de versiones anteriores.

**Evidencia:** suite focal `Cobros|CustomerCompanyIsolation` →
**276/276, 2183 aserciones (VERDE)** tras Pint; `CobrosSecurityHardeningTest`
6/6 (66 aserciones). Dependencias declaradas instaladas desde lockfiles
(sin actualizar versiones): `composer install` (habilitó Pint) y
`npm ci` + `npm run build` → **build Vite correcto** (cierra el pendiente
 declarado de 5B). Pint limpio en archivos cambiados; `git diff --check`
limpio; `node --check` limpio en `sw-cobros.js`/`cobros-pwa.js`.

**Validaciones pendientes declaradas:** política de dispositivos del
cobrador y retención/geolocalización (requieren definición de producto;
sin migración autorizada en 5C), instalación PWA en dispositivo real y
revisión visual 360/768/1280. Fallo preexistente ajeno:
`CobrosCollectorManagementTest` (fatal de proceso PHP). `tmp_*` y
`output.txt` fuera del commit.

**Siguiente bloque:** 6A (Pruebas integrales), según
`docs/CRONOGRAMA_COBROS.md` — requiere autorización explícita.

## Fase 6A — Pruebas integrales / cierre técnico (2026-10-01) — COMPLETADA LOCALMENTE

Alcance: certificar CobrOS de punta a punta antes de 6B, sin ampliar
funcionalidad y sin tocar producción.

- **Prueba integral nueva** `tests/Feature/CobrosEndToEnd6ATest.php`
  (3 pruebas, 94 aserciones) que recorre el flujo real por HTTP:
  login CobrOS de dueño/supervisor/cobrador → asignación de cartera →
  agenda y visita del día → pago parcial (RCB-00000001) → pago total
  (RCB-00000002) → recibo → anulación (RVC-00000001) → reportes del
  dueño y panel del supervisor → alertas.
- **Idempotencia**: el mismo `client_token` con el mismo payload devuelve
  el mismo pago y el mismo recibo (1 pago / 1 recibo tras el reintento);
  la anulación repetida no duplica el reverso.
- **Aislamiento**: empresa ajena 404 sobre el recibo; cartera ajena 404
  en ficha y al cobrar (cero escrituras); sucursal inexistente 404 en
  el panel del supervisor; dueño de otra empresa no alcanza pagos ajenos.
- **Roles**: dueño ve panel/reportes/auditoría/alertas; supervisor ve
  panel y alertas; cobrador recibe 403 en funciones de dueño y supervisor.
- **Caja intacta**: `cash_sessions` y `cash_movements` en cero; cada pago
  con `cash_session_id` null, `affects_cash_snapshot` false y
  `cash_effect_amount` 0.0000 (custodia pendiente, sin ingreso a caja).
- **Sin confirmación no hay dinero**: el envío sin `confirm=1` devuelve la
  vista de confirmación en servidor y cero pagos/recibos.
- **Revocaciones**: rol sin `cobros.cobrar` 403 y usuario desactivado 403
  con cero escrituras; al reactivarlo el flujo normal vuelve a funcionar.
- **Migraciones**: las 6 pendientes (3B/3C) aplicadas sobre una **copia**
  de la base local (`tmp_6a_migrate_check.sqlite`), resultado 0
  pendientes y datos preexistentes intactos (7 usuarios, 4 clientes,
  2 empresas, permiso `cobros.anular` creado). No se ejecutó
  `migrate` sobre la base de revisión ni sobre la maestra.
- **Suite**: `Cobros|CustomerCompanyIsolation` **279/279, 2277
  aserciones (VERDE)**; `CobrosGeoTest|CobrosManifestTest|CobrosAuthenticationTest`
  28/28 (272 aserciones) para GPS, manifest y PWA; sin regresiones en la
  suite global de CobrOS.
- **Build**: `npm ci`/`npm run build` correctos (5.88 s); `node --check`
  en `cobros-pwa.js` y `sw-cobros.js`; Pint PASS en el archivo nuevo;
  `git diff --check` limpio.
- **No comprobado (declarado)**: doble clic y concurrencia real de varios
  usuarios en paralelo sobre la misma cuenta; pérdida de conexión real en
  dispositivo; instalación PWA en teléfono; revisión visual 360/768/1280
  en navegador real; auditoría externa de 6A.
- **Fallos preexistentes ajenos** (fuera de CobrOS, no tocados):
  `InventoryImportHardeningTest` (`InventoryPostingService::postImportMovement`
  inexistente), `PosAccessAndSearchTest`, bloque de fidelización/ventas y
  logo de `ResponsiveNavigationTest`; la suite global agota el límite de
  memoria de 128 MB de PHP y requiere `-d memory_limit=1G`.
- Temporales `output.txt` y `tmp_*` preservados fuera del commit.
