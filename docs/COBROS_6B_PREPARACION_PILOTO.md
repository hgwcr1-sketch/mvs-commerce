# CobrOS 6B — Preparación local del piloto

2026-10-01 (Costa Rica). **Validación local 6B cerrada; BLOQUEADO para piloto
financiero**. 6B/Definition of Done de producción NO completada. Base de cierre:
`feature/cobros-v1`, `08fba58`. Sin cambios funcionales, SSH, push, merge ni
despliegue. Base de revisión, servidor y temporales previos preservados. No se
consultó la base maestra.

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
| PWA (5B) | Manifest, worker público y fuente JS | Layout pide `/js/cobros-pwa.js`, archivo público inexistente; Vite entra por app.js/app.css sin importar esa fuente. Build verde no registra el SW. Corregir integración y validar registro/activación en servidor HTTPS antes del piloto |
| Mala señal | Replay con mismo token; no pago offline | No se encontró consumidor operacional de captureDraft/sync-ready en vistas. Captura nueva genera token nuevo: no certificada recuperación tras cierre/recarga. Banner se incluye online=true y no renderiza aviso por defecto |
| Precisión | Gate CobrOS usa BCMath, columnas decimal(19,4) | Servicio compartido applyPayment convierte montos/saldos a float y round, contrario a precisión extremo a extremo. Requiere alcance de corrección y tests de límites; no se afirma pérdida concreta con los montos pequeños probados |
| Liquidación | Custodia, recibos, reversos y reportes netos | No hay entrega/aceptación/conciliación e ingreso a caja. Excluido de 3B/3C, pero arquitectura exige liquidación antes de caja. Definir operación del piloto; nunca registrar otro pago para liquidar |
| Licencias/cupos | Individual/company_cupo, Platform Admin, altas por dueño según contrato | **Bloqueador:** contrato por empresa; no evidenciado contrato individual con vigencia propia por cobrador. canCreateCollector cuenta antes de transacción sin lock contractual; toggleCollector reactiva sin revalidar cupo. Falta decisión de contrato, control y prueba de altas concurrentes/reactivación. admin_license_charged no implica facturación |
| Standalone | Login/navegación propios, User y monolito compartidos según V1 | No significa backend/base separados. Instalación display:standalone, retorno/sesión/actualización en teléfono pendientes; no crear identidad paralela |
| Dispositivos | Auth/sesión, permisos, revocación y rate limiting | Política de confianza, pérdida/robo, sesiones simultáneas y retiro pendientes; sin certificación Android/iOS/GPS real |
| GPS | Ubicación cliente y snapshot visita | Falta plazo/finalidad, consentimiento operativo, acceso, backups y purga/anonimización autorizada preservando pagos/auditoría. No borrar GPS/pagos aquí |
| Responsive | CSS/markup mobile-first, controles 44/48/56px | Prueba local 6B PASS en formulario, vista previa y recibo a 360/768/1280 sin overflow horizontal; esto no certifica dispositivo real. Revisar teclado, nombres largos, menú y pantallas financieras en dispositivo real antes del piloto |

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
- Service worker: corregir primero la integración de asset PWA documentada
  arriba; luego validar registro, activación, alcance `/cobros`, actualización y
  offline en staging servido por HTTPS.
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
