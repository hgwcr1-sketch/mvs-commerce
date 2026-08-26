# Centro de Datos MVS Commerce — Cronograma Maestro

## Estado
- D00 Auditoría existente: COMPLETADO.
- D01 Contratos y mapeo de plantillas MYM: EN CURSO, en paralelo.
- D02 Centro de Datos base: EN CURSO y autorizado para iniciar ahora.
- Fidelización F01–F45: cerrada previamente.

## Regla principal
D02 puede construirse sin esperar a cerrar D01 porque es únicamente el shell de navegación/orquestación.
No se autoriza crear importadores nuevos dependientes de plantillas no aprobadas.

## Reglas permanentes
1. Compras Excel/XML existente NO se duplica ni reemplaza.
2. No inventar plantillas: primero recibir y comparar plantillas reales de MYM.
3. Centro de Datos es capa de navegación/orquestación; reutiliza servicios y rutas existentes.
4. Inventario actual es prototipo y debe endurecerse antes de migración real.
5. Datos históricos no deben volver a afectar stock, puntos, caja o saldos de forma duplicada.
6. Un commit por fase; push; working tree limpio antes de continuar.
7. No tocar BeautyOS.

## D02 — Centro de Datos base

### Objetivo
Crear una entrada central, mobile-first, para:

- Importar
- Exportar
- Reportes

### Alcance autorizado
- Crear shell/pantalla central.
- Definir navegación, permisos y rutas seguras.
- Enlazar capacidades existentes.
- Compras Excel: enlazar flujo actual.
- Compras XML: enlazar flujo actual.
- Inventario: puede mostrarse/enlazarse como capacidad existente, sin rehacer todavía su lógica.
- Reportes: mostrar entrada al futuro centro de reportes, sin inventar todavía reportes nuevos fuera del alcance D02.
- Exportar: crear el espacio/navegación base; no implementar exportadores de negocio en D02.

### Prohibido en D02
- No crear importadores nuevos de productos, clientes, proveedores, CxC, CxP o fidelización.
- No cambiar formato de plantillas existentes.
- No modificar PurchaseProcessor.
- No duplicar PurchaseExcelImport / PurchaseXmlImport.
- No corregir todavía toda la deuda del importador de Inventario; eso corresponde a D03.
- No cargar datos reales de MYM.
- No cambiar .env.
- No hacer migraciones destructivas.
- No tocar BeautyOS.

### Pruebas D02
- permisos de acceso;
- rutas;
- enlaces a flujos existentes;
- 360 / 768 / 1280;
- navegación sin duplicación;
- git diff --check.

### Criterio de cierre
Centro de Datos permite entrar claramente a Importar / Exportar / Reportes y reutiliza las capacidades ya existentes sin duplicar lógica.

Commit sugerido:
`feat: crear centro de datos D02`

---

## D03 — Caracterización Compras + blindaje Inventario
Pendiente después de D02.

## D04 — Productos + múltiples códigos de barras
Pendiente y bloqueado hasta contratos de plantilla D01 aprobados.

## D05 — Clientes + proveedores
Pendiente y bloqueado hasta contratos de plantilla D01 aprobados.

## D06 — Inventario inicial por sucursal
Pendiente.

## D07 — Saldos CxC / CxP
Pendiente.

## D08 — Saldo inicial Fidelización
Pendiente.

## D09 — Exportadores esenciales
Pendiente.

## D10 — Reportes esenciales
Pendiente.

## D11–D12 — Históricos opcionales
Solo si son necesarios para el arranque.

## D13 — Migración real MYM
Carga por lotes con conciliación y rollback seguro.

## D14 — Auditoría post-migración + piloto
Prueba end-to-end y checklist de producción.

---

## Plan de migración en una sola noche

1. Ensayo completo días antes.
2. Congelar contratos de plantillas.
3. Exportar datos finales de San Ramón y Liberia al cierre.
4. Backup MVS + backup archivos origen.
5. Importar maestros.
6. Importar inventario por sucursal.
7. Importar CxC, CxP y Fidelización inicial.
8. Importar históricos obligatorios solo si fueron aprobados.
9. Conciliar totales automáticamente.
10. Prueba end-to-end.
11. Habilitar usuarios/sucursales.
12. Apertura operativa en MVS Commerce a la mañana siguiente.
