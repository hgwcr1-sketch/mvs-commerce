# Centro de Datos MVS Commerce — Cronograma Maestro

## Estado
- D00 Auditoría existente: COMPLETADO.
- D01 Contratos y mapeo de plantillas MYM: EN CURSO, en paralelo.
- D02 Centro de Datos base: COMPLETADO.
- D03 Caracterización Compras + blindaje Inventario: COMPLETADO.
- D04–D08: pendientes de D01/contratos MYM aprobados.
- D09 Exportadores esenciales: COMPLETADO.
- D10 Reportes esenciales: SIGUIENTE fase ejecutable.
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

**Estado: COMPLETADO.**

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

### Evidencia de cierre
- `DataCenterController` aporta únicamente autorización y navegación: Inicio, Importar, Exportar y Reportes.
- Se reutilizan rutas existentes: Compras Excel abre `compras.index`, Compras XML abre `compras.import.xml.create` e Inventario abre `importaciones.inventario`.
- No se modificaron `PurchaseExcelImport`, `PurchaseXmlImport`, `PurchaseProcessor`, `DataImportController` ni plantillas.
- Permisos existentes: `compras.crear`, `compras.ver`, `inventario.ver`, `reportes.exportar` y `reportes.ver`; no se creó un sistema paralelo.
- UI mobile-first verificada conceptualmente en 360/768/1280, con grids progresivos y controles táctiles.
- `DataCenterShellTest`: 6 tests, 47 aserciones; regresión relacionada: 39 tests, 210 aserciones; build Vite y `git diff --check` correctos.
- El Excel maestro `_v2.xlsx` se usó como fuente de control y no fue modificado.

Commit sugerido:
`feat: crear centro de datos D02`

---

## D03 — Caracterización Compras + blindaje Inventario

**Estado: COMPLETADO.**

### Evidencia de cierre

- Compras conserva `PurchaseExcelImport`, `PurchaseXmlImport`, `PurchaseImportManager` y `PurchaseProcessor`; no se duplicó ni reemplazó su lógica.
- El POST XML quedó protegido igual que el resto del flujo con `active.branch` y `compras.crear`.
- `PurchaseImportCharacterizationTest` fija lectores Excel/XML, contrato de la plantilla, middleware, sesión review y confirmación mediante `PurchaseProcessor`.
- El importador existente de Inventario ahora delega análisis/confirmación en `InventoryImportService`; el preview no muta, consulta stock real y resuelve código interno, barcode principal y `product_barcodes` adicionales.
- Productos nuevos resuelven categoría, unidad y marca activas de la empresa por los valores ya presentes en la plantilla; se eliminaron IDs hardcodeados y se crea `ProductBarcode` cuando corresponde.
- Se validan empresa/sucursal, acceso a otras sucursales, cantidades, unidades enteras, mínimos/máximos, precios, impuesto, duplicados y conflictos de barcode; las salidas no pueden dejar stock negativo.
- Confirmar requiere `inventario.ajustar`, vuelve a comprobar empresa/sucursal y ejecuta producto, stock y movimiento dentro de una transacción. La publicación de stock usa `InventoryPostingService` con bloqueo de fila.
- `InventoryImportHardeningTest` cubre preview/confirm, permisos, multiempresa/multisucursal, stock real, duplicados/barcodes y rollback atómico.
- No se modificaron plantillas, `PurchaseProcessor`, datos MYM, Excel maestro ni BeautyOS.

Pruebas específicas D03: 13 tests, 68 aserciones. Regresión relacionada adicional: 54 tests, 300 aserciones. Build Vite, Pint focalizado y `git diff --check` correctos.

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

**Estado: COMPLETADO.**

- Un solo `DataExportController` y `DataExportService` generan XLSX/CSV para productos, clientes, proveedores, inventario, CxC, CxP y fidelización.
- Cada conjunto tiene encabezados estables, CSV UTF-8 con BOM y XLSX con filtro/encabezado congelado; no se alteran datos ni contratos de importación.
- Todas las consultas se limitan a la empresa activa. Inventario, CxC y CxP requieren sucursal asignada; inventario entre sucursales exige además `inventario.ver_otras_sucursales`.
- `reportes.exportar` es obligatorio y se combina con el permiso de lectura del dominio. La pantalla muestra únicamente exportadores autorizados.
- UI mobile-first: una columna a 360 px, dos a 768 px y tres a 1280 px; selectores y descargas mantienen targets de 44 px.
- `DataExportTest`: 7 tests, 40 aserciones. `DataCenterShellTest`: 6 tests, 47 aserciones. Regresión de dominios: 31 tests, 177 aserciones.
- Build Vite, Pint focalizado y `git diff --check` correctos. No se modificó el Excel maestro ni BeautyOS.

## D10 — Reportes esenciales
SIGUIENTE; depende de D02 y D09, ambos completos.

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
