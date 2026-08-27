# P08 — Operación de Producción

## Estado y límites

P08 deja la infraestructura **técnicamente preparada / pendiente de activación**. No crea MYM Beauty Center, no carga datos reales, no instala software del sistema y no cambia router, firewall, DNS, certificados ni motor de base de datos.

Desarrollo y Producción son instalaciones distintas:

| Elemento | Desarrollo | Producción |
|---|---|---|
| Checkout | repositorio de trabajo actual | release/checkout dedicado en servidor cloud |
| Entorno | `.env` local, `APP_ENV=local` | `.env` no versionado basado en `env.production.example` |
| Datos | SQLite de desarrollo y bases temporales de tests | archivo/servidor de BD exclusivo; nunca copiar `database.sqlite` de desarrollo |
| Assets | Vite dev/build local | `npm ci && npm run build` durante despliegue |
| Uso | Codex/MiMo, desarrollo y pruebas | San Ramón y Liberia, datos reales |

## Auditoría al 2026-08-27

- Laravel 13.21.1, PHP 8.4.24, Composer 2.10.2, Node 24.19.0 y npm 11.17.0.
- Desarrollo usa SQLite; PHP dispone de `pdo_sqlite`, `sqlite3`, `openssl` y `zip`, pero no de `pdo_mysql` ni `pdo_pgsql`.
- Sesiones, cache y cola usan base de datos. Correo está en `log`, por lo que SMTP sigue pendiente.
- Scheduler registra caja cada minuto, apartados y alertas de CxP cada hora, y vencimiento de puntos diario.
- Los uploads viven en `storage/app/public`; `public/storage` está enlazado.
- Vite genera assets en `public/build`, ignorado por Git. Logs actuales son `stack/single`; Producción debe usar `daily`.
- La instalación local usa `APP_DEBUG=true` y es únicamente Desarrollo.

## Producción cloud y contingencia Windows

La decisión v6 establece cloud como Producción principal: proxy HTTPS, aplicación PHP, worker, scheduler, PostgreSQL privado, storage persistente, backups externos y monitoreo. Exponer únicamente 443; SSH solo por llave y red/IP administrativa; PostgreSQL nunca público. Separar usuario de despliegue, usuario del proceso y rol de base de datos con privilegios mínimos. Aplicar actualizaciones de seguridad en ventana controlada y alertar por disponibilidad, CPU, RAM, disco, errores 5xx, cola y conexiones PostgreSQL.

La PC Windows de San Ramón queda únicamente como alternativa provisional/contingencia y no como arquitectura objetivo.

Arquitectura recomendada: router/DNS o túnel empresarial → HTTPS en IIS con ARR/FastCGI o Caddy → PHP/Laravel. `php artisan serve` no es servidor productivo. No activar exposición directa hasta contar con dominio, certificado, firewall restringido y revisión humana.

Servicios/tareas que deben arrancar automáticamente con una cuenta de servicio sin privilegios administrativos:

1. servidor web y PHP;
2. `php artisan queue:work --sleep=3 --tries=3 --timeout=90 --max-time=3600`, reiniciado por el administrador de servicios;
3. tarea cada minuto: `php artisan schedule:run`;
4. backup programado con `Backup-MvsProduction.ps1`;
5. monitor externo HTTPS.

Configurar Windows para no suspenderse durante la jornada, reinicio automático después de energía (BIOS/UEFI), UPS con apagado controlado y reinicio de servicios tras Windows Update. Crear reglas entrantes solo para 443 hacia el proxy; no exponer PHP, SQLite, SMB, RDP ni el puerto de Vite. El acceso administrativo debe limitarse por VPN o red de gestión con MFA. Estos cambios requieren intervención humana.

Para Liberia, preferir `https://app.mvscommerce.com` mediante un túnel/VPN empresarial o publicación 443 correctamente endurecida. Si Internet o la PC de San Ramón cae, Liberia no podrá operar; documentar atención manual y reconciliación posterior, sin intentar dos bases SQLite activas.

## Dominio y HTTPS

- `mvscommerce.com`: futuro sitio comercial.
- `app.mvscommerce.com`: aplicación productiva.
- Crear DNS solo cuando exista IP/túnel/VPS aprobado. Terminar TLS en el servidor web/proxy, renovar certificados automáticamente y redirigir HTTP a HTTPS.
- Mantener `APP_URL=https://app.mvscommerce.com`; Laravel ya evita hardcodear el dominio.
- Tras activar, comprobar certificado, redirección, login y cookies `Secure`, `HttpOnly` y `SameSite=Lax` desde San Ramón y Liberia.

## Base de datos

SQLite es aceptable únicamente como etapa provisional de baja concurrencia, con el archivo en disco local estable, una sola instancia de aplicación y backups consistentes. No ubicarlo en OneDrive, carpeta de red ni repositorio. Sus límites son la serialización de escrituras, operación/observabilidad limitada y mayor dependencia de una sola PC.

La arquitectura cloud v6 adopta PostgreSQL como objetivo. PHP local todavía no tiene `pdo_pgsql` y tampoco existen las herramientas cliente; la prueba real no se simuló. `Test-PostgreSqlCompatibility.ps1` exige una base vacía cuyo nombre termine en `_test`, ejecuta solo `migrate --force`, nunca limpia una base existente y conserva el resultado para inspección. La plantilla está en `env.postgresql.testing.example`.

La migración futura será: congelar escrituras, backup verificado, crear esquema con migraciones en BD vacía, importar mediante herramienta aprobada, conciliar conteos/totales/relaciones, ejecutar pruebas end-to-end y cambiar `DB_CONNECTION` solo en una ventana controlada. No copiar SQLite sobre PostgreSQL ni improvisar conversión.

## Backup y restore

Programar `Backup-MvsProduction.ps1` cada noche y adicionalmente antes de cada despliegue. Conservación inicial: 30 diarios; añadir 12 mensuales en almacenamiento externo. `BackupRoot` debe estar fuera del checkout y, cuando sea posible, sincronizarse cifrado a otro dispositivo/ubicación. El script crea una copia consistente mediante API SQLite, valida `integrity_check`, comprime uploads, guarda hashes y elimina únicamente respaldos MVS vencidos con manifiesto.

Con `DB_CONNECTION=pgsql`, el mismo script usa `pg_dump --format=custom --no-owner --no-acl` y valida el archivo con `pg_restore --list`. `Test-PostgreSqlRestore.ps1` solo acepta una base vacía terminada en `_restore_test`, valida hashes de dump/uploads, restaura con `--exit-on-error` y nunca elimina ni reemplaza una base. PostgreSQL y sus herramientas deben ser instalados/configurados por el operador cloud.

## Capacidad P08S

`P08SecurityPostgresCapacityTest` genera 3.001 clientes y 100 ventas/movimientos en SQLite efímero, mide cliente/saldo/movimientos/compras y comprueba índices. Se observaron cuatro consultas y 2,37–8,22 ms en las ejecuciones locales; no es un máximo ni extrapola concurrencia cloud.

`scripts/load/p08s-k6.js` mide Portal y POS concurrentes únicamente contra local/staging/test y exige `ALLOW_P08_LOAD_TEST=true`. Antes de Producción debe ejecutarse contra staging PostgreSQL, registrando VUs, solicitudes/minuto, p50/p95/p99, errores, CPU, RAM, disco, conexiones y consultas lentas. El payload POS debe usar datos desechables e idempotencia real.

Ejemplo (la ruta y cuenta deben aprobarse):

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\MVSCommerce\production\app\scripts\production\Backup-MvsProduction.ps1 -ApplicationPath C:\MVSCommerce\production\app -BackupRoot E:\MVSBackups -RetentionDays 30
```

Probar semanalmente un restore aislado:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\production\Test-MvsRestore.ps1 -BackupDirectory E:\MVSBackups\mvs-AAAAMMDD-HHMMSS
```

Para una recuperación real: detener web/worker/scheduler; preservar la instalación dañada; ejecutar primero `Test-MvsRestore.ps1 -KeepRestore` hacia una ruta nueva; apuntar una instalación de recuperación y un `.env` temporal a esa copia; ejecutar `migrate:status`, login y smoke tests; solo después intercambiar rutas bajo ventana aprobada. Nunca sobrescribir la base activa directamente.

## Despliegue repetible

1. En Desarrollo: árbol limpio, pruebas y build correctos; commit exclusivo y push a GitHub.
2. En Producción: ejecutar `Test-ProductionReadiness.ps1`, confirmar ventana y comunicar mantenimiento.
3. Ejecutar `Deploy-MvsProduction.ps1`; este exige árbol limpio/rama correcta, realiza backup, permite solo fast-forward, activa mantenimiento, instala Composer sin dev, construye assets, ejecuta únicamente `migrate --force`, regenera caches, reinicia workers y vuelve a servicio.
4. Smoke tests: HTTPS, `/login`, autenticación, selección empresa/sucursal, consulta de producto, storage, cola y scheduler; revisar logs.
5. Si falla después de entrar en mantenimiento, el script lo deja detenido para evitar servir una versión parcial. Restaurar según el procedimiento aprobado; no usar `migrate:fresh`, resets ni una base de Desarrollo.

## Activación pendiente del usuario

1. Comprar/configurar dominio y elegir publicación segura (túnel/VPN o 443 endurecido).
2. Elegir e instalar servidor web y administrador de procesos Windows.
3. Decidir SQLite provisional frente a PostgreSQL/MySQL; instalar el driver solo si se elige otro motor.
4. Crear rutas, cuenta de servicio, permisos mínimos, tareas automáticas, UPS y política de energía/reinicio.
5. Generar `.env` productivo y `APP_KEY` nuevos; configurar SMTP y credenciales fuera de Git.
6. Configurar TLS, DNS, router/firewall y monitor externo.
7. Elegir almacenamiento externo/cifrado para backups y ejecutar restore de aceptación.
8. Validar desde ambas sedes. Solo entonces P08 cumple el criterio operativo de acceso estable y recuperación probada.
