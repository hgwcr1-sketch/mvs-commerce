# MVS Commerce — Cronograma Maestro de Puesta en Producción v7

**Estado:** actualizado después de P08S, P08L y P08C.  
**Objetivo:** activar Producción cloud y PostgreSQL real antes de crear MYM Beauty Center.

## Cerrado

- P00–P07B: completados.
- P11 Centro de Etiquetas: completado.
- P12 Verificación Digital de Mercadería: completado.
- P08S Seguridad/PostgreSQL/capacidad: completado técnicamente (`443a028`), con validación real PostgreSQL/staging todavía pendiente.
- P08L Licenciamiento SaaS: completado (`c128c34`).
- P08C Apertura obligatoria de caja antes de POS: completado (`876d805`).

## Estado de P08

P08 está **preparado / pendiente de activación real**.

Falta:
- contratar/configurar servidor cloud;
- PostgreSQL real/staging autorizado;
- `app.mvscommerce.com`;
- DNS y HTTPS/TLS;
- backups externos;
- SMTP;
- monitoreo/alertas;
- prueba real desde San Ramón y Liberia.

## Orden obligatorio desde aquí

1. **Activar P08 + validar PostgreSQL real.**
2. **P09 — crear MYM Beauty Center + San Ramón + Liberia + licencia.**
3. **D04–D08 — preparar/importar datos reales.**
4. **D13/D14 — ensayo, conciliación y corte.**
5. **P10 — piloto y salida operativa.**

## Regla de disciplina

No agregar nuevas funciones bloqueantes antes de MYM.  
Las ideas nuevas van al **Backlog de Innovación MVS**, separado de Producción.

## Backlog de Innovación

El Excel v7 incluye una hoja `Backlog Innovación MVS` con 30 iniciativas priorizadas. Entre las principales:

- Dashboard inteligente “Qué requiere atención hoy”.
- Reposición inteligente.
- Transferencias inteligentes entre sucursales.
- Predicción de quiebres de stock.
- Órdenes de compra sugeridas.
- Recuperación automática de clientes.
- WooCommerce ↔ MVS unificado.
- Centro de alertas.
- Automatizaciones por reglas.
- Copiloto IA MVS.
- Resumen ejecutivo automático.
- ABC/XYZ y capital inmovilizado.
- Score de proveedores.
- POS móvil ultracompacto.
- Detección de anomalías operativas.
- Wallet móvil.
