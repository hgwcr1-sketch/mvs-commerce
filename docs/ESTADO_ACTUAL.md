# MVS Commerce — Estado actual

Documento corto de relevo entre agentes. Actualizar al terminar cada tarea importante.

## Worktree CobrOS — Fase 4B COMPLETADA LOCALMENTE (2026-09-30)

Base `feature/cobros-v1`, `6530659`. Panel avanzado del dueño
(`cobros.owner.reports` + `OwnerReportService`, solo lectura, permiso
`cobros.portal_dueno`): cobrado del período, recibos, anulaciones y
auditoría; ranking de cobradores (cobrado/anulado/saldo/recibos/clientes);
antigüedad de vencimientos (5 cubos); custodia pendiente neta (pagos sin
sesión de caja − reversos); cartera consistente con 1C. Filtros de sucursal
(validada por `company_id` → 404) y período `from`/`to` por defecto mes en
curso. Reutiliza `PortfolioSummaryService::summarize`/`decimalSum` y
`CollectorManagementService::listCollectors`; sin escrituras (query log y
snapshots verificados). Enlace “Resultados” en la navegación fuente.
Validación: `CobrosOwnerReportsTest` 8/8 (88 aserciones) y suite focal
`Cobros|CustomerCompanyIsolation` **261/261, 2022 aserciones**; Pint y
`git diff --check` correctos. Sin migraciones ni build.
**Pendiente 4B:** exportación según arquitectura (no autorizada aquí).

## Worktree CobrOS — Fase 5A COMPLETADA LOCALMENTE (2026-09-30)

Base `feature/cobros-v1`, `6f845d8`. Centro de Notificaciones: canal
CobrOS con los 6 tipos (`cobros.payment_registered`, `payment_annulled`,
`promise_overdue`, `customer_not_found`, `custody_pending`,
`license_expiring`), permiso `notificaciones.cobros`, ámbito empresa y
deduplicación en `AlertDispatcher`, `AlertTypeRegistry` y
`NotificationPreferenceService`. Panel de alertas (`CobrosAlertController`,
`cobros.alerts`) sola lectura para dueño/supervisor, sin escrituras
financieras (query log y `financialSnapshot` verificados).

- Comando `cobros:evaluate-alerts` para evaluación programada.
- Pruebas de integración (canal, permisos, preferencias y pantalla).

Validación: `CobrosAlertsTest` 9/9 (87 aserciones) y suite focal
`Cobros|CustomerCompanyIsolation` **270/270, 2117 aserciones**; Pint y
`git diff --check` correctos. Sin migraciones ni build.

**Pendiente 5B:** PWA móvil del cobrador y conectividad (según
`docs/CRONOGRAMA_COBROS.md`).

## Worktree CobrOS — Fase 5B COMPLETADA LOCALMENTE (2026-10-01)

Base `feature/cobros-v1`, `390d891`. PWA móvil del cobrador y
conectividad: service worker propio `public/sw-cobros.js` con scope
estricto `/cobros` (nunca toca Commerce), registro + `beforeinstallprompt`
en `resources/js/cobros-pwa.js`, banner de conexión móvil-safe en
`cobros/layout.blade.php` y borradores idempotentes locales (IndexedDB)
sin aplicación offline de pagos. El cobro SOLO se confirma en línea vía
3A/3B; nunca se muestra éxito sin confirmación del servidor. Sin
migraciones ni dependencias nuevas; reutiliza identidad 1B (manifest,
iconos, `cobros-shell.css`, navegación fuente).

Validación: suite focal `Cobros|CustomerCompanyIsolation` **270/270, 2117
aserciones**; `CobrosManifestTest` 1/1; `CobrosOwnerDashboardTest` 9/9;
`php -l` + `node --check` + `git diff --check` limpios. Pint no disponible
(binario ausente; composer declara `laravel/pint` sin instalar).
**Pendientes 5B declarados:** build Vite (worktree sin `node_modules`, no
se instalan dependencias sin autorización), instalación PWA en dispositivo
real y revisión visual 360/768/1280. Fallos preexistentes ajenos:
`CobrosCollectorManagementTest` (fatal de proceso) y logo de
`ResponsiveNavigationTest`. 5C pendiente y sin iniciar. Temporales
`tmp_*` y `output.txt` preservados fuera del commit.

## Worktree CobrOS — Fase 5C COMPLETADA LOCALMENTE (2026-10-01)

Base `feature/cobros-v1`, `4f4eb03`. Seguridad endurecida con alcance
acotado: rate limiting en rutas financieras (`payments.store` 30/min,
`payments.annul` 10/min, `password.force.store` 10/min), CSRF verificado
en escritura financiera (419 sin token, cero pagos) y casos negativos de
revocación (usuario/rol/permiso/módulo/licencia → 403 o `license.status`
sin escrituras) en nuevo `CobrosSecurityHardeningTest` (6/6, 66
aserciones). Revisión 5B endurecida: SW precachea solo assets públicos
(cero HTML autenticado/caché de respuestas), borradores aislados por
owner `usuario:empresa` y borrados al logout, credenciales nunca
persistidas con `client_token` de idempotencia conservado y ninguna
referencia offline a `payments.store`.

Validación: suite focal `Cobros|CustomerCompanyIsolation` **276/276,
2183 aserciones** tras Pint; dependencias declaradas instaladas desde
lockfiles sin actualizar versiones (`composer install` habilitó Pint;
`npm ci` + `npm run build` → build Vite correcto, cierra el pendiente
de build de 5B); Pint, `node --check` y `git diff --check` limpios.
**Pendientes 5C declarados:** política de dispositivos del cobrador y
retención geográfica (definición de producto, sin migración autorizada),
instalación PWA en dispositivo y revisión visual 360/768/1280.
Temporales `tmp_*` y `output.txt` preservados fuera del commit.

## Worktree CobrOS — Fase 6A COMPLETADA LOCALMENTE (2026-10-01)

Base `feature/cobros-v1`, `0b28ac3`. Cierre técnico sin ampliar
funcionalidad: nueva prueba integral `CobrosEndToEnd6ATest` (3/3, 94
aserciones) que recorre login por rol → asignación → cartera → visita →
pago parcial/total → recibo → anulación → reportes y alertas, con
idempotencia por `client_token`, aislamiento por empresa/sucursal/rol/
cartera, permisos por rol, revocaciones con cero escrituras y caja
intacta (`cash_sessions`/`cash_movements` en cero, custodia pendiente).

Validación: `Cobros|CustomerCompanyIsolation` **279/279, 2277
aserciones**; `CobrosGeoTest|CobrosManifestTest|CobrosAuthenticationTest`
28/28; migraciones 3B/3C aplicadas sobre **copia** de la base local
(6 pendientes → 0, datos intactos, sin `migrate` sobre la base de
revisión); `npm run build` OK; Pint PASS; `node --check` y
`git diff --check` limpios. La suite global necesita
`-d memory_limit=1G` en este entorno.

**Pendientes 6A declarados** (para 6B): auditoría externa/revisión por
auditor, concurrencia real y doble clic sobre la misma cuenta, pérdida
de conexión real en dispositivo, instalación PWA en teléfono y revisión
visual 360/768/1280; más los pendientes heredados de 5B/5C. Servidor
local de revisión activo en `http://127.0.0.1:8000/cobros/login`.
6B no iniciado. Temporales `tmp_*` y `output.txt` preservados fuera del
commit.

## Worktree CobrOS — Fase 4A COMPLETADA LOCALMENTE (2026-09-30)

Base `feature/cobros-v1`, `f556650`. Panel operativo del supervisor en solo
lectura (`cobros.supervisor.index` + `SupervisorPanelService`): cartera por
cobrador (asignaciones, saldo CxC, cobrado hoy), agenda del día e
incidencias (no localizados, promesas vencidas), recibos y anulaciones de
hoy, últimos eventos de auditoría con enlace a la auditoría 3C y filtro por
sucursal validado contra empresa y alcance del supervisor (fuera → 404).
Reutiliza `CollectorManagementService::listCollectors` y
`PortfolioSummaryService::decimalSum` (ahora público); sin reglas
financieras nuevas ni escrituras (query log y snapshots verificados).
Adaptadas dos aserciones del placeholder en `CobrosCollectorAccessTest`.
Validación: `CobrosSupervisorPanelTest` 8/8 (58 aserciones) y suite focal
`Cobros|CustomerCompanyIsolation` **253/253, 1932 aserciones**; Pint y
`git diff --check` correctos. Sin migraciones ni build.
4B pendiente y sin iniciar. Temporales `tmp_*` preservados fuera del commit.

## Worktree CobrOS — Fase 3C COMPLETADA LOCALMENTE (2026-09-30)

Base `feature/cobros-v1`, `b3d725f`. Auditoría completa, anulaciones seguras
y trazabilidad inmutable: `Cobros\CobrosAnnulmentService` crea reverso
`RVC-` (nunca borra ni modifica el pago original; `UNIQUE(payment_id)` +
locks bloquean dobles anulaciones → 409), idempotencia por `client_token`
(mismo payload → mismo reverso; distinto → 409), motivo obligatorio,
redevolución BCMath del saldo con empresa/sucursal bloqueadas, recibo con
`annulled_at/by/reason`, auditoría vía `RouteosAuditService`
(`cobros.payment.registered`/`annulled`, con actor/empresa y sin tokens),
permiso dedicado `cobros.anular` (seeder + provisioning `2026_09_30_000005`,
solo dueño/supervisor) y consulta de auditoría `owner.audit`/
`supervisor.audit`. Migraciones aditivas `..._000003` y `..._000004`.
Validación: `CobrosPaymentAnnulmentTest` 11/11 (97 aserciones) y suite focal
`Cobros|CustomerCompanyIsolation` **245/245, 1873 aserciones**; Pint limpio en
archivos 3C y `git diff --check` correcto. Sin visual/build.
Fase 4 pendiente y sin iniciar. Temporales `tmp_*` preservados fuera del commit.

## Worktree CobrOS — Fase 3B COMPLETADA LOCALMENTE (2026-09-30)

Base `feature/cobros-v1`, `751a363`. Cobros parciales/totales y recibos en
CobrOS: `Cobros\SecurePaymentService` (Gate 3A + idempotencia `client_token`,
mismo payload → mismo pago, distinto → 409, carrera vía UNIQUE),
`AccountsReceivableService::payInCustody()` (mismo cuerpo transaccional de
`pay()` sin sesión de caja: `cash_session_id=null`, `affects_cash_snapshot=false`,
efectivo en custodia del cobrador, nunca ingresado a caja), migraciones
aditivas `2026_09_30_000001` (cash_session nullable) y `2026_09_30_000002`
(`cobros_receipts` + `RCB-%08d` por empresa), captura + confirmación en
servidor, vista de recibo, historial y enlace "Mis recibos".
Validación: `CobrosPaymentTest` 14/14 (91 aserciones) y suite focal
`Cobros|CustomerCompanyIsolation` **234/234, 1772 aserciones**; Pint en
archivos 3B y `git diff --check` limpio. Sin validación visual ni build.
3C (anulaciones/auditoría) pendiente y sin iniciar. Temporales
`tmp_gate_result.txt`, `tmp_gate_summary.txt` y `tmp_generate_cobros_icons.php`
preservados fuera del commit.

## Worktree CobrOS — Fase 3A COMPLETADA LOCALMENTE (2026-09-29)

Base `feature/cobros-v1`, `552a0a6`. Se completó la implementación local
preexistente del gate: `CobrosPaymentGate`, decisión inmutable, GET de pantalla
previa y botones condicionados en cartera/ficha/agenda. Validación final:
220/220 pruebas, 1679 aserciones (`Cobros|CustomerCompanyIsolation`), Pint
en archivos nuevos y `git diff --check` correctos; sin prueba visual real.
Reutiliza identidad, permisos, cartera y CollectorAccessService; exige permiso
explícito incluso para roles super admin, vigencia, asignación y sucursal.
La URL con visita exige empresa, cobrador, cliente y cuenta coherentes.
Solo lectura financiera; continuar deshabilitado, sin POST de pagos.
3B pendiente, sin iniciar. Temporales preexistentes preservados fuera del commit.

## Worktree CobrOS — FASE 1E-C COMPLETADA / CERTIFICADA (2026-09-23)

**FASE 1E-C = COMPLETADA / CERTIFICADA.** Asignación de cartera por CLIENTE
(`cobros_assignments` UNIQUE company+customer, log append-only
`cobros_assignment_logs`, `PortfolioAssignmentService`, permiso `cobros.asignar`,
pantalla Owner/Supervisor mobile-first). CxC/saldos/pagos solo lectura.

- **Validación manual:** Andrés 200 / cobrador 403; ciclo completo
  Sin asignar → Mario Vega → Juan Prueba → Sin asignar; saldos
  ₡25,000.00 y ₡40,000.00 intactos; cliente 2 sin asignar; filtros y
  Asignar/Reasignar con combobox buscable (IDs internos en hidden).
- **Auditoría final:** PASS (migraciones aditivas, tenant, permisos,
  transacción/unique/locking, logs, UI, sin escritura financiera).
- **Suite Cobros:** **168/168, 1408 aserciones.**
- **Riesgos no bloqueantes:** `catch (QueryException)` amplio en assign;
  deuda `is_super_admin` hereda `cobros.asignar`; huecos test filtro
  `branch_id` / cobrador inactivo en lista.
- Provisioning `2026_09_22_000003` aditivo e idempotente; solo roles
  `portal_dueno`/`supervisar` (nunca Cobrador). `tmp_generate_cobros_icons.php`
  fuera del commit. Sin push/merge/deploy/2A.

**Hallazgo entorno:** hay DOS `php -S` en `127.0.0.1:8000` — PID 5588
(worktree cobros) y PID 13808 (worktree main, APP_KEY y SQLite distintas).
La main no tiene ruta `owner/assignments` (sería 404, no 403 con ese
mensaje). Requests `/cobros/*` han respondido siempre desde 5588. No
prohibido; si el navegador da 404 intermitente, cerrar el servidor main
stray.

## Worktree CobrOS — FASE 1E-B COMPLETADA / CERTIFICADA (2026-09-22)

**FASE 1E-B = COMPLETADA / CERTIFICADA.** Auditoría CLINE PASS; validación manual
individual + company_cupo PASS (cupo 1→2/3, contraseñas, WhatsApp, aislamiento).
Suite final Cobros: **145/145, 1330 aserciones**. Commit local
`fix(cobros): isolate tenant flow and close collector management` sobre
baseline `f9124be`. Sin push/merge/deploy; `tmp_generate_cobros_icons.php` fuera.
Deuda futura NO bloqueante: `is_super_admin` puede heredar `cobros.cobrar`
y afectar conteo de cupos — revisar después, sin modificar aún.

## Worktree CobrOS — recuperación post-apagado 1E-B (2026-09-22)

Rama `feature/cobros-v1`; HEAD previo `f9124be`.
Causa del 404 `/cobros/login`: servidor caído (tras apagado); rutas correctas.
Caches `route/config/view/cache` limpias; `GET/POST /cobros/login` = 200/419
(CSRF) sin auth ni `active.company`/`cobros.active.company` en login.
Aislamiento `cobros.active.company` aplicado (alias + middleware + rutas).
Fix `@else` en `platform/show.blade.php` aplicado.

## Worktree CobrOS — 1E-B completada (2026-09-20)

En este worktree la rama verificada es feature/cobros-v1; HEAD `4dd4ca4`.
Shell responsive, ₡ y 2 decimales solo en importes. Dashboard aprobado.
Suite CobrOS: **105/105, 658 aserciones**; finanzas 1C y permisos 1D intactos.
1E-B (gestión de cobradores con contratos) completada: servicio, controlador propietario,
Panel Maestro individual con crear/toggle/sucursales, 23 tests de cobradores.
Detalle en docs/PROGRESO_COBROS.md. Vite no instalado; build y prueba manual/PWA pendientes.
tmp_generate_cobros_icons.php preservado fuera del commit; sin push ni merge.
Las entradas Commerce siguientes son contexto heredado de la rama base.


## Pulido operativo P1–P4 — pre N10 / producción (2026-09-17)

Base `feature/pos`, HEAD `7fe7e79`. Trabajo local sin commit, push ni producción.

**Cambios realizados:**
- **P1 — Scanner de cámara en productos (AUDITADO / YA EXISTÍA):** confirmado funcional en `resources/views/productos/_form.blade.php` vía `<x-scanner.mvs-scanner />`, escucha `@mvs-scan.window` y copia el código leído al campo de código de barras. Componente reutilizable en `resources/views/components/scanner/mvs-scanner.blade.php`, lógica en `resources/js/scanner/index.js`, importado en `resources/js/app.js`.
- **P2 — Producto recién agregado arriba en Compras:** en `resources/js/modules/compras.js` `addProduct` ahora inserta nuevos ítems con `unshift` (arriba) y enfoca/selecciona el input de cantidad de la primera fila; si el producto ya existe, incrementa la cantidad y enfoca la línea existente. Se agregaron `data-item-id` y `data-field="quantity"` a los inputs de cantidad en `resources/views/compras/create.blade.php` y `resources/views/compras/edit.blade.php`. Ingreso de mercadería/verificación no aplica porque sus líneas vienen prefijadas por la compra.
- **P3 — Reset Demo (CÓDIGO/SCHEDULER LOCAL VERIFICADO; PRODUCCIÓN PENDIENTE DE CERTIFICAR):** `routes/console.php` ya programa `demo:company --reset --force` a las 02:00 con `withoutOverlapping()` y `onOneServer()`. Se agregó `appendOutputTo(storage_path('logs/demo-reset.log'))` y un checklist de producción en comentario. `php artisan schedule:list` y `php artisan schedule:run` verifican que el scheduler responde; el comando no se ejecutó ahora porque `dailyAt('02:00')` aún no vence. No se corrió el reset destructivo en local. El reset nocturno de producción NO está certificado hasta comprobar el cron/scheduler del VPS.
- **P4 — Icono propio MVS en móvil:** generados `public/icons/favicon-32x32.png`, `apple-touch-icon.png`, `icon-192x192.png` e `icon-512x512.png` desde `public/images/logo-mvs.png` usando GD; creado `public/manifest.json` con identidad dorada `#D4AF37`; agregados `<link rel="icon">`, `<link rel="apple-touch-icon">`, `<link rel="manifest">`, `theme-color` y capacidad web-app en `resources/views/layouts/app.blade.php`. El favicon `.ico` genérico/ vacío de Laravel queda sin uso.

**Validación:**
- `npm run build` correcto; `git diff --check` limpio.
- `php artisan schedule:list` muestra el comando Demo programado.

**Pendiente:**
- Validación visual en navegador real (360/768/1280) para P2 y P4.
- En producción: confirmar cron del scheduler y `APP_TIMEZONE` para P3; no autorizado deploy.

## APARTADOS POS — RECIBIDO/VUELTO + MVS PRINT (2026-09-16 noche)

Implementación local terminada para pre-commit; sin commit, push, deploy ni migración en producción.

**Funcionalidad completada:**
- Migración aditiva `received_amount` / `change_amount` en `layaway_payments` (DECIMAL 19,4 nullable).
- Casts y validación backend en `LayawayService::receivedAndChange` con BCMath.
- UI de Recibido/Vuelto en POS (`resources/views/pos/index.blade.php`) y vista de apartado (`resources/views/layaways/show.blade.php`).
- Corrección del mojibake "Cambiar a cotización".
- `EscPosLayawayTicket` para comprobantes 58/80 mm con símbolo `₡`, word-wrap y sin mojibake.
- Endpoints MVS Print: `mvs.print.ticket.layaway` y `mvs.print.ticket.layaway.payment`.
- Auto-print de apartado y abono desde POS, apertura de cajón solo para efectivo, reimpresión read-only sin drawer ni mutaciones.
- Actualización de `AGENTS.md` y `docs/GUIA_VISUAL.md`.

**Pruebas ejecutadas:**
- `PosLayawayModeTest` 17/17, 167 aserciones.
- `LayawayV1Test` 7/7, 42 aserciones.
- `MvsPrintLayawayTicketTest` 11/11, 73 aserciones (nuevo).
- `MvsPrintAutoPrintTest` 32/32, 309 aserciones.
- `QuoteTest` 12/12, 159 aserciones.
- `PosCheckoutTest` 17/17, 3 aserciones reportadas por PHPUnit (focalizado junto a los anteriores).
- Suite focalizada total: **96 pruebas, 96 aprobadas, 753 aserciones, 0 fallos**.
- Regresión POS + Apartados + Cotización + MVS Print: 298 pruebas, 292 aprobadas, 2036 aserciones, 6 fallos preexistentes documentados (no atribuibles a esta tarea).
- Tests JS: `mvs-print-test.cjs` 20/20; `pos-layaway-mode.cjs` ejecutado vía `PosLayawayModeTest`.
- `npm run build` correcto; `git diff --check` limpio; Pint aplicado a archivos PHP modificados.

**Pendiente:**
- Migración `2026_09_16_000002_add_received_change_to_layaway_payments_table.php` pendiente de ejecución en producción (NO autorizada todavía).
- Prueba física con impresora térmica y cajón pendiente.

## Modo Apartado integrado al POS — auditoría pre-commit (2026-09-16)

Implementación local aprobada para pre-commit, todavía sin commit, push ni producción. El POS permite entrar a Apartado con `apartados.crear`, exige cliente y prima positiva, reserva únicamente stock real de la sucursal activa bajo `lockForUpdate`, admite precio manual sólo con `pos.cambiar_precio` y reutiliza las reglas de pago de `LayawayService` (sin crédito ni puntos). Cotización y Apartado son mutuamente exclusivos. La UI nueva usa el dorado oficial `bg-primary` (`#D4AF37`) y mantiene acciones de 44/48 px.

Idempotencia: migración nueva nullable para `client_token` UUID y `request_fingerprint` SHA-256, con UNIQUE exacto `(company_id, client_token)`; los apartados históricos permanecen válidos. Replay idéntico devuelve el mismo apartado sin repetir reserva ni prima; el mismo token con payload distinto devuelve 409; empresas distintas pueden reutilizar el token. La migración fue auditada como compatible con PostgreSQL 16, pero no se ejecutó contra PostgreSQL en esta estación porque `pdo_pgsql` no está cargado.

Integración existente verificada: el apartado creado en POS aparece en el listado, acepta abonos y entrega final; conserva el precio manual en `SaleItem`, mantiene descuento cero y no vuelve a descontar inventario al entregar. Descuentos quedan pendientes: requieren persistencia explícita de `discount_total`/`gross_total` y preservación durante entrega; cualquier payload de descuento se rechaza en esta fase.

Validación: `PosLayawayModeTest` **14/14, 134 aserciones**; `LayawayV1Test` **7/7, 42**; `QuoteTest` **12/12, 159**; PosCheckout relacionados **46/46, 408**; MVS Print PHP **70/70, 348**, JS **41/41**; Vite build y `git diff --check` correctos. Regresión POS + Quote: **198 pruebas, 193 aprobadas, 1469 aserciones**, con exactamente los cinco fallos históricos ya documentados y sin fallos nuevos. Validación visual real a 360/768/1280 y PostgreSQL ejecutado quedan pendientes; no autorizado para producción.

## MVS Print — instalador 1.0.2 en preparación (2026-09-16)

Versión bump a 1.0.2 en todos los archivos fuente: NSI (`!ifndef` guard, única definición), Launcher.cs, launcher.manifest, build-installer.ps1, README.md, VERSION. Dorado oficial documentado en AGENTS.md y docs/GUIA_VISUAL.md. Impresion.blade.php migrado de amber/blue a `bg-primary` dorado. Tests PHP 62/62, JS security 25/25, JS print 14/14. Launcher build + tests PASS. Pre-existente: ResponsiveNavigationTest fallo de logo (no relacionado). Build completo del instalador requiere QZ source tree (JDK+Ant); preparado para ejecutar cuando el árbol QZ esté disponible. Prueba física final pendiente. Nota: archivos fuente del installer (`storage/app/mvs-print-release/`) están gitignored; el version bump es local y efectivo en el próximo build.

## MVS Print — instalador 1.0.1 completado, producción actualizada (2026-09-16)

Instalador 1.0.1 completado con launcher propio C# x64 (`MVS Print.exe`, 27,648 bytes), QZ 2.2.6 embebido, ruta automática `C:\Program Files\MVS Print`, identidad dorada oficial (`#D4AF37`), certificado público para trust management, single instance vía Mutex, y tests automatizados. Builds y uploads a producción verificados: SHA256 `1d8a5e66...`, URL `https://app.mvscommerce.com/mvs-print/MVS-Print-Setup.exe?v=1.0.1`. SmartScreen requiere certificado de firma de código (limitación externa, no bug). Pruebas PHP 62/62, JS 25/25 (QZ real), JS 14/14, npm build PASS. Prueba física final pendiente en Liberia.

## MVS Print — AUTO_PRINT, Imprimir postventa y Reimprimir (2026-09-15, cierre aprobado)

Base `21d5329`, `feature/pos`. Listado y detalle de Ventas reutilizan `EscPosSaleTicket` mediante el endpoint existente y QZ; fallback del navegador exclusivamente manual tras error. Terminal por UUID local o única terminal configurada en empresa/sucursal; no resolver ambigüedad silenciosamente. Reprint conserva ancho/corte, desactiva cajón y reutiliza autorización del comprobante. `auto_print` corrige conexión QZ ya activa y resolución `undefined`; venta nueva conserva cajón configurado. Diagnóstico temporal retirado. Botón Imprimir postventa integrado en el mismo canal MvsPrint.printSale; fallback manual tras error y mensaje si no se resuelve terminal. JS ejecuta confirmCheckout y el botón reales con QZ/HTTP simulados. Demo sin terminal nunca reutiliza MYM (test); configuración física de Demo no consultada. PHP MvsPrint **56/56, 226 aserciones**; JS **14/14**; build y diff-check correctos. Snapshot de todas las tablas y SQL prueban cero escrituras del GET de reprint. Prueba física y visual pendiente. Commit y push de esta corrección autorizados; producción no autorizada. Detalle: [MVS_PRINT_TEST_PRINT_AUDIT.md](MVS_PRINT_TEST_PRINT_AUDIT.md). Cambios locales ajenos preservados.

## Corrección focal — canje proporcional sobre base pre-impuesto (2026-09-15)

Base `8cf8714`, `feature/pos`, limpia al iniciar la corrección; trabajo local sin commit. Cambio funcional únicamente en PosSaleProcessor: la base elegible neta deriva de SaleItem.subtotal, después de descuentos, sin impuestos y respetando earn_on_offers. Se excluye su porción financiada con puntos: `base_elegible * canje_real / Sale.total`, BCMath, sin truncar el ratio y con redondeo único de la porción a cuatro decimales. Sustituye la resta íntegra del canje rechazada por el usuario. Ejemplo base 10000 + IVA 1300 y canje 2260: earning base 8000; canje total: cero; sin canje: base intacta. Multiplicador después del prorrateo; redemption, impuestos y point_value intactos.

Validación final: **86/86 pruebas focales, 679 aserciones, cero fallos**; cubre tasas mixtas, exentos, descuentos, ofertas on/off, multiplicadores, redondeo, efectivo/tarjeta/SINPE, retry y aislamiento empresarial. Lint PHP y diff-check correctos. Sin UI, migración, commit, push ni producción. Problemas preexistentes de devoluciones/anulaciones/premios/comprobante fuera de alcance; no se reejecutaron esas suites. Regla en docs/DECISIONES.md y detalle en docs/POS_CANJE_PUNTOS.md. Listo para auditoría focal.

## Canje monetario en POS — auditoría aprobada, cierre autorizado (2026-09-14)

Auditoría final del usuario aprobada: canje, pago parcial/total/mixto, autoridad backend, atomicidad, idempotencia y multitenant correctos. Commit y push a feature/pos autorizados; producción no autorizada. Los problemas consignados abajo se confirman preexistentes y fuera del alcance de este commit, por lo que no bloquean el cierre del pago con puntos. El párrafo siguiente conserva la evidencia de la implementación local anterior al cierre.

Paso 32 pausado por el usuario. Reutilizada fidelización existente; habilitado pago total con puntos sin pago normal, validación de cantidades en checkout y BCMath para puntos/restante. Focal final: 51/51, 373 aserciones; canje total, retry y JavaScript renderizado probados. Regresión POS/Loyalty/USD: 75/76, 590 aserciones; fallo de texto esperado del comprobante fuera del parche. Bloqueo: devoluciones/anulaciones llaman a métodos de inventario ausentes (`saleReturn`, `voidSale`), 13 errores; sin ampliar alcance. Multisucursal añade error de premio por `postRewardRedemption` ausente. Detalle y entrega: [POS_CANJE_PUNTOS.md](POS_CANJE_PUNTOS.md). Sin migración, commit, push ni producción; validación visual pendiente.

## Modo cotización en POS — implementación local (2026-09-12)

Corrección visual posterior (2026-09-12): `Cotizar` ahora tiene fondo azul, texto blanco, cursor activo y mínimo 44px; su bloqueo refleja permiso/flujo sin depender del carrito. Ambos `Guardar cotización` se deshabilitan con carrito vacío. Pruebas de expresiones del botón y JavaScript renderizado, permiso Blade verdadero/falso: `QuoteTest` 12/12, 159 aserciones; build y `git diff --check` correctos. Se confirmó ausencia de fondo en el botón previo; no se reprodujo el fallo de activación inicial ni se verificó la sesión del usuario en navegador. Validación visual pendiente; los cinco fallos preexistentes no se modificaron ni se reejecutó la regresión amplia para este ajuste. Cambios de este ajuste: vista POS, pruebas Quote PHP/JS y esta nota; se preservó el trabajo local anterior.

Base verificada: `feature/pos`, `305466d`, árbol limpio antes de editar. `Cotizar` activa modo Alpine local con carrito vacío, conserva carrito existente y permite stock cero/cantidades superiores al stock. `Guardar cotización` reutiliza servicio y ruta existentes; cliente opcional y cálculos intactos. Guarda, limpia el carrito, confirma y vuelve a venta en la misma página. `Volver a venta` y carga de cotización restauran controles; checkout no acepta saltarse stock mediante `quote_mode`.

Alcance de código: vista POS; prioridad de stock en búsqueda solo para cotización autorizada; respuesta JSON de errores limitada al POST `cotizaciones`. No cambia QuoteService, rutas, inventario, caja, fidelización, demo ni compras. La prueba de conversión existente ahora prepara caja abierta y verifica stock real, corrigiendo un fixture que fallaba antes de llegar al inventario.

Validación SQLite en memoria: `QuoteTest` **12/12, 158 aserciones, cero fallos**, incluida ejecución del JavaScript renderizado con Node y snapshots/query log de cero efectos en ventas/items/pagos, stock/Kardex, caja y fidelización. Regresión final POS + Quote: **173 pruebas, 168 aprobadas, 1161 aserciones, 5 fallos preexistentes**. Comparación directa con código/tests de `305466d`: 167 pruebas, 161 aprobadas, 1070 aserciones, 6 fallos; se resolvió únicamente el fixture focal de conversión. Persisten tres fallos de `PosAccessAndSearchTest` (payloads ampliados de productos/clientes y texto antiguo del modal), uno de `PosCheckoutTest` (texto antiguo del comprobante) y uno de `PosSuspendedSalesTest` (125 vs '125.00'). Evidencia local: `storage/logs/quote-mode-baseline.{txt,xml}` y `quote-mode-regression.{txt,xml}`. Comando final: `php -d memory_limit=512M vendor/bin/phpunit --filter='Tests\\Feature\\(QuoteTest|Pos(?!tgre))'`; APP_KEY temporal de proceso, sin `.env` ni configuración global.

Build Vite y `git diff --check` correctos. Pint pasa en bootstrap/test; el controlador conserva los mismos cuatro avisos de formato de la base, sin refactor ajeno. Responsive conceptual 360/768/1280, acciones nuevas de 44/48px y barra móvil existente; sin navegador, pendiente de revisión visual del usuario. Listo para auditoría local, sin declarar verde la regresión global. Sin commit, push ni producción.

## Toma de Inventario — pruebas finales locales (revalidación 2026-09-11)

Corrección de auditoría por contrato del usuario: **stock final = físico confirmado**; el ajuste firmado se calcula contra el stock actual bajo bloqueo. Snapshot 10, venta deja 8 y físico 12: stock final 12, movimiento +4; snapshot/diferencia original y venta intactos. `InventoryCountTest` pasa **39/39, 361 aserciones** con y sin `--stop-on-failure`. Creación incorpora marker responsive y detalle elimina scanner sin uso; edición conserva scanner compartido. `InventoryPostingService.php` intacto. Auditoría de precisión: ya existe migración a `decimal(19,4)` para las tres columnas Kardex, registrada en SQLite local; no se requiere nueva migración. Regresión relacionada: **46/49, 502 aserciones**; dos errores por `postImportMovement()` ausente y un fallo de texto mojibake en transferencias, fuera de alcance. Detalle y límites de UI/concurrencia en [TOMA_INVENTARIO_TESTS.md](TOMA_INVENTARIO_TESTS.md). Sin commit, push ni producción; listo para auditoría del módulo, sin declarar verde la regresión general.

## Decisión USD en POS — 2026-09-09

Registrada en `docs/DECISIONES.md`: USD se ofrecerá automáticamente dentro de Efectivo según `accepts_usd`, snapshot y tipo de cambio válido de la sesión aplicable, sin requerir PaymentMethod manual. Auditoría local: no hay métodos USD/Dólares, MYM tiene USD deshabilitado y no hay sesiones abiertas/en cierre. Implementación integral pendiente: el checkout actual es CRC y falta persistencia de moneda/importe USD para conciliación. Sin cambios de datos, métodos de pago ni producción.

## Separación Dashboard administrativo / sucursal operativa — 2026-09-09

El bloque previo fue confirmado y publicado en `feature/pos` como `f40efccf3a614eb88aa56d373ee46da3d5a1a856`. La corrección actual queda **local, sin commit**. Dashboard administrativo consulta empresa/todas por defecto; el filtro GET por sucursal no modifica `active_branch_id`. POS/Caja solicitan sucursal explícita cuando falta y conservan permisos, asignaciones y caja obligatoria. Usuarios operativos mantienen el comportamiento existente. Detalle: [DASHBOARD_ADMINISTRATIVO_CAJA.md](DASHBOARD_ADMINISTRATIVO_CAJA.md).

Sin acceso a producción, cambios de inventario, `.env` ni stash de Notificaciones. Las notas de cierre siguientes describen el estado histórico anterior al commit citado.

Validación actual: focal final **56/56, 515 aserciones**; accesos/P08/onboarding/alertas **22/22**; regresión **213 pruebas, 206 aprobadas**, cinco fallos y dos errores preexistentes de POS, logo y compras (`postPurchase()` ausente). Build y diff-check correctos. Revisión responsive conceptual, sin navegador. No se realizó commit ni push.

## Dashboard Administrativo + Caja — cierre local (2026-09-09)

Bloque retomado desde el working tree y terminado para preparar un commit único, **todavía no ejecutado**. Dashboard por Hoy/Semana/Mes/sucursal/Todas sin requisito de caja para consulta administrativa; apertura por denominaciones, cierre ciego, conciliación y correo existentes conservados. Configuración → Caja soporta múltiples destinatarios por empresa y cierre normal sin correo cuando la lista está vacía; idempotencia de cierre/job/reintento parcial probada. Caja mantiene `mail.notifications.from`, auth/sistema `mail.from` y soporte `mail.reply_to`, sin tocar `.env` ni SMTP.

`documentsBreakdown()` es la fuente única: venta + abono CxC + abono de apartado + pago CxP; excluye SalePayment y CashMovement. Se conserva el contador POS recibido. Revertida únicamente la ampliación ajena de payload en `PosAccessAndSearchTest.php`; archivo sin diff. `InventoryPostingService.php`, producción y stash de notificaciones intactos.

Validación: focal **52/52, 467 aserciones**; filtro Cash **154 pruebas, 151 aprobadas, 974 aserciones**, con el fallo histórico de Órdenes/POS 200/302 y dos errores de `postPurchase()` ausente. Build Vite correcto, 236 módulos; diff-check correcto. Cronograma maestro nuevo: bloques **11–20 completados localmente**, producción No, sin renumerar ni avanzar Notificaciones. Detalle y límites: [DASHBOARD_ADMINISTRATIVO_CAJA.md](DASHBOARD_ADMINISTRATIVO_CAJA.md). Pendiente revisión del usuario y orden explícita de commit/push; no autorización de producción.

## Continuación local — vencimiento P37 (2026-09-07)

Como antecedente histórico: posteriormente se verificó PostgreSQL de producción y se reactivó **solo** `loyalty_settings.is_active` de MYM (id/company_id 1) con autorización expresa; saldo 97 y movimiento 7109 quedaron intactos. Esta nueva tarea es exclusivamente local, sin acceso ni cambios a producción.

Implementada política compartida compra > movimiento legado P37 válido; referencia ambigua o ausente no calcula fecha. Integrada en portal y expiración bajo lock, conservando idempotencia y metadata de origen. Sin migraciones/backfill, sin tocar importador, compras ni historial. SQLite: **83/83, 553 aserciones**. PostgreSQL 16 aislado: **84 pruebas, 53 pasan, 31 errores**, todos en fixtures existentes de `LoyaltyMigrationP37Test` que usan `identification_type=national`, rechazado por `customers_identification_type_check`. Las pruebas nuevas, incluido P37 real integrado y concurrencia de dos procesos (espera de lock, compra confirmada y relectura), pasan en PostgreSQL. **PAUSA por instrucción del usuario ante anomalía importante; no corregir fixtures ni hacer commit/deploy sin retomar explícitamente.** Pint focalizado y diff-check correctos. La instancia local 127.0.0.1:55439 fue detenida; sus datos ficticios se conservan en `storage/framework/testing/p37-pg-test`. No se cambió php.ini ni .env; pdo_pgsql se cargó solo por CLI. La nueva política debe aprobarse y volver a medir su impacto antes de producción.

## Validación posterior — fixtures de identificación (2026-09-07)

El usuario autorizó retomar únicamente los fixtures: 24 valores `national` y uno `physical` cambiados a `01` en DataExportTest, HistoricalSaleImportP34P35Test, LoyaltyMigrationP37BulkTest, LoyaltyMigrationP37Test, PosAccessAndSearchTest y RepairP37IncompatibleSnapshotsTest. No quedan inserciones inválidas de identification_type detectadas en la suite; se conserva el caso negativo de importación P32 con tipo 6. Sin cambios adicionales a lógica, schema, validadores ni datos reales.

Validación focalizada (seis archivos afectados + vencimiento P37): SQLite **97 pruebas, 597 aserciones, 3 fallos POS y 1 omisión de concurrencia PostgreSQL**; PostgreSQL aislado **97 pruebas, 587 aserciones, 5 fallos y 2 errores**. Los 15 casos nuevos de política pasan en ambos motores; concurrencia PostgreSQL pasa (8 aserciones). Pendientes PG: dos triggers escritos con sintaxis SQLite, expectativa de ID fijo en P37 y formato decimal de exportación; además los tres fallos POS comunes.

Regresión amplia `Loyalty|RepairP37IncompatibleSnapshotsTest|DataExportTest|HistoricalSaleImportP34P35Test|PosAccessAndSearchTest`: SQLite **519 pruebas, 2838 aserciones, 45 fallos, 2 errores, 1 omitida**; PostgreSQL **519 pruebas, 2705 aserciones, 47 fallos, 32 errores**. Cada ejecución reporta también un test riesgoso. Cero errores de customers_identification_type_check. Persisten problemas fuera del alcance: métodos de inventario ausentes, expectativas de permisos/vistas, fixtures UUID y products.product_type inválidos, y manejo de transacción abortada PostgreSQL. No se corrigieron. Evidencia local en storage/logs/fixture-*-regression.json y fixture-focused-*.xml. CLI con memory_limit=512M y pdo_pgsql, sin cambiar configuración global.

La corrección de identificación está terminada. La revisión independiente confirmó que la política P37 y estos fixtures no introdujeron regresiones; el usuario autorizó un único commit limitado, sin corregir la deuda ajena ni desplegar. Validación final pre-commit de la suite nueva: SQLite 15 aprobadas, 69 aserciones y una omisión (concurrencia requiere PostgreSQL); PostgreSQL aislado 16 aprobadas, 77 aserciones, incluida concurrencia real. No declarar la regresión global en verde ni autorización para deploy. Sin acceso a producción en esta tarea.

> La información de este archivo es una fotografía. Antes de programar, comprobar el estado real del repositorio: `git status`, último commit y código del módulo.

---

## Panel Maestro — Cronograma M

- **M01 — Licenciamiento SaaS por tenant: COMPLETADO.** Platform Admin controla estado/plan, `branch_limit` y módulos por empresa mediante el contrato existente; las mutaciones están protegidas en middleware y servicio.
- Bloqueo tenant e aislamiento entre empresas cubiertos por pruebas; roles tenant no administran privilegios globales.
- Fuente oficial: `docs/Cronograma_M_Panel_Maestro_MVS_Commerce.xlsx`.
- **M02 — Listado global: COMPLETADO.** Búsqueda por empresa/propietario, filtros de licencia/módulo y resumen de propietario, plan, sucursales usadas/límite, módulos y usuarios.
- **M03 — Alta comercial mínima: COMPLETADO.** Crea propietario, tenant y contrato sin datos fiscales, sucursales ni operación.
- **M04 — Acceso propietario: COMPLETADO.** Invitación segura expirable/de un uso, activación al definir contraseña y separación estricta de Platform Admin.
- **M05 — Onboarding tenant: COMPLETADO.** El propietario completa datos legales y primera sucursal en el tenant comercial existente sin alterar contrato.
- **M06 — Sucursales: COMPLETADO.** `branch_limit` se aplica por tenant; aumentar desde Panel Maestro habilita la siguiente alta sin borrar datos.
- **M07 — Módulos: COMPLETADO.** Desactivación bloquea navegación/URL sin borrar permisos y reactivación los restaura; tenant no auto-habilita. M08 queda siguiente.
- **M08 — Ciclo de vida: COMPLETADO.** Suspender/cancelar bloquea sin borrar; reactivar restaura acceso y conserva datos. M09 queda siguiente.
- **M09 — Ficha tenant: COMPLETADO.** Vista única con contrato, módulos, sucursales/uso, usuarios, fechas, historial y acciones maestras. M10 queda siguiente.
- **M10 — Auditoría/seguridad: COMPLETADO.** Historial con actor/snapshot para licencia, límites, estado y módulos; aislamiento y escalada cubiertos. M11 queda siguiente.
- **M11 — UX responsive: COMPLETADO.** Panel y onboarding validados para móvil/tablet/escritorio, sin overflow de página y con acciones claras.
- **M12 — Regresión: COMPLETADO; DESPLIEGUE NO EJECUTADO.** M01–M11 integrados en verde (53 pruebas, 273 aserciones), build correcto y MYM preparado mediante escenario aislado. Listo para aprobación humana de producción; pausa obligatoria antes de desplegar o crear datos reales.
- **M13 — Planes/licencias: COMPLETADO.** Plan como plantilla y licencia como contrato efectivo con overrides aislados. Panel Maestro contractual; operación tenant en solo lectura.
- **M14 — Renovaciones/ciclo: COMPLETADO localmente.** Trial/Active/Grace/Expired/Suspended/Cancelled, renovación y trazabilidad contractual no destructiva. Sin cobros ni despliegue; listo para aprobación de producción tras validación final.

## Onboarding de clientes y sucursales

- Implementado onboarding obligatorio para usuarios cliente sin empresa: empresa y primera sucursal se crean mediante `CompanyProvisioner` antes de habilitar el dashboard.
- Los administradores de plataforma son dirigidos siempre a `/panel-maestro`; el Panel Maestro conserva su middleware exclusivo.
- La cuenta de plataforma y la administración tenant son identidades separadas. `platform:admin correo --create` permite crear interactivamente una identidad global activa, sin empresa ni sucursal; la promoción de cuentas tenant se rechaza y cualquier revocación necesaria se realiza explícitamente con `--revoke` durante la operación del despliegue.
- Sucursales queda visible bajo Configuracion para usuarios autorizados. La creacion adicional reutiliza `BranchController` y respeta `company_license.branch_limit` mediante `CompanyLicenseService`.
- Empresas existentes conservan su contexto y no repiten onboarding.


## Estado actual de Portal de Clientes (P01–P20) — reconciliado con Excel único

**P10–P20 COMPLETADOS.** P14–P18 crean el incentivo único y sus reglas; P19 completa su trazabilidad. P20 aplica nombre/logo y colores configurables por empresa al acceso, registro, portal y tarjeta QR, manteniendo “Hecho con MVS Commerce”. Regresión P14–P20/Portal/POS/canje: 167 tests, 1.040 aserciones.

Fuente oficial: `docs/CRONOGRAMA_PRODUCCION.md` (P01–P50) y referencia visual `docs/Cronograma_Unico_Portal_Correcciones_MVS_Commerce_28-08-2026.xlsx`. Reemplaza cronogramas anteriores; **P01–P25 COMPLETADOS** (incluidos P09A–P09D con sus IDs exactos). Reconciliación documental aprobada: P21 corresponde a Separación Platform/Tenant y P22 a Onboarding, ambos con evidencia `a60425f`; P23/P24 cierran transferencias y P25 la navegación tenant responsive. **P26 es el siguiente bloque. P09 ajuste visual QR compacto: commit `58aba11`**.

- **P01 — Registrarme: COMPLETADO.** Enlace “Registrarme / Crear mi cuenta” en `loyalty.portal.login` (`resources/views/loyalty/portal/login.blade.php:14`) hacia `portal-clientes/{company}/registro`.
- **P02 — Autorregistro: COMPLETADO.** `LoyaltyPortalSessionController::register` crea cliente activo (`is_active=true`) disponible en `clientes`, `pos.customers.search` y Fidelización, dentro de la empresa de la URL (`portal-clientes/{company}`), vía `Customer` + `LoyaltyPortalCredential`; sin factura/incentivo/QR individual. Rutas `loyalty.customer.register` / `register.store` (`routes/web.php:139`, `throttle:10,1`).
- **P03 — Deduplicación + bloqueo por conflicto: COMPLETADO.** Antes de crear, busca por `identification` / `phone` normalizado (`PhoneNumberService`) / `email` lower dentro de la empresa; si algún dato coincide, enlaza al cliente existente en vez de duplicar. **Si dos identificadores apuntan a clientes distintos (ej. identificación→A y teléfono→B, o correo→C distinto de teléfono→B), bloquea** con mensaje seguro `Los datos proporcionados coinciden con clientes distintos...`, sin fusionar, sin crear `Customer` ni `LoyaltyPortalCredential`, sin crear credencial. Aislamiento multiempresa obligatorio probado (identificación `ID-123` duplicada en empresas distintas crea registros separados).
- **P04 — Visibilidad POS: COMPLETADO (evidencia actual).** Cliente nuevo queda activo y es encontrado por `PosController::searchCustomers` (`pos.customers.search` LIKE `name/identification/phone/mobile/email`) con `pos.acceder` y sesión `active_company_id/active_branch_id`. Evidencia: `LoyaltyPortalSelfRegistrationTest::test_register_creates_new_active_customer_and_credential` y `test_new_customer_appears_in_pos_search` (por nombre y por `8888` normalizado).
- **P05 — Cuenta fidelización al autorregistrarse: COMPLETADO.** `LoyaltyPortalSessionController::register` crea/activa `LoyaltyAccount` vía `LoyaltyAccountService::getOrCreateAccount` dentro de la misma transacción; sin bono/incentivo. Evidencia: `LoyaltyPortalSelfRegistrationTest::test_register_creates_loyalty_account_automatically` con `loyalty_accounts` `balance 0.0000`.
- **P06 — Crear acceso Portal desde Clientes y POS rápido: COMPLETADO.** `CustomerController::createPortalAccessForCustomer` (`clientes.store` con `create_portal_access`) y `PosController::createPortalAccessForQuickCustomer` (`pos.customers.quick-store` con `create_portal_access`) generan `LoyaltyPortalCredential` aislado por `company_id` con usuario derivado de nombre/teléfono y contraseña temporal única mostrada una vez; no crea acceso si cliente ya tiene credencial activa y reutiliza `PhoneNumberService`. Checkbox en `clientes/_form.blade.php` y `pos/index.blade.php` bajo permisos `clientes.crear`/`pos.acceder`.
- **P07 — Contraseña temporal única con cambio obligatorio: COMPLETADO.** `LoyaltyPortalCredential.must_change_password` (`2026_08_29_000001_add_must_change_password_to_loyalty_portal_credentials`, default false) en `true` al crear acceso P06, `LoyaltyPortalSessionController::login` redirige a `loyalty.customer.password.force`, `home` bloquea hasta cambiar, `forceChangeForm`/`forceChange` valida `PasswordRule::min(8)->letters()->mixedCase()->numbers()` y limpia flag; vista `loyalty/portal/force-change.blade.php` responsive. Contraseña no genérica: cada credencial tiene hash distinto y tests verifican `must_change_password=true`.
- **P08 — Entrega de acceso al cajero: COMPLETADO.** `LoyaltyPortalDeliveryService::build` genera `portal_url` (`route('loyalty.customer.login', $company)` aislada por `company_id`), `whatsapp_url`/`whatsapp_phone` vía `PhoneNumberService::forWhatsApp` (normalizado, digits only), `copy_text`/`message` con URL+usuario+contraseña temporal + aviso de cambio obligatorio. `CustomerController::store` añade entrega a flash `portal_access` (solo un request, no persiste plain), `PosController::storeQuickCustomer` añade entrega a JSON `portal_access`. Vistas: `clientes/index.blade.php` banner responsive `portal-delivery` con Copiar (clipboard) y WhatsApp (solo abre `https://wa.me/...` prellenado, no envía automático), `pos/index.blade.php` `quickCustomer.delivery` modal responsive con Copiar/WhatsApp/Continuar. QR no adelantado (P09B pendiente). Aislamiento multiempresa verificado.
- **P09 — Pantalla central Portal (URL general): COMPLETADO.** `LoyaltyPortalManagementController::index` genera `portalUrl` por empresa (`route('loyalty.customer.login', $company)`) y `portalQr` local `LoyaltyPortalAccessService::qrSvg` (chillerlan, sin API externa, H/ECC). Vista `loyalty/portal-management/index.blade.php` `acceso-general` muestra URL general, botón Copiar URL, Vista previa (nueva pestaña), QR vectorial e Imprimir QR. Aislamiento: URL contiene `company->id`, no mezcla empresas. Nav incorpora `Acceso general` sin duplicar sidebar.
- **P09A — Código público único cliente: COMPLETADO.** Migración `2026_08_29_000002_add_public_code_to_customers` (`public_code` 12 nullable + unique `company_id+public_code`), `Customer` fillable + `booted::creating` con `CustomerPublicCodeService` (8 chars A-Z0-9, CSPRNG, reintento por colisión, sin exponer `id`/cédula/teléfono/email). `ensure()` para legacy y `isSensitiveLeak()` validado. Aislamiento por empresa y no leak verificado.
- **P09B — QR + Code128 individual: COMPLETADO.** `CustomerPublicCodeService::qrSvg` (chillerlan H, `QRMarkupSVG`) y `barcodeSvg` (picqer Code128 SVG) 100% locales, sin API externa. `clientes/show.blade.php` `Identificación pública` muestra código, QR y Code128 con Copiar/Imprimir responsive. No expone identificación/teléfono/email, solo `public_code`.
- **P09C — Escaneo QR/Code128 en POS: COMPLETADO.** `PosController::searchCustomers` incluye `public_code` (like + order `public_code = ?` exact primero) y retorna `public_code` en payload. `pos/index.blade.php` agrega botón escáner cliente (≥44px, `cameraScannerAvailable`) y `onMvsScan` async: si código 6-12 alfanumérico busca `pos.customers.search` exact `public_code` → `selectCustomer`, sino cae a `searchProducts`. Mantiene búsqueda manual, aislamiento por empresa, sin exponer datos sensibles.
- **P09D — PIN/QR temporal de un solo uso: COMPLETADO.** Tabla `customer_one_time_tokens` (`token_hash` SHA256 único, `expires_at` 5min, `used_at`, `purpose` `redeem`), `CustomerOneTimeTokenService` genera PIN 6 dígitos + QR local (chillerlan) y `verify` valida expiración y single-use atómicamente (`used_at` no null → 422). `clientes/show` genera/muestra PIN+QR y verifica. `isStaticQrTrustedForRedeem=false` — QR estático nunca basta para canjes.
- **P21 – Separación Platform Admin / Tenant Admin: COMPLETADO en a60425f** (`a60425f684e11fd0629a42ac90fe6f25e5d31a35` – Platform Admin / Tenant Admin separados, `platform:admin --create`, `LoginController`, `BranchController`).
- **P22 – Onboarding empresa + primera sucursal + primer administrador: COMPLETADO en a60425f** (`a60425f684e11fd0629a42ac90fe6f25e5d31a35` – `CompanyProvisioner`, `CompanyController`, `EnsureActiveCompany`, `BranchController`).
- **P23 – Auditoría de transferencias existentes: COMPLETADO.** Auditoría de la implementación preexistente: Kardex (`transfer_out`/`transfer_in`), ID `TR-` y stock preservados; el movimiento de inventario se centraliza en `InventoryPostingService::postTransfer` (4 decimales, locking, rollback atómico) sin reconstruir la transferencia.
- **P24 – Probar origen/destino, stock, Kardex, permisos + decisión: COMPLETADO.** `InventoryTransferP24Test` 7/7, 54 aserciones; scoping empresa/sucursal, 4 decimales, rollback atómico, permisos `inventario.transferir` + `inventario.ver_otras_sucursales` + middleware `active.branch`. Decisión: transferencia **instantánea** (`status=completed`, `transferred_at` inmediato); NO se implementó envío/recepción.
- **P31/P32 — COMPLETADOS ADELANTADAMENTE por autorización expresa.** P31 reutiliza Centro de Datos, PhpSpreadsheet, exportación D09, patrones de Compras/Inventario y `PhoneNumberService`. P32 deja Clientes con plantilla XLSX, importación XLSX/XLS/CSV, preview, validación fila/campo, deduplicación por identificación/teléfono/correo dentro de `company_id`, confirmación atómica y exportación existente. `CustomerImportP32Test` 6/6, 42 aserciones; regresión relacionada 23/23, 139 aserciones. Clientes no tiene `branch_id`: su ámbito real es empresa.
- **P33 — COMPLETADO ADELANTADAMENTE por autorización expresa; importador reforzado.** Productos reutiliza Centro de Datos, PhpSpreadsheet y `DataExportService`: XLSX/XLS/CSV, preview sin escrituras, errores fila/campo, resolución normalizada de categorías/marcas/unidades por `company_id` y creación de faltantes solo al confirmar. Catálogos y productos comparten transacción y rollback; costo conserva hasta cuatro decimales (`412.9412`) y los precios mantienen dos. Es catálogo puro: no crea `branch_product`, no cambia stock ni genera movimientos. `ProductImportP33Test` 11/11, 122 aserciones; regresión focal 41/41, 330 aserciones.
- **P34/P35 — COMPLETADOS ADELANTADAMENTE por autorización expresa.** `HistoricalSaleImportService` aporta plantilla/importación XLSX/XLS/CSV, preview, errores por fila/campo/documento, conciliación de encabezado+líneas, resolución empresarial de sucursal/cliente/producto, idempotencia por `company_id+sale_number` y confirmación transaccional. `sales.is_historical` distingue el historial y bloquea anulaciones/devoluciones operativas. No crea caja, pagos, CxC, stock, Kardex, puntos ni comunicaciones. Exportación equivalente disponible en D09. `HistoricalSaleImportP34P35Test` 6/6, 64 aserciones; regresión relacionada 55/55, 377 aserciones.
- **P36 — COMPLETADO ADELANTADAMENTE por autorización expresa.** `InventoryMigrationImportService` aporta plantilla/importación XLSX/XLS/CSV, preview, errores fila/campo, resolución producto+sucursal, conciliación decimal y exportación equivalente. `inventory_migration_batches` conserva origen/usuario/fecha con unicidad por empresa para idempotencia. `InventoryPostingService` fija el saldo inicial con locking y registra Kardex `initial_balance`; `historical_entry/exit` conserva fecha/cadena anterior→nuevo sin cambiar `branch_product`. Sin ventas, compras, caja, pagos, CxC ni fidelización. `InventoryMigrationP36Test` 6/6, 62 aserciones; regresión relacionada 51/51, 401 aserciones.
- **P37–P40 — COMPLETADOS ADELANTADAMENTE por autorización expresa.** P37 mantiene su plantilla simple de 4 columnas y añade operación masiva segura: evidencia opcional exacta para desambiguar, consolidación conservadora por cliente, pendientes trazables que no bloquean válidos, confirmación parcial y resoluciones manuales persistentes; movimientos vía `LoyaltyAccountService`, idempotencia, rollback y bloqueo de cuentas operativas. P38–P40 conservan paquete, preview/reintento y conciliación documentada.
- **P37.1 — Portal Comercial Premium de Fidelización: FASE 1 EN CURSO; A–H COMPLETADOS, K PENDIENTE.** A/B/C preservan imágenes, formulario y carrusel. D añade CTA seguro y producto Core opcional con ficha comercial/disponibilidad simple sin stock exacto. E añade redes HTTPS validadas sin APIs. F usa el saldo y fecha reales de F22/F23. G calcula progreso contra el siguiente premio activo. H aplica nombre, logo, icono, colores, bienvenida y destacado por `company_id`, con fallback empresarial y firma MVS. Sin catálogo general, carrito, pago, PWA, push, campañas ni Passkeys en esta ejecución. Evidencia focal: `LoyaltyPortalManagementTest` + `LoyaltyPortalPremiumP371Test`; migraciones `2026_09_03_000001` y `2026_09_03_000002`.
- **Principios P37.1:** "MVS Portal no es una tienda. Es un portal de fidelización capaz de convertir una oportunidad en una compra." Fidelización detecta oportunidades y facilita venta/retención; MVS recomienda y cada empresa controla sus incentivos. Aislamiento SaaS y mobile-first obligatorios.
- **Mejora transversal de plantillas P31–P40:** las cinco plantillas de entrada (Clientes, Productos, Ventas históricas, Inventario/Kardex y Fidelización) incluyen `INSTRUCCIONES`, formatos de texto para identificadores/códigos/teléfonos/CABYS, formatos decimales solo en cantidades y montos, y desplegables basados en valores cerrados reales. Productos usa categorías, marcas y unidades activas de la empresa; impuesto conserva el contrato abierto 0–100. Los importadores heredados y la lógica de negocio no cambiaron. Evidencia: `MigrationTemplateSafetyTest` 2/2, 32 aserciones; regresión P31–P40 focal 40/40, 423 aserciones.
- Evidencia P01–P09D: `LoyaltyPortalSelfRegistrationTest` **11/11, 52 aserciones** + `LoyaltyPortalClientAccessTest` **11/11, 55 aserciones** + `LoyaltyPortalDeliveryTest` **7/7, 51 aserciones** + `LoyaltyPortalCentralTest` **4/4, 17 aserciones** + `CustomerPublicCodeTest` **5/5, 23 aserciones** + `CustomerQrBarcodeTest` **4/4, 16 aserciones** + `CustomerPosScanTest` **3/3, 13 aserciones** + `CustomerOneTimeTokenTest` **4/4, 17 aserciones, 0 fallos** (PIN 6 dígitos, single-use, expiración, aislamiento, static QR no confiable). `LoyaltyCustomerPortal` 13/13.

## Estado actual de Fidelización

Fuente oficial del orden de fases: `docs/Cronograma_Maestro_Fidelizacion_MVS_Commerce_Actualizado_23-08-2026.xlsx`, reflejada en `docs/CRONOGRAMA_FIDELIZACION.md`.

**F01–F45: COMPLETADO** según el cronograma maestro (F28 de forma adelantada). Todas las etapas de Fidelización están completas, sujeto al detalle en `docs/PROGRESO.md`.

Último hito confirmado:

**F45 — Respaldo GitHub: COMPLETADO.**

- F43 (`3efe76f`) y F44 (`5decbca`) publicados en `origin/feature/pos` como puntos de recuperación exclusivos.
- Antes del registro final: HEAD local/remoto sincronizados, working tree limpio y `git diff --check` correcto.
- Suite final de Fidelización: 305 tests, 1942 aserciones, 0 fallos.
- El commit documental exclusivo de F45 completa el cronograma F01–F45; verificar push y árbol limpio en Git al tomar el relevo.

Hito anterior:

**F36 — Acumulación online: COMPLETADO.**

- Capa mínima sobre venta real confirmada (`accrueForSale`) reutilizando F08/F12/F13 y bonos F10/F11 sin duplicar lógica; misma cuenta central `(company_id, customer_id)`, sin cuentas web paralelas.
- Idempotencia determinista `online_sale:{canal}:{ref}:loyalty:earn`; origen online auditado en metadata del movimiento sin columnas nuevas. Sin cliente identificado no acredita. Sin inventario/tienda/API.
- Evidencia: `LoyaltyOnlineSaleTest` (11 tests); regresión al cierre: 276 tests, 1846 aserciones, 0 fallos.

Hitos anteriores:

**F35 — Promociones del portal: COMPLETADO.** Tabla `loyalty_promotions`, administración bajo permiso `fidelidad.promociones` y sección "Promociones vigentes" independiente de los multiplicadores F12. Evidencia: `LoyaltyPromotionTest` (6 tests).

**F33 — QR + F34 — Acceso por enlace seguro: COMPLETADOS.** Token solo como hash SHA-256, ruta pública con throttle, QR local SVG que nunca se persiste y muere automáticamente con su enlace. Evidencia: `LoyaltyPortalAccessTest` (7) + `LoyaltyPortalAccessQrTest` (5).

**F30–F32 — Portal del cliente, identidad visual y marca MVS Commerce: COMPLETADOS** (detalle en `docs/PROGRESO.md`).

Además:

- **F38 — Administrador: COMPLETADO** (etapa 12. Permisos).
- **F39 — Cajero: COMPLETADO** (etapa 12. Permisos).
- **F40 — Indicadores: COMPLETADO** (etapa 13. Dashboard).
- **F41 — Empresa / sucursal: COMPLETADO** (etapa 13. Dashboard).
- **F42 — Suite de pruebas: COMPLETADO** (etapa 14. Calidad).
- **F43 — UI / usabilidad: COMPLETADO** (etapa 14. Calidad).
- **F44 — Regresión: COMPLETADO** (etapa 14. Calidad).
- **F45 — Respaldo GitHub: COMPLETADO** (etapa 15. Cierre).
- No quedan fases pendientes en el Cronograma Maestro de Fidelización F01–F45.
- **F28 — Reversión de puntos por anulación: COMPLETADO de forma adelantada** durante la integración POS (`7be1f80`).

Evidencia histórica: `8392dd4` (canje de puntos) y `7be1f80` (integración de fidelización en POS). Auditoría posterior a F18: 152 tests Loyalty/POS-Loyalty con 0 fallos. Tras F22: regresión Loyalty en verde (134 tests) más POS-Loyalty (48 tests); vencimiento configurable: 7 tests, 62 aserciones. Tras F23: `LoyaltyExpirationTest` (13 tests, 78 aserciones); regresión Loyalty + POS-Loyalty (177 tests, 1160 aserciones) en verde. Tras F24-F25: `LoyaltyRuleCenterTest` (6) y `LoyaltyManualAdjustmentTest` (10). Tras F26-F27: `LoyaltyMultiBranchTest` (5 tests, 40 aserciones); regresión Loyalty + POS-Loyalty (198 tests, 1295 aserciones) en verde. Tras F29: `SaleReturnLoyaltyTest` (9 tests, 79 aserciones); regresión Devoluciones+F28+Loyalty+POS-Loyalty (228 tests, 1481 aserciones) en verde.

Las denominaciones F18A–F18F se usaron durante el desarrollo pero no están etiquetadas dentro del repositorio; no inventar correspondencia exacta de letras.

---

## Estado actual del Centro de Datos

Fuente de verdad: `docs/centro-datos/CENTRO_DATOS_CRONOGRAMA.md` y `docs/centro-datos/Cronograma_Maestro_Centro_de_Datos_MVS_Commerce_v2.xlsx`.

- **D00 — Auditoría existente: COMPLETADO.**
- **D01 — Contratos de plantillas MYM: EN CURSO EN PARALELO.** No bloqueó el shell y sigue siendo obligatorio antes de crear importadores nuevos dependientes de formatos reales.
- **D02 — Centro de Datos base: COMPLETADO.** Entrada única mobile-first con Inicio, Importar, Exportar y Reportes; permisos existentes por capacidad y una sola entrada en la navegación compartida.
- **D03 — Caracterización Compras + blindaje Inventario: COMPLETADO.** Compras Excel/XML quedó cubierta directamente sin reemplazar su lógica; POST XML protegido. Inventario ahora valida y previsualiza sin mutar, usa stock/barcodes reales, resuelve catálogo por empresa y confirma transaccionalmente mediante `InventoryPostingService` con `inventario.ajustar`.
- **D09 — Exportadores esenciales: COMPLETADO.** XLSX/CSV de productos, clientes, proveedores, inventario, CxC, CxP y fidelización, aislados por empresa/sucursal y protegidos por exportación + lectura del dominio.
- **D10 — Centro de Reportes esenciales: COMPLETADO.** Reportes internos de Ventas, Inventario, Caja/Finanzas, Compras/Proveedores, Clientes y Fidelización, con filtros empresariales, permisos por dominio y enlaces a D09.
- Evidencia: `DataCenterShellTest` 6/6 (47 aserciones), regresión navegación/Compras 39/39 (210 aserciones), build Vite y `git diff --check` correctos.
- D04–D08 permanecen pendientes de contratos/plantillas MYM de D01. **SIGUIENTE EN ORDEN: D11–D12 — Históricos opcionales**, no iniciados y sujetos a necesidad/contratos aprobados.

---

## Rama actual

`feature/pos`

## Estado del repositorio

R02 (POS móvil + escaneo) COMPLETADO y R03 (Productos/Inventario móvil + cámara) COMPLETADO. R03 añadió: `ProductController::search()` enriquecido (busca en `product_barcodes` secundarios, retorna `sale_price`, `cost`, `branch_stock`), `productos/index.blade.php` responsive mobile-first (tarjetas con código/precio/stock, cámara junto al buscador, listener `mvs-scan`), `inventario/index.blade.php` responsive mobile-first (tarjetas con stock/mín/máx y estado, cámara, listener `mvs-scan`), ambos incluyen `<x-scanner.mvs-scanner />`. Sin backend adicional (se reutilizó `productos.search`). Sin header/sidebar. Evidencia: `PosCameraScannerTest` 9/9, regresión POS/Loyalty: 180 tests / 1126 aserciones, mismos fallos preexistentes; `npm run build` correcto. Siguiente fase responsive: **R04**; siguiente fase funcional de Fidelización: **F39** (orden intacto). Verificar siempre con `git status` antes de trabajar.

## Objetivo actual

Continuar el desarrollo de las prioridades principales:

1. POS.
2. Fidelización.

Mantener Caja estable e integrar correctamente los módulos existentes.

## Último trabajo terminado

Según historial reciente de commits en esta rama:

- integración de fidelización en POS (`7be1f80`), incluida auditoría con 152 tests de Loyalty / POS-Loyalty sin fallos;
- canje de puntos de fidelización (`8392dd4`);
- pedidos internos (`Order`) y órdenes de compra con conversión a compras;
- integración de caja con POS;
- P23/P24 transferencias: auditoría de la implementación existente + pruebas en `InventoryTransferP24Test` (7/7, 54 aserciones), centralización del movimiento de stock/Kardex en `InventoryPostingService::postTransfer` (4 decimales, locking, rollback atómico) y scoping/permiso por sucursal; decisión: transferencia instantánea (no envío/recepción);
- P31–P36 adelantados por autorización: infraestructura reutilizada y flujos completos de Clientes, Productos, Ventas históricas e Inventario/Kardex; P36 separa saldo inicial de historia sin impacto actual;
- documentación: `INTEGRACIONES.md` completado, módulos nuevos registrados en arquitectura/progreso y este archivo creado.

## Trabajo en curso

- Puesta en Producción: **P01–P25 y P31–P40 COMPLETADOS** (P31–P40 adelantados por autorización expresa). P25 unificó la navegación tenant en barra inferior para escritorio/tablet/móvil, mantuvo Panel Maestro separado y corrigió geografía/logo del onboarding solicitados. Evidencia P25: SQLite 28/28, 163 aserciones; compatibilidad PostgreSQL estática OK, ejecución real pendiente antes de producción; 3 fallos históricos de `PosAccessAndSearchTest` fuera de alcance. **P26 SIGUIENTE BLOQUE OFICIAL**. P40 solo documentó/probó el procedimiento; no ejecutó PostgreSQL ni producción. **Regla producción: desarrollo → validación local del usuario → APROBADO PARA PRODUCCIÓN → despliegue controlado.**
- Centro de Datos: D00, D02, D03, D09 y D10 completados; D01 continúa en paralelo con plantillas MYM. D04–D08 permanecen bloqueados por contratos; D11–D12 no se iniciaron.
- Fidelización: **cronograma F01–F45 completo**; no existe una fase siguiente dentro del maestro vigente.
- R01 — Navegación responsive: COMPLETADO (`9c03912`).
- R02 — POS móvil + escaneo: **COMPLETADO** (R02-A + R02-B escáner por cámara).
- R03 — Productos/Inventario móvil + cámara: **COMPLETADO** (responsive mobile-first, cámara integrada en ambas vistas, `productos.search` enriquecido). Pendiente commit junto con R02. Siguiente fase responsive: **R04**.
- POS: expansión activa (uno de los módulos principales).
- Configuración de OpenCode como agente alternativo para trabajar este repositorio.

## Próximo paso

Antes de programar cualquier tarea nueva:

1. leer `AGENTS.md`, `docs/PROGRESO.md` y este archivo;
2. verificar `git status`, rama y último commit;
3. inspeccionar el código real del módulo afectado;
4. confirmar con el usuario cuál es la tarea concreta si no está definida.

**Prioridad inmediata: P26 — Nombres claros 58 mm, 80 mm, Carta, etc. P31–P40 quedaron completados adelantadamente por autorización expresa y no desplazan P26–P30.**

No asumir que el último estado conocido sigue vigente.

## Archivos o módulos relevantes

- POS: `PosController`, `PosSaleProcessor`, `Sale`, `SaleItem`, `SalePayment`.
- Fidelización: `app/Services/Loyalty/*`, `LoyaltyAccount`, `LoyaltyMovement`, `LoyaltyMovementLine`, `LoyaltyReward`, `LoyaltyRewardRedemption`, `LoyaltyPromotion`.
- Caja: `app/Services/Cash/*`, notificaciones por correo con reintentos.
- Pedidos/órdenes: `OrderService`, `PurchaseOrderPreparationService`, `PurchaseOrderConversionService`.
- Apartados: `LayawayService`. Devoluciones: `SaleReturnService`. Pagos a proveedores: `AccountsPayableService`.

## Pruebas importantes

Suite principal: `tests/Feature`.

- POS: `PosCheckoutTest`, `PosSuspendedSalesTest`, `PosCashSessionIntegrationTest`, `PosAccessAndSearchTest`.
- Navegación: `ResponsiveNavigationTest`, `LoyaltySettingsSidebarNavigationTest`.
- Fidelización: `tests/Feature/Loyalty*Test.php` (incluye `LoyaltyExpirationTest`, `LoyaltyExpirationSettingTest`, `LoyaltyCustomerPortalTest`, `LoyaltyPortalAccessTest`, `LoyaltyPortalAccessQrTest`, `LoyaltyPromotionTest`, `LoyaltyOnlineSaleTest`, `LoyaltyOnlineRedemptionTest`), `PosCheckoutLoyaltyPointsRequestTest`, `PosCheckoutLoyaltyRedemptionTest`, `PosLoyaltyInterfaceTest`, `PosLoyaltyMixedPaymentsTest`, `SaleVoidLoyaltyTest`, `LoyaltySettingsSidebarNavigationTest`. Premios, disponibilidad, canjes y vencimiento: `LoyaltyRewardTest`, `LoyaltyRewardAvailabilityTest`, `LoyaltyRewardRedemptionTest`.
- Caja: `Cash*Test.php`.
- Módulos recientes: `Order*Test.php`, `PurchaseOrderTest`, `PurchaseOrderConversionTest`, `LayawayV1Test`, `SaleReturnTest`, `SaleVoidTest`, `AccountsPayable*Test.php`.

Ejecutar pruebas específicas más regresión razonable antes de declarar terminada una tarea.

## Riesgos / advertencias

- No usar floats para dinero ni puntos; precisión decimal obligatoria.
- Respetar aislamiento por empresa (`company_id`) y sucursal cuando corresponda.
- Los puntos de fidelización son globales entre sucursales de la misma empresa; `branch_id` es origen, no saldo.
- Las rutas `facturas` y `reportes` existen pero sus controladores están vacíos y sin permisos; no asumir funcionalidad.
- Recursos Humanos/Planilla y Contabilidad se desarrollan fuera de este repositorio: integrar, no duplicar.
- Puede haber trabajo de otros agentes en curso; revisar Git antes de modificar o respaldar.

## Fallos históricos conocidos (no atribuibles a P24)

Estos fallos son preexistentes de HEAD (deriva `backend–tests` documentada en `docs/PROGRESO.md` R02) y **no** son causados ni por P24 ni por sus archivos (`TransferController`, `InventoryPostingService`, `InventoryTransferItem`, rutas `transferencias*`, migración de precisión, `InventoryTransferP24Test`). No se corren en el alcance de P24; se dejan registrados para no confundirlos con regresiones nuevas:

- `PosAccessAndSearchTest::test_search_finds_by_name_and_returns_minimal_payload` — el payload de `pos.customers.search` incluye claves adicionales (`allows_decimals`, `is_offer`, `price_a`, `price_b`, `price_c`, `unit`, `wholesale_price`) que el test no espera. `PosController`, no modificado por P24.
- `PosAccessAndSearchTest::test_checkout_modal_has_responsive_permanent_summary_and_dynamic_direct_payment_flow` — el HTML del modal/checkout POS difiere del snapshot esperado. Vista `pos/index.blade.php`, no modificada por P24.
- `PosAccessAndSearchTest::test_customer_search_returns_only_the_authorized_minimal_fields` — el payload de `pos.customers.search` incluye claves adicionales (`credit_due_date`, `credit_used`, `price_level`, `public_code`) que el test no espera. Crecimiento del payload atribuible al trabajo Portal (P04–P09A), no a P24.

También documentados como deriva preexistente: `PosSuspendedSalesTest::test_recovery_revalidates_customer_product_price_tax_stock` (`125` vs `'125.00'`) y `OrderPosCreationTest::test_pos_button_and_cashier_product_payload_are_permission_safe` (302 vs 200); ambos en módulos POS/Órdenes no tocados por P24.

## Instrucción para el siguiente agente

Reconstruye el contexto desde el repositorio, nunca desde memoria conversacional:

1. lee `AGENTS.md` y `docs/`;
2. revisa `git status`, rama y últimos commits;
3. identifica el módulo y sus pruebas;
4. trabaja con cambios mínimos, ejecuta pruebas y deja el repo y la documentación en estado comprensible para el siguiente agente;
5. actualiza este archivo si tu tarea cambia rama, prioridades o deja trabajo a medias.
