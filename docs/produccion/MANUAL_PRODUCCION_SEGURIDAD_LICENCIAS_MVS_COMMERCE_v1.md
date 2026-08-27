# Manual Maestro --- Preparación de Producción, Seguridad y Licencias MVS Commerce

## 1. Propósito

Este manual define cómo debe prepararse MVS Commerce antes de crear la
primera empresa real. Complementa P08 y establece los criterios de P08S
y P08L.

## 2. Separación de ambientes

### Desarrollo

-   Código de trabajo de cada desarrollador/agente.
-   SQLite permitido.
-   Datos de prueba.
-   Ramas Git independientes.
-   Nunca copiar la base de Producción encima para desarrollo normal.

### Producción

-   Servidor cloud.
-   `app.mvscommerce.com`.
-   PostgreSQL objetivo.
-   `.env` y secretos propios.
-   Storage productivo propio.
-   Datos reales.
-   Solo recibe versiones probadas y aprobadas desde GitHub.

## 3. Flujo de actualización

1.  Desarrollar localmente.
2.  Ejecutar pruebas.
3.  Commit/push.
4.  Integrar versión aprobada.
5.  Backup de Producción.
6.  Activar mantenimiento si corresponde.
7.  Actualizar código por fast-forward/versión aprobada.
8.  Composer para producción.
9.  Migraciones **no destructivas**.
10. Build de assets.
11. Limpiar/recrear caches necesarias.
12. Reiniciar workers.
13. Smoke tests.
14. Volver a servicio.

Nunca usar `migrate:fresh`, `reset --hard` sobre Producción ni copiar
SQLite de Desarrollo encima de Producción.

## 4. Seguridad mínima obligatoria

Antes de datos reales: - HTTPS. - `APP_ENV=production`. -
`APP_DEBUG=false`. - secretos fuera de Git. - SSH por llave y acceso
administrativo restringido. - firewall con solo puertos necesarios. -
PostgreSQL no expuesto públicamente. - cookies/sesiones seguras. - rate
limiting en autenticación y Portal. - permisos multiempresa
verificados. - logs sin secretos/datos sensibles innecesarios. -
actualizaciones de seguridad. - monitoreo de CPU, RAM, disco y
disponibilidad. - alertas. - backups externos. - restauración probada.

## 5. PostgreSQL

SQLite sigue permitido para desarrollo, pero todo código nuevo debe ser
portable.

P08S debe comprobar: - migraciones completas; - seeders requeridos; -
tipos y constraints; - índices; - SQL crudo; - transacciones; -
ordenamientos/fechas; - búsquedas; - pruebas de módulos críticos; -
backup y restore.

Estado P08S: auditoría estática completa y SQLite verdes. Se eliminaron comparaciones booleanas incompatibles, se agregaron índices de historial del Portal y se prepararon plantilla/runner PostgreSQL sin secretos. La ejecución real sigue pendiente por ausencia de driver, cliente y servidor PostgreSQL; no se autoriza declarar compatibilidad operativa hasta ejecutar migraciones y regresión en una base `_test` vacía.

## 6. Capacidad

No medir capacidad por "cantidad de empresas" solamente.

Medir: - usuarios concurrentes; - cajas concurrentes; - solicitudes por
minuto; - CPU; - RAM; - disco; - latencia; - conexiones PostgreSQL; -
consultas lentas; - carga del Portal.

Miles de clientes registrados no significan miles conectados. P08S debe
generar una referencia de carga antes de MYM y Producción deberá
monitorearse continuamente.

Referencia local P08S: 3.001 clientes, 100 movimientos y 100 ventas; cuatro consultas críticas en 2,37–8,22 ms sobre SQLite en memoria. No es capacidad máxima. La concurrencia Portal/POS debe medirse con `scripts/load/p08s-k6.js` en staging PostgreSQL y correlacionarse con métricas del servidor.

## 7. Portal del Cliente

El Portal pertenece a la empresa y consolida compras/saldo de sus
sucursales.

Debe proteger: - autenticación; - recuperación; - aislamiento por
`company_id`; - comprobantes; - datos personales; -
promociones/publicaciones; - enlaces; - rate limiting.

Contenido estático/imágenes podrá evolucionar a CDN/object storage si el
volumen crece.

## 8. Licencias SaaS

Implementación P08L: `company_licenses` conserva el estado vigente y `company_license_events` registra cada transición con actor, estados, snapshot y nota. `licenses:refresh` corre diariamente. `trial`, `active` y `grace` permiten operar; `expired`, `suspended` y `cancelled` redirigen a `/licencia`. El bloqueo no elimina datos. Reactivar exige fechas futuras coherentes. Los cupos se validan antes de vincular usuarios o crear sucursales. Los módulos permanecen en `company_modules`.

### Unidad de licencia

Una licencia por empresa.

### Usuarios

Administradores, cajeros, vendedores y bodegueros son usuarios internos
gobernados por roles/permisos. No necesitan serial/licencia individual.

### Estados

Prueba, Activa, Gracia, Vencida, Suspendida y Cancelada.

### Suspensión

Suspender no elimina clientes, ventas, inventario, comprobantes ni
configuraciones. La política de acceso limitado debe quedar explícita y
probada.

### Panel Maestro

Solo Superadmin MVS administra: - plan; - estado; - fechas; - módulos; -
límites; - notas; - historial; - renovación; - suspensión/reactivación.

### MVP

No implementar cobro automático todavía. La renovación puede
administrarse manualmente desde Panel Maestro.

## 9. Backups

Debe existir: - backup de PostgreSQL; - backup de uploads/storage
requerido; - copia fuera del servidor activo; - retención definida; -
checksum/integridad; - restauración aislada probada; - procedimiento de
emergencia.

Un backup que nunca se restauró no se considera validado.

Para PostgreSQL, `Backup-MvsProduction.ps1` genera formato custom y valida el catálogo; `Test-PostgreSqlRestore.ps1` restaura solo en una base vacía `_restore_test`, valida hashes y puede extraer uploads a una ruta nueva. La prueba real queda pendiente hasta disponer de PostgreSQL autorizado.

## 10. Primera empresa real --- MYM Beauty Center

No crear MYM hasta cerrar P08S y P08L y activar los componentes críticos
de P08.

P09 creará: - MYM Beauty Center; - San Ramón; - Liberia; -
administradores/usuarios; - cajas; - métodos de pago; - módulos; -
configuración.

Luego se migrarán datos con las plantillas aprobadas y se conciliará
antes del piloto.

## 11. Checklist antes del domingo

Debe estar verde: - cloud accesible; - `app.mvscommerce.com`; - HTTPS; -
PostgreSQL validado; - seguridad P08S; - backups + restore; - licencia
MYM activa; - MYM + 2 sucursales; - importación/conciliación; - POS; -
Compras; - Inventario; - Clientes; - Fidelización; - comprobantes; -
correo; - Portal; - acceso desde ambas sedes.

Si un elemento crítico de seguridad, integridad o conciliación falla, no
se fuerza la salida; se realiza piloto controlado y se corrige.
