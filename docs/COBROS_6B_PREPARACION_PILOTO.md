# CobrOS 6B — Preparación local del piloto

2026-10-01 (Costa Rica). **BLOQUEADO para piloto financiero**.
Preparación documentada; 6B/Definition of Done de producción NO completada.
Base: `feature/cobros-v1`, `9b0a218eaa8a23c8bf46dfa077e5e10aa477dc71`.
Sin cambios funcionales, SSH, push, merge ni despliegue. Base de revisión,
servidor y temporales previos preservados. No se consultó la base maestra.

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
| Chrome / 360, 768, 1280 / PWA | Dos intentos: probe existente de shell y nuevo probe financiero fallaron con `CDP timeout Page.enable`. Sin capturas válidas ni aprobación visual/instalación |
| PostgreSQL | Revisión estática; PHP CLI no carga `pdo_pgsql`. Hay herramientas cliente PG16, pero no se conectó a servidor alguno |
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
rechaza escrituras y no usa la base/sesión del servidor de revisión. Prepara
captura/confirmación/recibo a tres anchos, métricas clientWidth/scrollWidth,
targets y pruebas de SW/red. **No pasó aquí**; tampoco sustituye E2E real.

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
| PWA (5B) | Manifest, worker público y fuente JS | Layout pide `/js/cobros-pwa.js`, archivo público inexistente; Vite entra por app.js/app.css sin importar esa fuente. Build verde no registra el SW. Corregir integración y probar antes del piloto |
| Mala señal | Replay con mismo token; no pago offline | No se encontró consumidor operacional de captureDraft/sync-ready en vistas. Captura nueva genera token nuevo: no certificada recuperación tras cierre/recarga. Banner se incluye online=true y no renderiza aviso por defecto |
| Precisión | Gate CobrOS usa BCMath, columnas decimal(19,4) | Servicio compartido applyPayment convierte montos/saldos a float y round, contrario a precisión extremo a extremo. Requiere alcance de corrección y tests de límites; no se afirma pérdida concreta con los montos pequeños probados |
| Liquidación | Custodia, recibos, reversos y reportes netos | No hay entrega/aceptación/conciliación e ingreso a caja. Excluido de 3B/3C, pero arquitectura exige liquidación antes de caja. Definir operación del piloto; nunca registrar otro pago para liquidar |
| Licencias/cupos | Individual/company_cupo, Platform Admin, altas por dueño según contrato | Contrato por empresa; no evidenciado contrato individual con vigencia propia por cobrador. canCreateCollector cuenta antes de transacción sin lock contractual; toggleCollector reactiva sin revalidar cupo. Falta control/prueba de altas concurrentes/reactivación. admin_license_charged no implica facturación |
| Standalone | Login/navegación propios, User y monolito compartidos según V1 | No significa backend/base separados. Instalación display:standalone, retorno/sesión/actualización en teléfono pendientes; no crear identidad paralela |
| Dispositivos | Auth/sesión, permisos, revocación y rate limiting | Política de confianza, pérdida/robo, sesiones simultáneas y retiro pendientes; sin certificación Android/iOS/GPS real |
| GPS | Ubicación cliente y snapshot visita | Falta plazo/finalidad, consentimiento operativo, acceso, backups y purga/anonimización autorizada preservando pagos/auditoría. No borrar GPS/pagos aquí |
| Responsive | CSS/markup mobile-first, controles 44/48/56px | 360/768/1280 pendientes por CDP; CTA confirmación no persistente en markup inspeccionado. Probar teclado, nombres largos, menú y pantallas financieras |

La arquitectura inicial menciona licencia por ruta; 1E-A/1E-B documentan después
individual/company_cupo. Se registra evolución/brechas, sin cambiar contratos,
renumerar fases ni implementar funciones nuevas.

## Aplicación futura: procedimiento NO ejecutado

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

**Decisión:** BLOQUEADO para piloto financiero: asset PWA ausente, floats
compartidos, PG no ejecutado, cupos/políticas/custodia por cerrar y
auditoría/usuario/dispositivo pendientes. Preparación no equivale a aprobación.
