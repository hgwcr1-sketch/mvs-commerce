# CobrOS 6B — Preparación local del piloto

2026-10-02: ver **Actualización 2026-10-02 — staging HTTPS** más abajo (fix
de `sw-cobros.js` + validación SW/manifest en staging; dispositivo real sigue
pendiente).

2026-10-02 (tarde). **Módulo CLIENTES cerrado localmente**: listado con búsqueda y saldo
del dueño en `/cobros/owner/customers` (crear, editar, detalle con CxC/pagos/recibos),
alta **Nuevo cliente** del cobrador con autoasignación a su cartera
(`/cobros/collector/customers`), drill-down del dueño por cobrador
(`/cobros/owner/collectors/{user}`) y enlace **Clientes** en la barra del shell.
Aislamiento cross-company (404) y por permisos (403); CobrOS no escribe
crédito/nivel de precio/geolocalización (siguen en Commerce, R01).
Evidencia: `CobrosCustomerModuleTest` **8/8 (61)** y regresión
`Cobros|CustomerCompanyIsolation` **319/319 (2559)**; pint PASS en los PHP tocados;
`git diff --check` PASS. Detalle en [PROGRESO_COBROS.md](PROGRESO_COBROS.md).
Sin migraciones, SSH, push, merge ni despliegue.

### Estado 6B — CERRADO vs REQUIERE APROBACIÓN / RECURSO EXTERNO (2026-10-02)

| CERRADO (local, con evidencia) | Fecha |
|---|---|
| Integración Vite del asset PWA (`@vite([... 'resources/js/cobros-pwa.js'])`) | 2026-10-02 |
| Fix de `public/sw-cobros.js` (`claim()` solo en `activate`) + validación de registro/alcance/cachés en staging HTTPS | 2026-10-02 |
| Precisión monetaria: rechazo de >4 decimales, acumulación BCMath exacta, `credit_limit` a escala 4 (`CobrosMoneyPrecision6BTest` 10/10) | 2026-10-02 |
| Cierre de cupo bajo `lockForUpdate()` en creación y reactivación de cobradores | 2026-10-02 |
| Módulo CLIENTES en CobrOS (dueño: lista/búsqueda/crear/editar/detalle; cobrador: **Nuevo cliente** autoasignado; drill-down por cobrador; aislamiento por empresa) | 2026-10-02 |
| Navegación del shell sin duplicados (barra + Más) | `5ddb018` |

| REQUIERE APROBACIÓN O RECURSO EXTERNO | Motivo de lo que falta |
|---|---|
| Registro automático del SW por la app y ciclo 2 de la PWA (cerrar sesión y reabrir) | Requiere sesión autenticada y dispositivo: **NO PROBADO / NO EJECUTADO** |
| Instalación real de la PWA (icono → `/cobros` → login → `/cobros/owner`, teclado, nombres largos) | Requiere teléfono físico: **NO EJECUTADO** |
| PostgreSQL real (locks `FOR UPDATE`, migraciones pobladas, FK/índices) | Entorno local = SQLite; credenciales desde el gestor autorizado: **NO DISPONIBLE** |
| GPS: consentimiento, finalidad, retención y purga/anonimización | Decisión de producto/usuario pendiente |
| Custodia: liquidación, entrega/aceptación e ingreso a caja | Definición operativa pendiente (excluido de 3B/3C) |
| Licencias/cupos: `customers.credit_limit` a 4 decimales, licencia individual por cobrador, facturación | Exigen migración o contrato nuevo (no autorizados) |
| Importación Excel/CSV de clientes dentro de CobrOS | El flujo vive en `importaciones.clientes.*` (layout Commerce): requiere decisión de alcance |
| Auditoría externa, validación del usuario y **APROBADO PARA PRODUCCIÓN** | Acciones externas; los agentes no despliegan producción |

2026-10-01 (Costa Rica). **Validación local 6B cerrada; BLOQUEADO para piloto
financiero**. 6B/Definition of Done de producción NO completada. Base de cierre:
`feature/cobros-v1`, `08fba58`. Sin cambios funcionales, SSH, push, merge ni
despliegue. Base de revisión, servidor y temporales previos preservados. No se
consultó la base maestra.

### Actualización 2026-10-02 — solo staging HTTPS (nada de esto sustituye el móvil físico)

| Validación | Resultado hoy (2026-10-02) |
|---|---|
| `public/sw-cobros.js` | **CORREGIDO (hallazgo real) + PROBADO**: `await self.clients.claim()` dentro de `oninstall` se rechazaba porque al primer install no hay worker activo; el `waitUntil` fallaba, el worker pasaba a `redundant` y Chrome **borraba el registro** (era el síntoma “Registration has no worker”). Fix mínimo: `claim()` solo en `activate`. Staging con respaldo `public/sw-cobros.js.bak-sw-20261002-131416`. |
| Registro/activación/scope en `https://cobros-staging.mvscommerce.com` | **PROBADO**: scope `…/cobros`, estado `activated`, página controlada por `sw-cobros.js`, script responde 200. |
| Cachés del SW | **PROBADO**: solo `cobros-pwa-v2-static` con `/manifest-cobros.json` + 2 iconos CobrOS; ningún HTML ni respuesta autenticada. |
| Aislamiento | **PROBADO**: rutas fuera de `/cobros` (p. ej. `/dashboard`) no son interceptables por el SW. |
| Fallback offline GET | **PROBADO** con emulación de red sobre página + SW: `/cobros/login` devuelve 503 “Sin conexión”. No equivale a pérdida física de señal en un teléfono. |
| Manifest/CTA/iconos CobrOS | **PROBADO**: toda página `/cobros` declara `manifest-cobros.json` (`id`/`start_url`/`scope` = `/cobros`, 4 iconos), cero referencias a `manifest.json`; `assets/cobros-pwa-DZqbccKS.js` responde 200 y su fallback de instalación abre `/cobros` (antes `/`). `manifest.json` de Commerce **no** se modificó: su `scope` no admite excluir `/cobros` y limitarlo rompería sus rutas. |
| Integración Vite del asset PWA (brecha “Layout pide `/js/cobros-pwa.js`”) | **CERRADA**: `@vite([... 'resources/js/cobros-pwa.js'])` genera y sirve `assets/cobros-pwa-DZqbccKS.js`. |
| Página CobrOS sin `Dashboard`/`Fidelización` (móvil emulado 360 px, UA Android) | **PROBADO** en `/cobros/login` (invitado): sin “Dashboard”, sin “Fidelización”, sin overflow horizontal, marca CobrOS presente. `/cobros` y `/cobros/owner` redirigen a `/cobros/login` sin sesión. |
| Registro automático del SW por la app (`cobros-pwa.js` en el layout) | **NO PROBADO**: el bundle solo se carga en el layout autenticado y no se entregan credenciales al agente; el probe registró con los mismos parámetros (`/sw-cobros.js`, scope `/cobros`). |
| Ciclo 2 (cerrar sesión y reabrir la PWA) | **NO EJECUTADO**: requiere sesión autenticada y dispositivo. |
| Dispositivo real: icono → `/cobros` → login → `/cobros/owner` | **NO EJECUTADO**: requiere móvil físico con el PWA instalado. |
| PostgreSQL, auditoría externa, custodia, licencias/cupos, precisión monetaria, GPS, aprobaciones | **PENDIENTES**, sin cambios hoy. |

Evidencia temporal (no commiteada): `tmp_6b_staging_pwa.cjs/.txt`,
`tmp_6b_sw_diag2.txt`, `tmp_6b_sw_install_test.cjs/.txt` (experimento A/B:
original falla en install; sin `claim()` en install queda `activated`).
Pruebas locales: `CobrosSecurityHardeningTest|CobrosModuleAccessTest|CobrosManifestTest`
→ 20/20 (173 aserciones) y `node tests/js/cobros-6b-offline.cjs` → PASS.
No se modificaron roles, BD, `.env`, navegación (`5ddb018`) ni producción.

### Cierre local de navegador (HEAD 08fba58)

`node tests/js/cobros-6b-browser.cjs`: **PASS** en las nueve vistas comprobadas
(formulario, vista previa y recibo; anchos configurados 360/768/1280), sin
overflow horizontal. Se acotó la espera de activación del service worker:
**NO DISPONIBLE** en este entorno, se detectó el registro con scope `/cobros`
pero sin worker activo (`Registration has no worker`). La prueba continuó y
validó las vistas; no certifica modo offline/PWA en navegador.

PostgreSQL: **NO DISPONIBLE**; el entorno local está configurado con SQLite.
No se ejecutaron build ni suites en este cierre. `git diff --check`: PASS.
La aprobación visual/device, auditoría, PostgreSQL, aprobación del usuario y
requisitos restantes de piloto/producción siguen pendientes.

## Evidencia y límites

| Validación | Resultado |
|---|---|
| Suite `Cobros\|CustomerCompanyIsolation`, SQLite `:memory:` | 279/279, 2277 aserciones, antes de agregar pruebas 6B |
| `CobrosPreparation6BTest` | 2/2, 21 aserciones: fallo antes de commit → 500 sin redirect ni filas financieras; retry HTTP tras descartar respuesta → mismo recibo |
| `tests/manual/cobros-6b-concurrency.php` | PASS: dos procesos con barrera antes del INSERT real, mismo token/cuenta/payload; uno aplica y otro recupera pago 1/recibo 1; saldo 3000 → 2000; cero filas de caja |
| Respuesta perdida | Retry por servicio y HTTP simulado, un pago/recibo. No equivale a desconexión física ni doble toque en teléfono |
| `tests/js/cobros-6b-offline.cjs` | PASS: worker real en VM con red fallida devuelve 503; no intercepta POST/Commerce; sync solo notifica, no paga ni simula éxito |
| Migraciones SQLite | Cadena completa aplicada a archivo vacío exclusivo del probe; no prueba actualización de PG poblado |
| Build | `npm.cmd run build` PASS; no demuestra existencia de todos los assets referenciados |
| Estilo/sintaxis | Pint de los dos PHP nuevos PASS; `node --check` del probe navegador PASS |
| Navegador 6B / vistas responsive | **PASS**: nueve combinaciones (formulario, vista previa, recibo × 360/768/1280), `scrollWidth <= clientWidth`; no certifica instalación real ni todos los dispositivos |
| Activación service worker en navegador local | **NO DISPONIBLE**: registro `/cobros` hallado sin worker activo (`Registration has no worker`); la espera está acotada y no impide validar vistas |
| PostgreSQL | **NO DISPONIBLE**: configuración de base del entorno local es SQLite; validación PG no ejecutada |
| Intentos CDP previos | Los dos intentos previos fallaron con `CDP timeout Page.enable`; el probe actualizado completó las vistas en el cierre local |
| Suite global, auditor externo, staging y usuario | No certificados por esta tarea; suite focal verde no significa aprobación global |

Base de concurrencia preservada:
`%TEMP%/cobros-6b-1ff8cd609a242383/exclusive.sqlite`.
Cada ejecución crea otra base aislada, fuerza entorno/conexión/cache propios y
conserva los pagos. Logs fuera del commit: `tmp_6b_suite.txt`,
`tmp_6b_build.txt`, `tmp_6b_concurrency.txt`, `tmp_6b_browser.txt`.

Reproducción local sin instalar dependencias:

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL=''
$env:COBROS_6B_HTML=$env:TEMP
php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --filter CobrosPreparation6BTest
php tests/manual/cobros-6b-concurrency.php
node tests/js/cobros-6b-offline.cjs
node tests/js/cobros-6b-browser.cjs
```

El probe navegador sirve fixtures PHPUnit y assets públicos en puerto efímero,
rechaza escrituras y no usa la base/sesión del servidor de revisión. Comprueba
captura/confirmación/recibo a tres anchos, métricas clientWidth/scrollWidth,
targets y, si el worker se activa, pruebas SW/red. La disponibilidad local del
worker no condiciona el resultado responsive; tampoco sustituye E2E real.

## Migraciones y PostgreSQL

Inventario revisado en `database/migrations/` (prefijos completos y orden):

| Migración | Operación `up()` | Recuperación |
|---|---|---|
| `2026_09_20_000001_add_cobros_contract_fields_to_company_modules_table` | Campos nullable modalidad/cupo/admin; revisar contratos NULL existentes | Mantener columnas |
| `2026_09_22_000001_create_cobros_assignments_table` | FK y UNIQUE empresa/cliente | Mantener asignaciones |
| `2026_09_22_000002_create_cobros_assignment_logs_table` | Bitácora, FK e índices | Mantener auditoría |
| `2026_09_22_000003_provision_cobros_asignar_permission` | DML aditivo permiso/pivotes | No revocar automáticamente permisos históricos |
| `2026_09_23_000001_create_cobros_visits_tables` | Visitas/logs, decimal(19,4), FK e índices | Mantener visitas/logs |
| `2026_09_23_000002_add_location_snapshot_to_cobros_visits` | Coordenadas decimal y fecha nullable | Retención pendiente de definición |
| `2026_09_30_000001_make_ar_payment_cash_session_nullable` | Relaja NOT NULL; requiere ALTER/lock en PG | NO down: custodia tiene NULL |
| `2026_09_30_000002_create_cobros_receipts_table` | UNIQUE empresa/token, empresa/número y payment_id; FK restrict; decimal(19,4) | NO drop de recibos/idempotencia |
| `2026_09_30_000003_create_cobros_payment_reversals_table` | Reversos, payment_id único, FK restrict, decimales | NO drop de reversos |
| `2026_09_30_000004_add_annulment_fields_to_cobros_receipts` | Campos nullable y FK | Mantener anulaciones |
| `2026_09_30_000005_provision_cobros_anular_permission` | Permiso/pivotes dueño-supervisor | Conservar y auditar |
| `2026_09_30_000006_provision_notificaciones_cobros_permission` | Permiso/pivotes notificaciones | Conservar y auditar |

Schema/Query Builder, booleanos, timestamps y FK sin SQL MySQL específico en
estos cambios. `after()` no garantiza orden físico en PG. UNIQUE admite los NULL
temporales de recibo/payment_id dentro de transacción; verificar que no queden
recibos incompletos tras commit. Los `up()` son aditivos/relajación; muchos `down()`
destruyen datos. Falta ensayo sobre copia PG poblada con FK, índices, locks,
fallo transaccional y carrera real; no se declara compatibilidad ejecutada.

`AccountsReceivableService::applyPayment` bloquea cuenta con `lockForUpdate` y
revalida saldo. `SecurePaymentService` reserva recibo bajo UNIQUE y recupera
conflicto fuera de transacción. SQLite no certifica aislamiento/locks PG.
El probe multiproceso está limitado a SQLite; adaptar/verificar su destino
antes de ensayarlo en PG exclusivo. `phpunit.postgresql.xml` NO fija una base:
no ejecutarlo sin fijar y verificar base vacía exclusiva y credenciales limitadas
(RefreshDatabase puede destruir el destino). No se reutilizan credenciales locales.

## Brechas frente a V1

| Área | Existente | Brecha / condición de cierre |
|---|---|---|
| PWA (5B) | Manifest, worker público y fuente JS | ~~Layout pide `/js/cobros-pwa.js`…~~ **Cerrado 2026-10-02:** el layout usa `@vite([…, 'resources/js/cobros-pwa.js'])`, el build publica `assets/cobros-pwa-DZqbccKS.js` (200) y el registro/activación del SW en HTTPS de staging quedó probado (ver Actualización 2026-10-02). Falta: registro automático por la app en layout autenticado y prueba en dispositivo real |
| Mala señal | Replay con mismo token; no pago offline | No se encontró consumidor operacional de captureDraft/sync-ready en vistas. Captura nueva genera token nuevo: no certificada recuperación tras cierre/recarga. Banner se incluye online=true y no renderiza aviso por defecto |
| Precisión | Gate CobrOS usa BCMath, columnas decimal(19,4) | **CERRADA 2026-10-02.** Los 3 hallazgos corregidos en `AccountsReceivableService` sin floats ni `round()`: (1) `roundedAmount()` half-up eliminado → `exactAmount()` con la misma regla que `SecurePaymentService::amount()` (**>4 decimales se RECHAZA**, no se redondea); (2) `sum('balance_due')` (float de la base, pérdida real a partir de 10 dígitos enteros: `(string)` de `10000000000.0002` daba `10000000000` y sobreaprobaba 0.0002) → acumulación exacta string/BCMath fila a fila; (3) `bccomp(credit_limit,…)` pasa de escala 2 a escala 4. Tests: `CobrosMoneyPrecision6BTest` **10/10, 51 aserciones** (cero, fracciones de colón, `10.00005` rechazado en ambas capas, sumas fraccionarias, magnitud grande, límite exacto, límite 0). Residual con aprobación pendiente: la columna `customers.credit_limit` sigue `decimal(15,2)` — llevarla a 4 decimales exige migración (no autorizada en esta tarea). `admin_license_charged` no implica facturación |
| Liquidación | Custodia, recibos, reversos y reportes netos | No hay entrega/aceptación/conciliación e ingreso a caja. Excluido de 3B/3C, pero arquitectura exige liquidación antes de caja. Definir operación del piloto; nunca registrar otro pago para liquidar |
| Licencias/cupos | Individual/company_cupo, Platform Admin, altas por dueño según contrato | **Parcialmente cerrado 2026-10-02:** `createCollector` y `toggleCollector` (reactivar) ahora revalidan `collector_limit` DENTRO de la transacción y después de `lockForUpdate()` sobre `company_modules`, con test que simula el cupo tomado entre la comprobación previa y el lock (`CobrosCollectorManagementTest`). Nota: en PostgreSQL el lock serializa; en SQLite (dev) la grammar no emite `FOR UPDATE`, así que la garantía real es de staging/producción. Sigue abierto: contrato por empresa sin licencia individual de vigencia propia por cobrador; altas concurrentes sin lock contractual quedan protegidas solo por este lock; `admin_license_charged` no implica facturación |
| Standalone | Login/navegación propios, User y monolito compartidos según V1 | No significa backend/base separados. Instalación display:standalone, retorno/sesión/actualización en teléfono pendientes; no crear identidad paralela |
| Dispositivos | Auth/sesión, permisos, revocación y rate limiting | Política de confianza, pérdida/robo, sesiones simultáneas y retiro pendientes; sin certificación Android/iOS/GPS real |
| GPS | Ubicación cliente y snapshot visita | Falta plazo/finalidad, consentimiento operativo, acceso, backups y purga/anonimización autorizada preservando pagos/auditoría. No borrar GPS/pagos aquí |
| Responsive | CSS/markup mobile-first, controles 44/48/56px | Prueba local 6B PASS en formulario, vista previa y recibo a 360/768/1280 sin overflow horizontal; esto no certifica dispositivo real. Revisar teclado, nombres largos, menú y pantallas financieras en dispositivo real antes del piloto |
| Clientes | CRUD de Commerce + asignación de cartera CobrOS | **CERRADO 2026-10-02** en CobrOS: listado con búsqueda y saldo, crear/editar/detalle del dueño (`cobros.owner.customers.*`), **Nuevo cliente** del cobrador con autoasignación (`cobros.collector.customers.*`), drill-down por cobrador (`cobros.owner.collectors.show`), aislamiento cross-company (404) y por permisos (403), sin escritura de crédito/precio/geolocalización. Queda fuera: importación Excel/CSV (flujo de Commerce, decisión de alcance) y edición de crédito/nivel de precio (sigue en Commerce, R01) |

La arquitectura inicial menciona licencia por ruta; 1E-A/1E-B documentan después
individual/company_cupo. Se registra evolución/brechas, sin cambiar contratos,
renumerar fases ni implementar funciones nuevas.

## Aplicación futura: procedimiento NO ejecutado

### Validación reproducible en staging PostgreSQL + HTTPS

Ejecutar solo por el operador autorizado en staging aislado; no usar producción
ni la base maestra. Definir en el entorno (sin incluir secretos en comandos,
archivos, historial o logs): `PGHOST`, `PGPORT`, `PGDATABASE`,
`PGUSER`, `PGSSLMODE`, `BACKUP_FILE`, `RESTORE_DB`,
`APP_ENV=testing`, `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`,
`DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` (inyectado desde el gestor
aprobado de secretos). Verificar host, base, usuario y permiso antes de cada
operación; detenerse si el destino no es inequívoco.

1. **Respaldo y restauración:** suspender escrituras de prueba; crear dump
   custom con `pg_dump --format=custom --file="$BACKUP_FILE" --dbname="$PGDATABASE"`
   usando la conexión/variables aprobadas. Comprobar archivo, checksum y
   `pg_restore --list "$BACKUP_FILE"`. Restaurar a una base vacía, aislada y
   desechable `RESTORE_DB` con `pg_restore --exit-on-error --dbname="$RESTORE_DB"
   "$BACKUP_FILE"`; comprobar esquema, FK, conteos y sumas de pagos/recibos/
   reversos/saldos/caja. No probar restauración encima de staging ni de una
   base con pagos posteriores.
2. **Migraciones de staging:** respaldar staging antes de migrar y ensayar
   primero en la restauración aislada. Con el release aprobado y la conexión
   verificada, ejecutar `php artisan migrate:status`; comparar TODOS los
   pendientes con la lista autorizada. Solo si coincide y el respaldo es
   restaurable, ejecutar `php artisan migrate --force`; después repetir status
   y conciliación. No usar `migrate:fresh`, `refresh`, `reset` ni rollback SQL.
3. **Pruebas de pago:** crear otra base PostgreSQL vacía y exclusiva para tests,
   con usuario limitado; `CobrosPaymentTest` usa `RefreshDatabase` y puede
   reinicializarla. Configurar `APP_ENV=testing` y `DB_*` solo para esa base,
   verificar el destino y correr
   `php artisan test tests/Feature/CobrosPaymentTest.php`. No apuntar tests a
   staging restaurado/poblado. Confirmar idempotencia, importes DECIMAL,
   recibo único, saldo y cero sesiones/movimientos/efecto de caja en custodia.
4. **Service Worker HTTPS:** publicar únicamente en staging HTTPS con el asset
   PWA integrado en Vite. En navegador compatible, abrir `/cobros`, confirmar
   que `/sw-cobros.js` responde, que Application/Service Workers muestra
   registro bajo `/cobros` en estado `activated` y que la página queda
   controlada. Recargar y actualizar el release; validar assets públicos y
   fallback GET offline. Enviar solo una solicitud POST sintética sin datos ni
   operación financiera y confirmar que no se almacena/reproduce pago offline;
   verificar que rutas fuera de `/cobros` no quedan interceptadas. Registrar
   navegador, versión, URL HTTPS, scope y evidencia sin datos sensibles.
5. **Rollback:** suspender escrituras y conservar logs/token/recibos y snapshot
   posterior. Revertir solo código/assets a release compatible, manteniendo el
   esquema ampliado; jamás ejecutar `migrate:rollback`/`down` de 3B/3C ni
   restaurar el dump encima de datos posteriores. Si hay discrepancias, mantener
   cobros suspendidos, restaurar el dump en una base aislada y reconciliar
   pagos posteriores antes de decidir corrección hacia adelante. Reabrir solo
   tras conciliación y aprobación documentada.

1. **Precondiciones.** Cerrar bloqueos, revisión de auditor, pruebas focales y
   globales evaluadas separadamente, dispositivo y staging, validación del usuario
   y `APROBADO PARA PRODUCCIÓN`. Registrar SHA exacto nuevo/anterior compatible,
   destino/base/esquema y responsable. Verificar PHP/extensiones/lockfiles. No
   asumir pendientes de producción por los seis pendientes históricos de 6A.
2. **Respaldo.** Ventana autorizada, suspender nuevas escrituras financieras y
   drenar solicitudes; snapshot coherente:
   `pg_dump --format=custom --file=<backup.dump> --dbname=<destino-verificado>`.
   Credenciales seguras sin contraseña en CLI/logs. Preservar clave existente,
   configuración, archivos privados y release anterior con acceso restringido.
   Verificar salida, tamaño/checksum y `pg_restore --list`; ensayar restauración
   con `pg_restore --exit-on-error --dbname=<base-aislada-vacia> <backup.dump>`.
   Listar no sustituye restaurar. Conciliar conteos y sumas DECIMAL de pagos,
   recibos, reversos, CxC, caja y secuencias. Definir RPO/RTO, custodio y retención.
3. **Staging/migración.** Release aislado, dependencias desde lockfiles sin
   actualizar versiones; `npm ci` y `npm run build` bajo autorización futura.
   Verificar manifest y URLs de assets, especialmente JS CobrOS. Comprobar
   configuración/destino antes de `php artisan migrate:status`; revisar TODOS
   los pendientes, incluidos ajenos. Si no coinciden con la lista aprobada,
   detener. Ensayar copia PG; `php artisan migrate --force` solo tras revisión y
   backup restaurable. No fresh/refresh/reset/rollback/seed global. Revisar locks,
   timeout, FK/índices; repetir status y conciliación.
4. **Release/build.** Publicar SHA y assets como unidad mediante runbook del
   destino. Regenerar caches config/rutas/vistas y reiniciar workers según
   arquitectura verificada; conservar APP_KEY/sesiones y release anterior.
5. **Smoke.** Login por rol; licencia/módulo inactivo; dos empresas y sucursales;
   cartera asignada; captura sin pago; parcial/total/recibo; doble envío y retry
   con mismo token; conflicto de payload; reverso autorizado; reportes/alertas;
   caja intacta. Escrituras solo en tenant de prueba autorizado. En móvil:
   instalación, tres anchos, pérdida antes de enviar/después de commit, recuperar
   recibo sin token nuevo, logout/cambio de usuario sin borradores ajenos.
6. **Monitoreo.** Responsable en ventana y primer ciclo; revisar 5xx/409/419/429,
   UNIQUE/locks, recibos incompletos, duplicados de token, pagos sin recibo,
   saldos vs pagos/reversos y custodia vs caja. No loguear credenciales/GPS
   innecesarios. Duplicado, éxito falso o inconsistencia monetaria suspende
   escrituras y activa recuperación; login/build correcto no permite reapertura.

## Recuperación sin borrar pagos

1. Suspender nuevas escrituras CobrOS con procedimiento autorizado; conservar
   logs, tokens, recibos y snapshot posterior al fallo. No retry con token nuevo
   ni correcciones de saldo por SQL.
2. Preferir corrección hacia adelante. Volver a código/assets anteriores solo
   tras verificar que entienden cash_session_id=NULL, recibos y reversos; mantener
   esquema ampliado. Sin versión compatible, mantener cobros suspendidos y
   consulta por mecanismo validado.
3. Nunca ejecutar down de 3B/3C: borran recibos/reversos o imponen NOT NULL sobre
   custodia. Nunca restaurar backup anterior encima de pagos posteriores.
4. Ante corrupción, restaurar en base aislada y reconciliar pagos/reversos
   posteriores mediante identificadores, tokens, auditoría y comprobantes.
   Preservar original/secuencias; corte solo con aprobación y doble verificación
   contable. Evidencia insuficiente no significa pago inexistente ni éxito.
5. Pago erróneo: anulación por flujo existente con permiso, motivo y reverso;
   nunca DELETE. Reabrir tras conciliación sin diferencias, smoke y aprobación.

## Expediente final y siguiente acción del operador

**Estado: BLOQUEADO para piloto financiero; listo para validación externa.**
Bloqueadores de salida:

- PostgreSQL: pendiente; ejecutar revisión/migraciones y pruebas autorizadas en
  una base aislada y verificada, con respaldo restaurable; conciliar pagos,
  recibos, reversos, saldos y caja. No usar la base maestra para ensayos.
- Service worker: **cerrado 2026-10-02** — integración de asset PWA verificada
  y registro/activación/alcance `/cobros`/fallback offline probados en staging
  HTTPS tras corregir `clients.claim()` en `install`. Quedan sin probar: la
  actualización de versión del SW, el registro automático hecho por la app en el
  layout autenticado y el comportamiento en dispositivo real.
- Auditoría externa independiente: revisar seguridad, aislamiento, permisos,
  idempotencia y trazabilidad; registrar informe y remediar hallazgos bloqueantes.
- Dispositivo real: completar prueba supervisada en móvil compatible, incluida
  instalación, sesión, responsive, pérdida/recuperación de conexión y recibo.
- Cupos/licencias: dueño de producto/Platform Admin define contrato y vigencia;
  verificar límites, concurrencia de altas y reactivación, con pruebas aprobadas.
- Custodia: responsable financiero define entrega, aceptación, conciliación y
  tratamiento de fondos antes de habilitar operación piloto o caja.
- Resolver además la brecha de precisión monetaria en el servicio CxC compartido;
  no habilitar pagos de piloto mientras permanezca sin corregir y verificar.

El operador debe coordinar y registrar evidencia de cada validación anterior,
obtener aprobación del responsable financiero y del usuario, y solicitar
`APROBADO PARA PRODUCCIÓN` explícito antes de cualquier despliegue. No entregar
contraseñas al agente ni ejecutar SSH/producción como parte de este expediente.
Preparación y PASS local responsive no equivalen a autorización de piloto.

## Paquete de preparación para staging — `cobros.mvscommerce.com`

Paquete candidato en rama `feature/cobros-v1`, SHA
`3cfc01ab6dac111ec79fa73d1b73eeb70b461585`
(`feat(cobros): preparar candidato de release 6B`). El commit contiene únicamente:

- `app/Services/Sales/AccountsReceivableService.php`
- `tests/Feature/CobrosPaymentTest.php`
- `resources/views/cobros/layout.blade.php`
- `vite.config.js`

El build Vite local fue validado para este candidato; el manifest genera la
entrada `resources/js/cobros-pwa.js` como `assets/cobros-pwa-35ym0p0z.js`.
El release debe construirse desde el SHA exacto con lockfiles (`npm ci`,
`npm run build`) y verificar nuevamente manifest y archivos publicados. No
incluir cambios de worktree ni `output.txt`, `tmp_*.txt` o
`tmp_generate_cobros_icons.php`; son temporales locales y quedan fuera.
No incluir `.env`, secretos ni respaldos.

Las migraciones CobrOS versionadas a revisar son las 12 entradas de la tabla
“Migraciones y PostgreSQL” de este documento. El SHA no implica que deban
ejecutarse todas: en staging el operador debe revisar `migrate:status`, comparar
todo lo pendiente con la lista autorizada de ese destino y detenerse ante una
diferencia.

### Checklist del operador (staging únicamente)

- [ ] **DNS:** proporcionar proveedor/administrador responsable, registros
  A/AAAA/CNAME previstos, IP o destino de staging autorizado y TTL; verificar
  resolución desde fuera antes del corte. No cambiar registros desde esta tarea.
- [ ] **Nginx:** confirmar host y release root (`public/`), PHP-FPM/socket y
  versión, `try_files` de Laravel, acceso de escritura limitado, bloqueo de
  `.env`/archivos ocultos y logs. Revisar configuración antes de recargar.
- [ ] **SSL:** disponer de certificado válido para `cobros.mvscommerce.com`,
  cadena completa y renovación automática; verificar HTTPS y redirección HTTP
  sin habilitar HSTS hasta validar todo el dominio.
- [ ] **`.env`:** proporcionar solo valores no secretos y referencias del gestor
  seguro para `APP_ENV`, `APP_URL`, conexión PostgreSQL, cache, session, queue,
  mail y filesystem. Confirmar `APP_DEBUG=false`, `APP_KEY` existente y
  extensiones PHP requeridas (`pdo_pgsql`, `bcmath`). No enviar valores secretos.
- [ ] **PostgreSQL:** host/puerto/nombre de base de staging, versión, TLS,
  propietario/responsable y usuario de mínimo privilegio; identificar bases
  separadas para staging, restauración aislada y pruebas destructivas con
  `RefreshDatabase`. Confirmar retención y destino/custodio del respaldo.
- [ ] **Storage:** definir volumen persistente, propietario/permisos mínimos,
  `storage:link` esperado, espacio, backup y restauración de archivos privados;
  no sincronizar datos reales de clientes a staging sin autorización.
- [ ] **Colas:** indicar driver, workers/supervisor, límites de memoria/tiempo,
  política de reintentos y monitoreo. Acordar reinicio controlado tras release;
  comprobar que no se procesan trabajos duplicados.
- [ ] **Scheduler:** confirmar mecanismo de scheduler, usuario y zona horaria;
  programar la ejecución Laravel requerida una vez por minuto con bloqueo de
  servidor único donde corresponda; revisar logs y evitar duplicar cron.
- [ ] **Migraciones:** operador compara `migrate:status` con la lista aprobada,
  revisa lock/DDL en copia PostgreSQL y detiene si aparece cualquier migración
  inesperada. Ejecutar solo tras respaldo restaurable y autorización.
- [ ] **Respaldo/rollback:** registrar SHA anterior/nuevo, ventana, responsable,
  archivo/checksum, restauración aislada probada, RPO/RTO y conciliación.
  Rollback preferente a código/assets compatibles manteniendo esquema ampliado;
  nunca ejecutar down/rollback de 3B/3C ni restaurar sobre pagos posteriores.

### Datos que debe aportar el operador y validaciones posteriores

Antes de iniciar staging, el operador entrega: SHA de release aprobado; hostname,
DNS esperado e IP/destino de staging; responsable de Nginx/PHP-FPM y SSL; versiones
de PHP/extensiones/PostgreSQL; referencias seguras a configuración/credenciales
(nunca contraseñas); nombres de bases aisladas y permisos; rutas de storage,
drivers/administradores de colas, scheduler/zona horaria; política de backup,
ventana/RPO/RTO; lista autorizada de migraciones; tenant/usuarios de prueba y
responsables de aprobación financiera, funcional y técnica.

Después, en staging se verificará DNS/TLS/cadena HTTPS, Nginx y health/login,
permisos y persistencia de storage, worker/scheduler, backup con restore aislado,
migraciones antes/después y conciliación DECIMAL; luego
`CobrosPaymentTest` solo contra base de test vacía y exclusiva, incluyendo
idempotencia y custodia/caja. Finalmente se comprobará el SW activo/controlando
`/cobros`, assets, actualización, fallback offline y que POST no registra/repite
pagos, seguido de smoke en móvil y firma de auditoría/aprobaciones. Todo resultado
que no pueda evidenciarse queda pendiente; piloto y publicación siguen bloqueados.

### Orden exacto de ejecución en el servidor de staging

1. Confirmar SHA aprobado
   `3cfc01ab6dac111ec79fa73d1b73eeb70b461585`, hostname/IP de staging,
   responsables, ventana, DNS esperado y que el destino no es producción.
2. Preparar release aislado desde ese SHA; configurar PHP-FPM/Nginx, DNS y
   certificado HTTPS con los valores aprobados. Inyectar `.env` desde el gestor
   seguro: `APP_ENV=staging`, `APP_DEBUG=false`, `APP_URL`, `APP_KEY` existente,
   `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
   `DB_PASSWORD`, `DB_SSLMODE`, `CACHE_STORE`, `SESSION_DRIVER`,
   `QUEUE_CONNECTION`, `FILESYSTEM_DISK`; nunca imprimir secretos. Verificar
   `pdo_pgsql` y `bcmath`.
3. Verificar host/base/usuario/permisos; suspender escrituras. Crear backup
   custom con `pg_dump`, validar checksum y listado, restaurarlo en base aislada
   desechable con `pg_restore` y conciliar esquema, conteos y sumas. No restaurar
   sobre staging ni sobre datos con pagos posteriores.
4. En staging ejecutar `php artisan migrate:status`, comparar toda migración
   pendiente con la lista aprobada y ensayar en la copia restaurada. Solo con
   coincidencia, respaldo restaurable y autorización escrita ejecutar
   `php artisan migrate --force`; repetir status y conciliación. No ejecutar
   `migrate:fresh`, `refresh`, `reset` ni `rollback`.
5. Construir assets en el release (`npm ci`, `npm run build`); verificar manifest
   y assets. Preparar permisos/storage, reiniciar workers de cola de forma
   controlada y activar un solo scheduler; comprobar HTTPS, health y login.
6. En una base PostgreSQL vacía, separada y exclusiva para tests, fijar
   `APP_ENV=testing` y `DB_*` verificados; ejecutar
   `php artisan test tests/Feature/CobrosPaymentTest.php`. Nunca apuntar
   `RefreshDatabase` a staging poblado. Verificar idempotencia, DECIMAL, recibo,
   saldo y custodia sin movimiento de caja.
7. Por HTTPS comprobar certificado, `/sw-cobros.js`, manifest/assets, scope
   `/cobros`, worker `activated` y página controlada. Probar actualización,
   fallback GET offline y que un POST sintético no registra ni repite pagos;
   verificar que otras rutas no son interceptadas. Guardar evidencia sin datos
   sensibles.
8. Ante fallo, suspender escrituras, conservar logs y backup y volver a código/
   assets compatibles dejando el esquema ampliado. Nunca ejecutar down de 3B/3C
   ni restaurar sobre pagos posteriores; restaurar solo en base aislada y
   conciliar antes de cualquier reapertura aprobada.
