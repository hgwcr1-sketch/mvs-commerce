# Toma de Inventario — pruebas finales locales

Fecha de revalidación: 2026-09-11. Rama: `feature/pos`. Trabajo realizado sobre el working tree existente, sin commit, push ni producción.

Corrección posterior a auditoría, por contrato explícito del usuario: el stock final debe ser la cantidad física confirmada. Se reemplazó la aplicación de diferencia contra snapshot por `getEffectiveQuantity()` y un ajuste firmado respecto al stock actual bajo bloqueo. Se agregaron casos de stock intermedio, diferencia original cero, stock ya igual al físico y precisión de cuatro decimales. Se añadió el marker responsive en creación y se retiró el scanner sin uso de detalle; edición conserva el componente compartido.

## Resultado focal

`tests/Feature/InventoryCountTest.php` utiliza `Tests\TestCase`, `RefreshDatabase`, SQLite en memoria, `User::factory()` y modelos/relaciones reales. No desactiva middleware ni simula permisos. La clave de cifrado es exclusivamente de pruebas; no modifica `.env`.

- `php artisan test tests/Feature/InventoryCountTest.php --stop-on-failure`: **39/39, 361 aserciones**.
- `php artisan test tests/Feature/InventoryCountTest.php`: **39/39, 361 aserciones**.
- Cubre los seis permisos, navegación dentro de Inventario, aislamiento de empresa/sucursal/documento/item, creación y cancelación sin movimiento de stock, búsquedas, snapshot y duplicados, conteo/reconteo a cuatro decimales, transiciones inválidas, confirmación y trazabilidad, reintento y modelo obsoleto.
- Confirma diferencias negativas, positivas y cero. Solo se omite el movimiento cuando físico y stock actual coinciden; una diferencia original cero sí puede requerir ajuste. Los demás casos generan `adjustment`, con cantidad firmada, `reference_type = App\Models\InventoryCount` y `reference_id` de la toma. Se mantienen transacción, bloqueos, idempotencia y actor/fecha de confirmación. Las líneas sin cantidad final permanecen sin ajustar.

## Estrategia de concurrencia comprobada

Snapshot teórico **10**, venta intermedia real contabilizada mediante el servicio existente deja **8**, conteo físico **12**. La diferencia original **+2** se conserva para auditoría; la confirmación deja **12**, con `previous_stock=8`, `new_stock=12` y `quantity=+4`. El movimiento de venta permanece intacto. Si stock actual es **5** y físico **2**, el ajuste es **-3** y el stock final **2**. Este contrato sustituye expresamente la estrategia anterior `actual + (físico - snapshot)`.

El reintento HTTP no duplica stock ni movimientos. Una prueba adicional entrega al controlador un modelo todavía en `review`, cargado antes de la primera confirmación: la relectura bajo bloqueo rechaza el segundo intento con 422. Es una simulación determinista de modelo obsoleto, **no una ejecución de dos procesos concurrentes ni una validación de locks PostgreSQL**.

## Defectos y correcciones locales documentados antes de esta revalidación

1. Import faltante de `InventoryCountController` en rutas: solicitudes autorizadas devolvían 500.
2. Interpolación PHP inválida en las notas del movimiento: impedía cargar el controlador.
3. Expresión Blade incompleta en el detalle: provocaba 500 al renderizar.
4. Confirmación llamaba a `authorize()` inexistente; ahora utiliza `Gate::authorize('inventario.conteo.confirmar')`, permiso real del módulo.
5. Acciones sobre tomas no comprobaban sucursal activa: se agregó el filtro junto al de empresa.
6. El OR del barcode adicional eludía los filtros de empresa/actividad/inventario; ahora queda agrupado dentro de la búsqueda y exige asociación a la sucursal activa.
7. Confirmación con modelo obsoleto podía duplicar ajuste: ahora bloquea y relee el documento en la transacción antes de validar su estado.
8. La vista incluía el scanner sin apertura ni receptor: botón de cámara y listener reutilizan `mvs-scanner-open` y `mvs-scan`.

No se modificó `InventoryPostingService.php`, modelos, migración, seeder ni sidebar durante esta tarea; estos últimos ya formaban parte del working tree entregado.

## Scanner y responsive

Búsqueda HTTP probada por nombre parcial, código interno, barcode principal y adicional; producto inexistente y productos fuera de ámbito/inactivos/sin inventario son rechazados. La vista reutiliza exactamente un `x-scanner.mvs-scanner`, abre el componente compartido y consume su evento. No incorpora `<video>`, `getUserMedia` ni `BarcodeDetector` propios.

Markers `data-responsive="360 768 1280"` comprobados en listado, creación, edición y detalle; controles `min-h-11` y búsqueda/cámara con envoltura móvil. Detalle no incluye scanner; edición conserva exactamente uno. Revisión conceptual 360/768/1280: buscador en fila propia en móvil y controles con wrap, tabla dentro de `overflow-x-auto`. **Sin hardware, navegador ni medición visual real**; estos tests verifican contratos estáticos, no usabilidad completa.

## Auditoría de precisión del Kardex (solo lectura)

- La migración inicial `2026_08_04_024743_create_inventory_movements_table.php` declara `quantity`, `previous_stock` y `new_stock` como `decimal(14,2)`, pero `2026_08_13_000003_increase_inventory_movement_precision.php` las amplía a **decimal(19,4)**. No se encontró una reversión posterior en las migraciones ascendentes. `InventoryMovement` convierte las tres a `decimal:4`.
- Consulta local de solo lectura sobre `database/database.sqlite`, con `PRAGMA query_only=ON`: las tres columnas aparecen como **numeric** (representación SQLite) y la migración de ampliación consta en `migrations`. SQLite no prueba enforcement de escala de PostgreSQL. No se consultó producción.
- El stock operativo `branch_product.stock` es `decimal(15,4)`; lotes, compras y Toma usan cuatro decimales. Ventas, devoluciones y transferencias también tienen cantidades de cuatro decimales. No todo el código es uniforme: `products.stock` conserva `decimal(15,2)` legado, existen cálculos con floats y `resources/views/kardex/index.blade.php` presenta saldos con `number_format(..., 2)`.
- Dependencias comprobadas: ventas/POS y transferencias mediante `InventoryPostingService`; ajustes manuales y Toma; compras/anulación; apartados/reserva/liberación; migración de inventario inicial/Kardex histórico; consulta Kardex, reportes, exportaciones y conciliación de migraciones. Importación ordinaria conserva su llamada histórica a `postImportMovement()` ausente, sin corregirla.
- **MIGRACION_REQUERIDA: NO**, no hace falta una nueva migración para estas tres columnas. En un entorno que aún conserve dos decimales, revisar el estado de la migración existente antes de considerar cambios. La presentación a dos decimales es una revisión independiente de UI; no requiere alterar la tabla. Ninguna tabla ni migración fue modificada en esta corrección.

## Regresiones

Comando ejecutado con `APP_KEY` temporal solo en el proceso:

`php artisan test tests/Feature/InventoryImportHardeningTest.php tests/Feature/InventoryMigrationP36Test.php tests/Feature/InventoryPostingSaleTest.php tests/Feature/InventoryTransferP24Test.php --log-junit storage/logs/inventory-count-regressions.xml`

| Suite | Resultado | Aserciones |
| --- | --- | --- |
| InventoryImportHardeningTest | 5 aprobadas, 2 errores | 22 |
| InventoryMigrationP36Test | 14/14 | 148 |
| InventoryPostingSaleTest | 6/6 | 32 |
| InventoryTransferP24Test | 21 aprobadas, 1 fallo | 300 |
| Total | 46/49 | 502 |

Errores de importación: `test_valid_confirmation_creates_company_catalog_product_barcode_stock_and_movement` y `test_confirmation_is_atomic_when_a_later_row_is_no_longer_valid`, por `InventoryPostingService::postImportMovement()` ausente. Fallo de transferencias: `test_phase_a_receipt_rejects_unsupported_lines_without_mutating_then_accepts_exact`, espera texto con mojibake (`confirmÃ³`) frente al mensaje correcto del controlador. Los archivos involucrados estaban sin cambios al inicio y continúan sin diff. No se corrigieron por alcance y exclusión expresa del servicio.

`php artisan test --filter='InventoryAdjustment|InventoryMovement'` no encontró tests (0; salida 1). No existen suites con esos nombres. Los ajustes y movimientos de la toma se validan directamente en la suite focal; contabilización de ventas y migración aportan regresión del stock/Kardex existente. No se presenta el filtro vacío como aprobado.

## Comprobaciones y alcance

- `php -l` de controlador y ambos modelos: correcto.
- `php artisan route:list --name=inventory-counts`: 15 rutas, resueltas al controlador real.
- `git diff --check`: correcto.
- Working tree conservado; los archivos nuevos siguen sin seguimiento. `git diff --stat` no incluye archivos sin seguimiento.
- Listo para auditoría final del módulo con las limitaciones anteriores. La regresión general no está completamente verde; no constituye aprobación de producción.
