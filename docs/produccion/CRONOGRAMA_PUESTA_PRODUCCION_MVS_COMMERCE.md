# MVS Commerce — Cronograma Maestro de Puesta en Producción

**Estado:** aprobado para ejecución.  
**Objetivo:** cerrar las piezas de producción, crear MYM Beauty Center como primera empresa real con San Ramón y Liberia, migrar datos con conciliación y comenzar operación sin rehacer módulos ya funcionales.

## Reglas permanentes

- Una sola plataforma MVS Commerce multiempresa/multisucursal.
- MYM Beauty Center será una empresa; San Ramón y Liberia serán sucursales.
- No hardcodear MYM dentro del producto.
- Un agente modificando la rama a la vez.
- Codex se usa primero para bloques largos; MiMo puede relevar cuando Codex se agote.
- Un commit por fase + push + working tree limpio.
- Auditar y reutilizar antes de construir.
- No inventar plantillas de migración: usar las plantillas reales MYM aprobadas.
- No cargar datos reales hasta ensayo, conciliación y punto de corte.

## Centro de Datos

**Navegación aprobada:** `Sidebar → Centro de Datos → Importar / Exportar / Reportes`.

Reportes **NO** tendrá una entrada independiente en el sidebar.

| ID | Fase | Estado |
|---|---|---|
| D02 | Centro de Datos base | COMPLETADO |
| D03 | Caracterización Compras + blindaje Inventario | COMPLETADO |
| D04 | Productos + múltiples códigos de barras | PENDIENTE |
| D05 | Clientes + proveedores | PENDIENTE |
| D06 | Inventario inicial por sucursal | PENDIENTE |
| D07 | Saldos abiertos CxC/CxP | PENDIENTE |
| D08 | Saldo inicial Fidelización | PENDIENTE |
| D09 | Exportadores esenciales | COMPLETADO |
| D10 | Centro de Reportes esenciales | SIGUIENTE / EN CURSO |
| D11 | Ventas históricas | OPCIONAL |
| D12 | Gastos/caja histórica | OPCIONAL |
| D13 | Migración real MYM | PENDIENTE |
| D14 | Auditoría post-migración + piloto | PENDIENTE |

## Preparación para producción

### P00 — Login profesional MVS Commerce
Rediseñar el login azul actual con identidad MVS Commerce moderna, limpia y responsive. Preservar autenticación y seguridad.

### P01 — Panel Maestro MVS / Superadmin
Dashboard privado del propietario de MVS para administrar empresas, sucursales, usuarios, estado, módulos y configuración. Aislamiento multiempresa estricto.

### P02 — Módulos por empresa
Permitir activar/desactivar módulos contratados por empresa. Separar módulos contratados de permisos de usuarios.

### P03 — Onboarding nueva empresa + sucursales
Asistente para datos de empresa, logo, información fiscal/comercial, sucursales, administrador, módulos y configuración inicial.

### P04 — Impresión POS y comprobantes
Auditar primero lo existente. Soportar:
- térmica 80 mm;
- térmica 58 mm;
- factura/comprobante grande;
- PDF;
- reimpresión desde historial;
- configuración por sucursal;
- impresión directa cuando el navegador/entorno lo permita.

La fotografía entregada por el usuario es **solo referencia conceptual**. No copiar el diseño ni sus datos. El diseño final debe ser MVS Commerce. El **TOTAL debe verse grande y en negrita**. No incluir elementos manuscritos o accidentales de la foto (por ejemplo, la X de lapicero).

### P05 — Correo de comprobantes
Enviar comprobante desde la venta y reenviar desde historial. Auditar y reutilizar PDF/correo existente antes de construir.

### P06 — Fidelización en todos los comprobantes
Cuando exista cliente y aplique, mostrar en térmica y factura grande/PDF:
- puntos ganados;
- puntos utilizados;
- saldo anterior;
- saldo actual.

Consumir el resultado real del módulo de fidelización; no recalcular puntos en la vista.

### P07 — Portal personal del cliente
Auditar lo existente. Acceso con usuario y contraseña. Cada cliente solo ve su información. MVP:
- saldo de puntos;
- movimientos;
- premios/beneficios;
- datos básicos;
- QR/link cuando aplique.

### P08 — Dominio, servidor provisional y backups
Preparar acceso estable para San Ramón y Liberia. PC de San Ramón puede ser servidor provisional con HTTPS estable, servicios de arranque, sin suspensión, backups automáticos y recuperación probada. Diseñar para migrar posteriormente a VPS.

### P09 — Primera empresa real: MYM Beauty Center
Crear MYM mediante el mismo mecanismo del producto:
- Empresa: MYM Beauty Center.
- Sucursal: San Ramón.
- Sucursal: Liberia.
- Usuarios, permisos, módulos, cajas, métodos de pago y configuración.

### P10 — Ensayo de migración + piloto productivo
Primero ensayo con copia; después corte final. Prioridad de datos:
1. productos y códigos;
2. clientes;
3. proveedores;
4. inventario San Ramón;
5. inventario Liberia;
6. CxC/CxP abiertas;
7. puntos vigentes;
8. usuarios/configuración.

Históricos D11/D12 no bloquean la salida salvo decisión expresa.

## V0.2 — Wallet
Evaluar Apple Wallet y Samsung Wallet/equivalente después de estabilizar portal, QR y fidelización. No bloquea el MVP.

## Orden de ejecución recomendado

1. Cerrar D10 si ya está iniciado.
2. P00 → P01 → P02 → P03.
3. P04 → P05 → P06 → P07.
4. D04 → D05 → D06 → D07 → D08 usando plantillas MYM aprobadas.
5. P08 → P09.
6. P10 junto con D13 → D14.
7. D11/D12 y Wallet V0.2 después del piloto, salvo necesidad operativa aprobada.

## Criterio final de salida

MVS Commerce se considera listo para piloto MYM cuando:
- login/onboarding/superadmin están operativos;
- MYM y sus dos sucursales están correctamente aisladas;
- POS imprime/reimprime y genera comprobantes;
- correo funciona de forma controlada;
- fidelización aparece correctamente en comprobantes;
- portal cliente protege los datos;
- importadores necesarios están probados;
- inventario, saldos y puntos concilian;
- acceso remoto y backups están probados;
- una venta end-to-end valida caja → inventario → cliente → fidelización → comprobante.
