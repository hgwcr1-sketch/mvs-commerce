# P40 — Procedimiento controlado SQLite local → PostgreSQL producción

Este documento define el ensayo y la ejecución futura. **No autoriza ni ejecuta producción.** La secuencia real solo ocurre después de validación local, aprobación explícita para producción, respaldo verificado y ventana de mantenimiento.

## Artefactos y responsabilidades

- Fuente: copia inmutable del SQLite validado; nunca trabajar sobre el archivo activo.
- Destino de ensayo: PostgreSQL vacío y aislado, con la misma versión de código del release.
- Paquete P38: respaldo funcional portable (clientes, productos, facturas/líneas, Kardex y puntos) con `manifest.json`, conteos y SHA-256.
- Importadores P31–P37: alternativa por dominio con preview, errores, reintento e idempotencia; no mezclarla con una copia física en el mismo ensayo.
- Responsable humano: toma y verifica respaldos, aprueba la ventana y decide continuar o revertir.

## Ensayo obligatorio (staging, nunca producción)

1. Congelar el commit candidato y registrar SHA, versiones PHP/Laravel/SQLite/PostgreSQL y zona horaria.
2. Generar respaldo consistente de SQLite y paquete P38; verificar hashes antes de copiar.
3. Crear PostgreSQL de ensayo vacío con usuario exclusivo. Configurar una copia del `.env` fuera del repositorio; no modificar secretos versionados.
4. Crear el esquema con `php artisan migrate --force` solamente contra el destino vacío de ensayo. No usar `migrate:fresh`, `migrate:refresh` ni `db:wipe`.
5. Transferir los datos con una herramienta aprobada para SQLite→PostgreSQL (por ejemplo pgloader) usando mapeo explícito de booleanos, timestamps, JSON y decimales. No ejecutar simultáneamente los importadores P31–P37 sobre esos mismos datos.
6. Ajustar secuencias PostgreSQL de cada PK al `MAX(id)` dentro de una transacción de ensayo y verificar claves foráneas/índices.
7. Ejecutar pruebas focales y regresión del release contra el destino de ensayo.
8. Conciliar fuente y destino por empresa: clientes, productos, facturas, suma de ventas, última compra, existencias, cantidad de Kardex, saldo de puntos y cantidad de movimientos. Todos deben coincidir exactamente; dinero/puntos/stock se comparan a 4 decimales.
9. Repetir el ensayo desde un destino vacío. El procedimiento debe producir los mismos conteos y hashes; los importadores por dominio deben rechazar el mismo `origen_migracion` sin duplicar.

## Go / no-go y rollback

- No-go ante cualquier diferencia, FK inválida, secuencia incorrecta, prueba nueva fallida o hash distinto. Se conserva SQLite como fuente y se descarta únicamente la base aislada de ensayo.
- Producción requiere aprobación explícita, modo mantenimiento, respaldo restaurable probado y comprobación de que no hay escrituras posteriores al corte.
- Si falla la ejecución futura: detener tráfico, no corregir datos manualmente, restaurar el respaldo PostgreSQL previo (si existía) o volver la aplicación al SQLite inmutable, validar salud y documentar el incidente.
- La conexión PostgreSQL no se habilita para usuarios hasta que conciliación y smoke tests queden aprobados.

## Evidencia de cierre P40

El repositorio prueba el resumen de conciliación mediante `MigrationP38P40Test`; este procedimiento se revisa como artefacto, pero ningún comando contra producción forma parte de la prueba automatizada.
