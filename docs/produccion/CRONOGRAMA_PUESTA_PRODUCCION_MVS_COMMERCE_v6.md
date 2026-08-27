# MVS Commerce --- Cronograma Maestro de Puesta en Producción v6

**Fecha de actualización:** 27/08/2026\
**Objetivo:** llegar al piloto de MYM Beauty Center con San Ramón y
Liberia con infraestructura, seguridad, licencias y migración
controladas.

## Estado real

-   Fidelización F01--F45: **CERRADO**.
-   Centro de Datos D02--D10: **CERRADO**.
-   P00--P07B: **COMPLETADOS**.
-   P11 Centro de Etiquetas: **COMPLETADO** (`56f1060`).
-   P12 Verificación Digital de Mercadería: **COMPLETADO** (`8bf056e`).
-   P08 Infraestructura: **TÉCNICAMENTE PREPARADO / PENDIENTE DE
    ACTIVACIÓN** (`059f753`).
-   Dominio `mvscommerce.com`: **COMPRADO**.
-   Decisión actual: Producción principal en **cloud**, no en la PC de
    San Ramón.
-   P08S: **SIGUIENTE**.
-   P08L: **PENDIENTE DESPUÉS DE P08S**.
-   P09 MYM Beauty Center: **PENDIENTE**.
-   P10 piloto/salida: **PENDIENTE**.

## Orden obligatorio actualizado

1.  **P08S --- Seguridad + PostgreSQL + capacidad.**
2.  **P08L --- Licenciamiento SaaS por empresa.**
3.  **Activación P08 --- cloud, app.mvscommerce.com, HTTPS, backups,
    SMTP, monitoreo.**
4.  **P09 --- MYM Beauty Center + San Ramón + Liberia.**
5.  **D04--D08 --- migración/importadores reales con plantillas
    aprobadas.**
6.  **D13/D14 --- ensayo, conciliación y corte.**
7.  **P10 --- piloto y salida operativa.**
8.  **V0.2 Wallet** después del MVP.

## P08S --- Seguridad + PostgreSQL + capacidad

Objetivo: no introducir datos reales hasta demostrar que MVS puede
operar de forma segura en PostgreSQL y bajo carga razonable.

Reglas: - Desarrollo local puede continuar con SQLite. - Producción
objetivo: PostgreSQL. - Evitar SQL específico de SQLite en código
nuevo. - Ejecutar todas las migraciones desde cero sobre PostgreSQL. -
Ejecutar regresión relevante sobre PostgreSQL. - Auditar aislamiento
multiempresa/multisucursal. - Auditar autenticación, recuperación,
Portal del Cliente, rate limiting, cookies/sesiones, secretos, logs,
archivos y endpoints públicos. - Revisar índices, consultas lentas y
compatibilidad de tipos/constraints. - Simular miles de clientes
registrados y concurrencia razonable del Portal. - Probar concurrencia
POS. - Validar backup/restore PostgreSQL. - Documentar hardening del
servidor cloud, firewall, HTTPS, actualizaciones y monitoreo.

Cierre: sin incompatibilidades críticas, aislamiento probado,
backup/restore probado y capacidad base medida.

## P08L --- Licenciamiento SaaS

La licencia pertenece a la **empresa**, no a cada usuario.

Estados mínimos: - Prueba - Activa - Gracia - Vencida - Suspendida -
Cancelada

Debe incluir: - inicio, vencimiento y renovación; - plan; - módulos
contratados; - límites opcionales de usuarios/sucursales; - notas
administrativas; - historial/auditoría; - quién activó, suspendió o
renovó; - avisos de vencimiento; - administración exclusiva desde Panel
Maestro MVS; - suspensión sin borrar datos; - reactivación inmediata; -
aislamiento entre empresas.

Los usuarios continúan gobernados por roles/permisos. No crear licencias
individuales para cajeros.

Cobro automático recurrente queda fuera del MVP.

## Producción cloud

Arquitectura objetivo:

`mvscommerce.com` → sitio comercial futuro\
`app.mvscommerce.com` → MVS Commerce Producción

Producción tendrá instalación, `.env`, PostgreSQL, storage y secretos
separados de Desarrollo. GitHub seguirá siendo el puente para desplegar
versiones aprobadas.

## Regla documental permanente

Cada fase se cierra en este orden:

**código → pruebas → manuales/documentación → cronograma → commit/push →
siguiente fase**

No avanzar con documentación/cronograma desactualizados.

## Objetivo MYM

MYM Beauty Center será el primer tenant real: - San Ramón - Liberia

El objetivo es arrancar con POS, Compras, Inventario, Clientes,
Fidelización, comprobantes, correo, Portal, etiquetas y verificación de
mercadería operativos y conciliados.

La fecha deseada es domingo, pero la estabilidad y la protección de
datos tienen prioridad. Si un control crítico no está verde, se realiza
piloto controlado antes de forzar producción.
