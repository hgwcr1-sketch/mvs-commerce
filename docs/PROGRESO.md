# MVS Commerce — Progreso del proyecto

## Estado general

MVS Commerce está en desarrollo activo.

La rama de trabajo principal conocida actualmente es:

`feature/pos`

El proyecto utiliza Git y GitHub como control de versiones y respaldo remoto.

Antes de iniciar una tarea nueva, revisar siempre:

- `git status`
- último commit
- módulo afectado
- pruebas relacionadas
- documentación en `docs/`

---

## Módulos completados o estables

### Clientes

Estado: COMPLETO

Incluye:

- CRUD
- activar/inactivar
- contactos
- direcciones
- dirección principal
- aislamiento por empresa
- unicidad empresarial por identificación
- exportación XLSX/CSV mediante la infraestructura común D09
- P32: plantilla XLSX e importación XLSX/XLS/CSV con vista previa, errores por fila/campo, normalización de teléfonos, deduplicación empresarial por identificación/teléfono/correo y confirmación transaccional

#### P31/P32 — Infraestructura de migración + Clientes: COMPLETADOS ADELANTADAMENTE

- P31 reutiliza `DataCenterController`, PhpSpreadsheet, `DataExportService`, el patrón preview/confirmación de `InventoryImportService`, los flujos caracterizados de Compras y `PhoneNumberService`; no crea otro exportador de Clientes.
- `CustomerImportService` valida y normaliza cada fila sin escribir durante el preview; la sesión queda ligada a `company_id` y la confirmación revalida contra el estado actual antes de una transacción única.
- Duplicados: identificación exacta, teléfono/móvil normalizados y correo case-insensitive, tanto contra clientes activos/eliminados de la empresa como dentro del archivo. La misma identidad puede existir en otra empresa.
- Clientes es empresarial en el modelo real y no tiene `branch_id`; no se inventó asociación por sucursal.
- Evidencia: `CustomerImportP32Test` 6/6, 42 aserciones; regresión con `DataExportTest`, `DataCenterShellTest` y `CustomerCompanyIsolationTest`: 23/23, 139 aserciones.
- Pendiente sin implementar: P37 mantiene saldos y movimientos históricos de Fidelización separados de inventario.

#### P33 — Migración de Productos: COMPLETADO ADELANTADAMENTE

- Reutiliza Centro de Datos, PhpSpreadsheet y `DataExportService`; añade plantilla XLSX e importación XLSX/XLS/CSV con preview y confirmación explícita.
- Valida por fila/campo, resuelve categorías, marcas y unidades dentro de `company_id`, y revalida antes de confirmar. Códigos internos y códigos de barras respetan las restricciones únicas reales de la base de datos, incluso ante concurrencia.
- Costos, precios e impuesto se validan como cadenas decimales de máximo dos decimales; no se calculan ni persisten con aritmética `float`.
- La confirmación usa una transacción única y solo crea catálogo/productos/códigos. No crea `branch_product`, no modifica existencias y no genera Kardex: stock inicial e histórico pertenecen a P36.
- Evidencia: `ProductImportP33Test` 6/6, 62 aserciones; regresión con exportación, Centro de Datos, Inventario, Compras y navegación responsive: 38/38, 240 aserciones.
- Preparación sin implementar: P34/P35 podrán resolver los productos importados por código/barcode; P36 conserva la responsabilidad exclusiva de stock inicial y Kardex histórico por sucursal.

#### P34/P35 — Migración de Ventas históricas: COMPLETADOS ADELANTADAMENTE

- Plantilla única por líneas repetidas incluye encabezado, fecha, sucursal, cliente, moneda, totales, producto, cantidad, precio, descuento, impuesto y costo; admite XLSX/XLS/CSV y exportación equivalente.
- Preview valida fila/campo/documento, pertenencia empresarial, consistencia de encabezados y conciliación decimal a cuatro posiciones. La combinación `company_id + sale_number` aporta idempotencia y se revalida antes de confirmar.
- La confirmación transaccional escribe directamente `sales` y `sale_items` con `is_historical=true`, `cash_session_id=null`, saldo cero y sin invocar `PosSaleProcessor` ni servicios de caja, inventario, pagos, CxC, fidelización o comunicaciones.
- Las anulaciones y devoluciones operativas rechazan ventas históricas, evitando efectos posteriores sobre inventario o puntos; sus acciones también se ocultan en el detalle.
- Evidencia: `HistoricalSaleImportP34P35Test` 6/6, 64 aserciones; regresión de Ventas, anulaciones, devoluciones, reportes, exportación, Centro de Datos y P33: 55/55, 377 aserciones.
- P36 conserva la responsabilidad exclusiva de inventario inicial y Kardex histórico por sucursal; ver cierre siguiente.

#### P36 — Migración de Inventario inicial y Kardex: COMPLETADO ADELANTADAMENTE

- Plantilla única y exportación equivalente distinguen `saldo_inicial` de `movimiento_historico`; admite XLSX/XLS/CSV con preview, errores fila/campo y permisos de sucursal existentes.
- Todas las cantidades usan cadenas decimales de cuatro posiciones. El historial valida entrada/salida, anterior+naturaleza+cantidad=nuevo y continuidad por producto+sucursal.
- `inventory_migration_batches` registra empresa, usuario, clave de origen, filas y fecha; la clave única `company_id+source_key` impide duplicar un lote al reintentar.
- La confirmación es transaccional. Saldo inicial usa locking en `InventoryPostingService`, fija `branch_product` y deja Kardex `initial_balance`; `historical_entry/exit` preserva fecha y trazabilidad sin alterar el stock actual.
- No crea ventas, compras, caja, pagos, CxC ni fidelización. Evidencia: `InventoryMigrationP36Test` 6/6, 62 aserciones; regresión Inventario/Transferencias/Compras/Exportación/Centro de Datos/P33–P35: 51/51, 401 aserciones.

#### P37–P40 — Fidelización, paquete, ensayo y cierre: COMPLETADOS ADELANTADAMENTE

- P37 importa saldos consolidados y Kardex histórico sin reaplicar puntos, bloquea cuentas con actividad operativa y conserva aislamiento, idempotencia y rollback.
- P38 genera un ZIP portable de clientes, productos, facturas/líneas, Kardex y puntos con manifiesto, conteos y SHA-256.
- P39 incorpora preview, conciliación, errores CSV, revalidación y reintento sin duplicados.
- P40 deja el procedimiento SQLite→PostgreSQL y la conciliación read-only documentados/probados; no se ejecutó PostgreSQL ni producción.
- Evidencia: focal 9 tests/90 aserciones; regresión P31–P40 y Fidelización relacionada 62 tests/488 aserciones.
- P37 queda pendiente y separado.

---

### Seguridad

Estado: COMPLETO

Incluye:

- login
- forgot password
- reset password
- remember me
- usuarios activos/inactivos
- último acceso

---

### Proveedores

Estado: COMPLETO

Incluye estructura funcional para uso en compras y abastecimiento.

---

## Módulos base operativos

### Multiempresa

Estado: BASE FUNCIONAL

Incluye:

- empresa activa
- límites de empresas
- separación por empresa

Pendiente:

- ampliar interfaz de administración de restricciones empresariales

---

### Multisucursal

Estado: BASE FUNCIONAL

Incluye:

- sucursal principal
- sucursal activa
- middleware de sucursal activa
- inventario por sucursal

---

### Usuarios, roles y permisos

Estado: BASE FUNCIONAL

Incluye:

- roles por empresa
- permisos por módulo
- protección del último administrador
- sidebar condicionado por permisos

---

### Dashboard

Estado: ACTIVO

Incluye vistas y comportamiento según perfil del usuario.

---

## Inventario

Estado: ACTIVO

Incluye:

- stock por sucursal
- branch_product
- inventory_movements
- Kardex
- ajustes
- transferencias

Regla:

Toda modificación de inventario debe respetar trazabilidad y sucursal.

#### P24 — Transferencias: pruebas, scoping y centralización

- `TransferController::store` delega el movimiento de stock y el Kardex a `InventoryPostingService::postTransfer` (4 decimales con BCMath, `lockForUpdate`, rollback atómico de origen/destino/item/kardex), preservando el concepto de transferencia (`transfer_number` `TR-`, `status`, `transferred_at`).
- Scoping por empresa y sucursal: middleware `active.branch` en rutas de transferencias; creación restringida a sucursales activas asignadas al usuario salvo permiso `inventario.ver_otras_sucursales`; listado/index filtrado por sucursales asignadas.
- Búsqueda de productos para transferencia (`transferencias.products.search`) aislada por empresa: solo activos, `track_inventory=true`, con stock de la sucursal activa.
- Validaciones: cantidad con hasta 4 decimales, stock suficiente en origen, producto con `track_inventory`, unidad sin decimales rechaza fraccionado, productos con lote bloqueados.
- Decisión de flujo: **transferencia instantánea** (`status=completed`, `transferred_at` inmediato). No se implementó el flujo de envío/recepción.
- Evidencia: `InventoryTransferP24Test` 7/7, 54 aserciones. Regresión relacionada (excl. `PosAccessAndSearchTest`) verde salvo 2 fallos históricos de deriva no atribuibles a P24: `PosSuspendedSalesTest` (formato de precio en recuperación: `125` vs `'125.00'`) y `OrderPosCreationTest` (302 en payload POS). Ver `docs/CRONOGRAMA_PRODUCCION.md`.
- `PosAccessAndSearchTest` conserva 3 fallos históricos ajenos a P24: dos contratos de payload mínimo ya ampliados (productos y clientes) y un snapshot antiguo del modal/checkout POS. P24 no modifica `PosController` ni `resources/views/pos/index.blade.php`; no se corrigió esa deuda.

---

## Compras

Estado: EN USO

Incluye:

- compras
- importación Excel
- importación XML
- manejo de CABYS
- relación con proveedores
- afectación de inventario

---

## POS

Estado: DESARROLLO ACTIVO

Incluye actualmente:

- payment_methods
- sales
- sale_items
- sale_payments
- formas de pago
- POS principal
- búsqueda de productos
- ventas
- descuentos
- pagos
- inventario por sucursal
- cliente / consumidor final
- permisos relacionados

Formas de pago base:

- efectivo
- tarjeta
- SINPE

Arquitectura relevante:

- `PosController`
- `PosSaleProcessor`
- `Sale`
- `SaleItem`
- `SalePayment`
- `PaymentMethod`

El POS es uno de los módulos principales actualmente en expansión.

### R02-A — POS móvil/tablet operativo: COMPLETADO

Adaptación exclusivamente de presentación del POS (`pos/index.blade.php`) sin cambiar el estado Alpine (`posTerminal`), la lógica de checkout ni el backend:

- Carrito: una sola plantilla; <1024px cada línea se presenta como tarjeta (grid) con etiquetas, targets táctiles ≥44px (±, cantidad, descuento, eliminar) y badge "Oferta" cuando `is_offer`; ≥1024px se restaura exactamente la tabla original (encabezado, anchos, paddings).
- Tablet 768–1023px: composición de dos paneles (`md:grid-cols-[1.6fr_1fr]`) con carrito a la izquierda y cliente/totales sticky a la derecha; desktop `lg:grid-cols-4` intacto.
- Barra sticky Total/Cobrar (<1024px): reutiliza `grandTotal`, `canCheckout` y `openCheckout`; oculta con carrito vacío, durante checkout o con cualquier modal POS abierto; `z-[90]` debajo de los modales; respeta safe-area y se desplaza fuera de vista al abrirse el teclado móvil (mecanismo compartido con la barra inferior R01); layout reserva espacio (`pb-24 lg:p-6` en rutas POS).
- Foco: `autofocus` eliminado; nuevo helper `focusSearch()` solo enfoca con puntero fino (desktop/lector HID), aplicado también a los refocos tras acciones.
- Lectores USB/Bluetooth/teclado: flujo HID sobre el buscador sin cambios.

Evidencia: regresión POS + Loyalty + Devoluciones 386 tests / 2391 aserciones idéntica al árbol limpio (los 5 fallos detectados son preexistentes de HEAD, por deriva backend–tests: claves del payload de búsqueda, formato de precio en recuperación y validación de caja en un test de suspendidas; no corresponden a esta tarea). `npm run build` correcto. Pendiente **R02-B**: escáner por cámara (BarcodeDetector + fallback), sin dependencias instaladas todavía.

### R02-B — Escáner por cámara para POS: COMPLETADO

Capa de entrada adicional para leer códigos de producto con la cámara en celular/tablet, reutilizable para R03 sin copiar código. El escáner no tiene lógica propia de productos: entrega el código a la búsqueda existente del POS (`onMvsScan` → `query` → `searchProducts()` → coincidencia exacta `matched_barcode` → `addProduct` existente).

Implementación:

- `resources/js/scanner/engine.js`: motor headless con detección progresiva — `BarcodeDetector` nativo si está disponible y soporta los formatos; fallback local `@zxing/library` (única dependencia nueva, autorizada; procesamiento 100% local, sin APIs externas) cargado por dynamic import como chunk separado (`esm-*.js`, fuera del bundle inicial). Formatos: EAN-13, EAN-8, UPC-A, UPC-E, Code 128, Code 39 y QR con gate `isProductCodeText()`.
- QR seguros: URLs/tokens (portal F33/F34), QR de cliente o fidelización nunca se buscan como producto; muestran mensaje comprensible dentro del escáner, sin navegar ni emitir evento.
- `resources/js/scanner/index.js`: componente Alpine reutilizable `mvsScanner`; contrato por eventos de ventana: `mvs-scanner-open` (entrada), `mvs-scan` `{ code, source }` (salida única por lectura válida) y `mvs-scanner-change` `{ open }`. Cámara trasera por defecto (`facingMode: environment`) con cambio de cámara cuando existe más de una.
- `resources/views/components/scanner/mvs-scanner.blade.php`: hoja mobile-safe (`z-[130]`, cierre ≥44px, video `playsinline muted autoplay`, mensajes en español). Prop `video-id` lista para R03.
- Integración POS (`pos/index.blade.php`): botón táctil junto al buscador visible según disponibilidad en runtime (`window.mvsScannerAvailable`); mientras el escáner está abierto, la barra sticky Total/Cobrar se oculta (`!cameraScannerOpen`), el Enter global no abre checkout y Escape cierra solo el escáner. Búsqueda manual, teclado y lectores USB/Bluetooth HID intactos.
- Ciclo de vida: cámara solo tras acción explícita; `track.stop()` de todas las pistas + `srcObject = null` al cerrar, tras lectura válida, al ocultar la pestaña (`visibilitychange`) y al desmontar. Anti-doble lectura con cooldown de ~1,2 s y pausa total tras lectura válida.
- Seguridad/permisos: HTTP inseguro explica el requisito HTTPS sin romper el POS; mensajes específicos para permiso denegado, cámara inexistente, cámara ocupada y navegador incompatible.

Evidencia: `PosCameraScannerTest` (9 tests / 58 aserciones). Regresión: POS (80 tests, 75 verdes — los mismos 5 fallos preexistentes de HEAD por deriva backend–tests, verificados idénticos en árbol limpio mediante stash), POS-Loyalty (32/32), Loyalty (218/218). `npm run build` correcto (chunk ZXing diferido) y `git diff --check` limpio.

### R03 — Productos/Inventario móvil + cámara: COMPLETADO

Extiende R02 a los módulos de Productos e Inventario, reutilizando el mismo scanner sin duplicar código.

Implementación:

- `ProductController::search()` enriquecido: ahora busca también en la tabla `product_barcodes` (códigos secundarios), retorna `sale_price`, `cost`, `tax_rate`, `track_inventory` y `branch_stock` cuando se provee `branch_id` (o se usa la sucursal activa). Compatibilidad retro: consumidores existentes que solo usan `id/name/internal_code/barcode` no se afectan.
- `resources/views/productos/index.blade.php` — responsive mobile-first: estadísticas en grid 2→4 columnas, buscador con botón de cámara (`x-show="cameraAvailable"`), tabla completa en `hidden md:block`, tarjetas móviles (`md:hidden`) con imagen, nombre, código, categoría, marca, costo, precio, stock, estado y acciones táctiles ≥44px. Listener `mvs-scan` alimenta `doSearch()` que reutiliza el mismo `productos.search`.
- `resources/views/inventario/index.blade.php` — responsive mobile-first: buscador con cámara, tabla desktop completa, tarjetas móviles con nombre, código, categoría, stock/grande, mínimo, máximo y badge de estado (Disponible/Stock bajo/Sin stock). Listener `mvs-scan` idéntico al de productos.
- Ambas vistas incluyen `<x-scanner.mvs-scanner />` y Alpine `x-data` con `cameraAvailable` detectado desde `window.mvsScannerAvailable`.
- Sin rutas nuevas, sin backend adicional, sin header/sidebar, sin BeautyOS, sin nuevas dependencias.

Preparación arquitectónica: el patrón de integración (botón de cámara + listener `mvs-scan` + reutilización de search existente) está listo para reaplicar en Kardex, Ajustes, Transferencias y cualquier vista futura que necesite escaneo.

Evidencia: `PosCameraScannerTest` (9/9). Regresión POS + Loyalty: 180 tests / 1126 aserciones, mismos fallos preexistentes de HEAD. `npm run build` correcto, `git diff --check` limpio. 3 archivos modificados: `ProductController.php`, `productos/index.blade.php`, `inventario/index.blade.php`.

Pendiente **R04** (siguiente fase responsive).

---

## Caja

Estado: ACTIVO / EN EVOLUCIÓN

Incluye:

- apertura
- cierre
- entradas
- salidas
- sesiones
- denominaciones
- conciliaciones
- historial
- eventos
- notificaciones

También existe infraestructura de correo para eventos de caja.

Elementos conocidos:

- `CashSessionMailNotification`
- `SendCashSessionMailNotification`
- `CashSessionMailRetryService`

Existe trabajo pendiente o reciente relacionado con reintentos e idempotencia de notificaciones.

---

## Cuentas por cobrar

Estado: ACTIVO / EN DESARROLLO

Incluye trabajo relacionado con:

- ventas a crédito
- clientes
- pagos
- abonos

Los apartados tienen módulo propio (ver sección Apartados).

Debe integrarse con POS sin duplicar lógica.

---

## Cotizaciones

Estado: EN DESARROLLO

Existe estructura y trabajo relacionado con:

- Quote
- QuoteItem
- servicios
- requests
- integración con POS

No asumir que todo el flujo está terminado.

---

## Pedidos internos

Estado: ACTIVO

Incluye:

- pedidos internos con estado pendiente al crearse
- creación desde el flujo del POS mediante `pedidos.store`
- cantidades enteras o fraccionarias
- revisión de líneas de pedido
- asociación de proveedor por línea de producto

Elementos conocidos:

- `Order`
- `OrderItem`
- `OrderService`
- `OrderController`
- permisos `pedidos.ver`, `pedidos.crear`

Pruebas relacionadas: `OrderV1BaseTest`, `OrderPosCreationTest`, `OrderReviewTest`, `OrderSupplierAssignmentTest`, `OrderPermissionSeederTest`.

---

## Órdenes de compra

Estado: ACTIVO

Incluye:

- preparación de órdenes de compra a partir de pedidos internos
- estados: draft, prepared, sent, received, cancelled
- conversión de una orden preparada en compras reales, incluyendo conversión parcial
- trazabilidad entre pedido interno, orden de compra y compra

Elementos conocidos:

- `PurchaseOrder`, `PurchaseOrderItem`, `PurchaseOrderItemSource`, `PurchaseOrderSourceConversion`
- `PurchaseOrderPreparationService`
- `PurchaseOrderConversionService`
- `PurchaseOrderController`
- permisos `pedidos.preparar_compra`, `compras.ordenes`, `compras.crear`

Pruebas relacionadas: `PurchaseOrderTest`, `PurchaseOrderConversionTest`.

---

## Apartados

Estado: ACTIVO

Incluye:

- creación de apartados con reserva de inventario
- abonos
- cancelación con liberación de inventario
- vencimiento automático de apartados expirados
- entrega que genera una venta cuando el apartado está completamente pagado
- alertas de vencimiento próximo, con días configurables por empresa

Elementos conocidos:

- `Layaway`, `LayawayItem`, `LayawayPayment`, `LayawayAlert`
- `CompanyAllowance`
- `LayawayService`
- `LayawayController`
- permisos `apartados.*`

Nota: los apartados son un módulo propio con permisos independientes; no deben mezclarse con la lógica de cuentas por cobrar.

Pruebas relacionadas: `LayawayV1Test`.

---

## Devoluciones y anulación de ventas

Estado: ACTIVO

Incluye:

- devoluciones sobre ventas existentes con motivo y líneas específicas
- anulación de ventas

Elementos conocidos:

- `SaleReturn`, `SaleReturnItem`, `SaleReturnService`
- `SaleVoidService`
- `ReturnController`
- permisos `devoluciones.crear`, `ventas.anular`

Pruebas relacionadas: `SaleReturnTest`, `SaleVoidTest`.

---

## Cuentas por pagar

Estado: ACTIVO

Incluye:

- cuentas por pagar asociadas a compras
- abonos con afectación de sesión de caja activa
- alertas de vencimiento con días configurables por empresa

Elementos conocidos:

- `AccountPayable`, `AccountPayablePayment`, `AccountPayableAlert`
- `AccountsPayableService`
- `AccountPayableAlertService`
- `PurchaseAccountPayableService`
- `AccountsPayableController`
- permisos `cuentas_pagar.*`

Pruebas relacionadas: `AccountsPayableModelsTest`, `AccountsPayablePaymentsTest`, `AccountsPayableDashboardAlertsTest`, `AccountsPayableInterfaceTest`, `PurchaseAccountPayableIntegrationTest`.

---

## Facturación y reportes

Estado: NO IMPLEMENTADO

- `InvoiceController` y `ReportController` son esqueletos vacíos.
- Las rutas `facturas` y `reportes` existen actualmente sin middleware de permisos; deben protegerse o retirarse cuando se desarrolle el área.

Sí existe como funcionalidad interna: comprobante de venta del POS, impresión/PDF de compras e impresión de cotizaciones. No asumir facturación electrónica ni reportes gerenciales implementados.

---

## Fidelización

Estado: DESARROLLO ACTIVO — F01–F42 COMPLETADOS según el Cronograma Maestro (F28 de forma adelantada). Etapas 10, 11, 12 y 13 completas; etapa 14 parcial. Fuente oficial del orden: `docs/CRONOGRAMA_FIDELIZACION.md` (sincronizado con `docs/Cronograma_Maestro_Fidelizacion_MVS_Commerce_Actualizado_23-08-2026.xlsx`).

Infraestructura principal creada.

Elementos conocidos:

- `LoyaltySetting`
- `LoyaltyAccount`
- `LoyaltyMovement`
- `LoyaltyMovementLine`
- servicios en `app/Services/Loyalty`

También existen:

- controlador de movimientos
- request de listado
- vistas de fidelización
- pruebas específicas

### Funcionalidades implementadas

Entre las fases recientes se encuentran:

- infraestructura de fidelización
- cuentas
- movimientos
- acumulación de puntos por porcentaje configurable (F08/F08.1)
- bonos de cumpleaños (F10) y cliente recurrente (F11)
- multiplicadores (F12)
- reglas de ofertas en acumulación (F13, `earn_on_offers`)
- valor monetario del punto (F14)
- mínimo monetario de canje (F15)
- máximo de una compra pagable con puntos (F16)
- canje sobre ofertas (F17, `redeem_on_offers`)
- forma de pago Puntos integrada al POS (F18)
- premios por puntos: catálogo administrable de producto/descuento/servicio/regalo (F19)
- disponibilidad de premios: ilimitada, cupo propio o vinculada a stock real por sucursal (F20)
- canje de premios con historial auditable y coherencia Kardex (F21)
- vencimiento configurable: Sí/No + meses enteros libres de inactividad (F22)
- vencimiento automático de puntos por inactividad, trazable en Kardex e idempotente (F23)
- centro de reglas de Fidelización con configuración centralizada (F24)
- ajuste manual de puntos con motivo obligatorio, permiso propio e idempotencia (F25)
- saldo global por empresa verificado entre sucursales con aislamiento empresarial (F26)
- canje cruzado entre sucursales: POS y premios, con cupo global e inventario por sucursal ejecutora (F27)
- ajuste proporcional de puntos por devoluciones totales y parciales, trazable en Kardex (F29)
- reversión de puntos por anulación de venta (F28, adelantado)
- dashboard operativo con oportunidades, contactos y plantillas
- precisión decimal, idempotencia y última compra calificadora como propiedades transversales

### Fases confirmadas por auditoría técnica

Fuente de verdad utilizada (en orden): Git/commits → código actual → tests → cronograma maestro → documentación narrativa. El Excel maestro define el orden y la numeración oficiales.

#### Canje — F14 a F17 — COMPLETADO

- **F14 — Valor del punto:** equivalencia punto↔dinero configurable; `LoyaltyPointValueService`, `LoyaltyPointValueTest`.
- **F15 — Mínimo para usar puntos:** bloqueo/permiso según configuración; `LoyaltyRedemptionEligibilityService`, `LoyaltyRedemptionMinimumTest`.
- **F16 — Máximo de una compra:** porcentaje de la venta pagable con puntos; el canje nunca supera saldo disponible, porcentaje permitido ni monto aplicable; `LoyaltyRedemptionLimitService`, `LoyaltyRedemptionLimitTest`.
- **F17 — Usar puntos en ofertas:** configuración contextual del canje sobre ofertas; snapshots e idempotencia mediante `event_key` y atomicidad en `LoyaltyRedemptionService`; `LoyaltyRedemptionServiceTest`.

#### F18 — Forma de pago Puntos en POS — COMPLETADO

Capacidades verificadas:

- panel/resumen de puntos en POS;
- saldo, valor, mínimo y máximo visibles para el cajero (`LoyaltyPosSummaryService`);
- `requested_points` en checkout con validación decimal e idempotencia por token;
- ejecución real del canje durante el checkout mediante la forma de pago `loyalty_points`;
- movimiento real de fidelización vinculado a la venta (`event_key` derivado de la venta);
- rollback atómico del checkout si el canje falla o el cobro no cubre el total;
- bloqueo del canje combinado con venta a crédito;
- pagos mixtos con puntos respetando las reglas existentes de métodos, efectivo y cambio;
- los puntos participan en la conciliación del cierre de caja sin alterar el efectivo esperado;
- acceso a configuración de Fidelización según permisos.

Evidencia: commit `7be1f80`; `PosSaleProcessor`, `PosController::loyaltySummary`, `PaymentMethodProvisioner`; tests `PosLoyaltyInterfaceTest`, `PosCheckoutLoyaltyPointsRequestTest`, `PosCheckoutLoyaltyRedemptionTest`, `PosLoyaltyMixedPaymentsTest`, `LoyaltySettingsSidebarNavigationTest`.

Nota sobre subfases: las denominaciones F18A–F18F se utilizaron durante el desarrollo, pero no están etiquetadas explícitamente dentro del repositorio. No corresponde inventar una correspondencia histórica exacta de letras; las capacidades verificadas son las listadas arriba.

#### F19 — Premios por puntos — COMPLETADO (administración)

- estructura persistente `loyalty_rewards` con aislamiento por empresa, costo en puntos DECIMAL(19,4) y tipos: producto, descuento, servicio, regalo;
- administración básica: crear, editar y activar/desactivar desde la interfaz de Fidelización;
- permiso propio `fidelidad.premios` sembrado para Administrador y entrada "Premios" en el sidebar condicionada por permisos;
- validaciones: nombre, tipo permitido, costo positivo con máximo cuatro decimales, descripción opcional.

Evidencia: migración `2026_08_23_000001_create_loyalty_rewards_table`, `LoyaltyReward`, `LoyaltyRewardController`, `SaveLoyaltyRewardRequest`, rutas `loyalty.rewards.*`, vista `loyalty/rewards/index`; `LoyaltyRewardTest`.

#### F20 — Stock / disponibilidad de premios — COMPLETADO

- `availability_mode` en `loyalty_rewards`: `unlimited` (default), `limited` (cupo propio global por empresa, `stock_quantity` DECIMAL(15,4) nullable) y `product` (premio vinculado a producto real, `product_id` FK restrictOnDelete);
- la disponibilidad efectiva del modo `product` se deriva del stock en `branch_product` según la sucursal; sin contadores paralelos de inventario en Loyalty;
- servicio central reutilizable y de solo lectura: `LoyaltyRewardAvailabilityService::evaluate()` (unidad por redención V1 = 1); F21 lo consultará antes de canjear;
- validaciones condicionales: cupo > 0 para `limited`; producto activo de la misma empresa para `product`; campos prohibidos entre modos; cambio de modo limpia el dato del modo anterior;
- la administración de premios permite elegir modo y configurar cupo o producto (Alpine condicional, estilo MVS);
- F20 NO consume stock ni registra canjes: el descuento atómico (lock de cupo e `InventoryPostingService`) corresponde a F21.

Evidencia: migración `2026_08_23_000002_add_availability_to_loyalty_rewards_table`, cambios en `LoyaltyReward`, `SaveLoyaltyRewardRequest`, `LoyaltyRewardController`, vista de premios; `LoyaltyRewardAvailabilityTest` (7 tests).

Nota: el canje de premios por puntos corresponde a F21; esta fase cubre únicamente la administración del catálogo.

#### F21 — Historial de canjes de premios — COMPLETADO

- tabla `loyalty_reward_redemptions`: empresa, sucursal origen, cliente, usuario, premio, producto, puntos consumidos, movimiento de fidelización vinculado (`loyalty_movement_id`), `event_key` único por empresa y snapshots (nombre/tipo/modo del premio y nombre del producto) para trazabilidad histórica aunque el premio cambie después;
- servicio atómico `LoyaltyRewardRedemptionService::redeem()`: valida pertenencia a empresa/premio activo → consulta disponibilidad (`LoyaltyRewardAvailabilityService`) → cupo `limited` con `lockForUpdate`, re-verificación en transacción y descuento exacto de 1 unidad (nunca negativo) → historial → inventario exclusivamente vía `InventoryPostingService::postRewardRedemption` (tipo `reward_redemption`, referencia al canje) → puntos vía `LoyaltyAccountService` con el tipo existente `TYPE_REWARD`; cualquier fallo revierte todo;
- idempotencia doble: `(company_id, event_key)` único en historial y mecanismo `event_key` de movimientos; reintentos devuelven el canje original sin duplicar puntos, cupo, inventario ni historial;
- interfaz "Canjes de premios" (ejecutar + historial paginado) bajo permiso nuevo `fidelidad.canjes`, con aislamiento por empresa;
- criterio de cierre verificado: Kardex y registro de canje coinciden (puntos negativos, balance_after = saldo de cuenta, total_redeemed incrementado, referencias cruzadas).

Evidencia: migración `2026_08_23_000003_create_loyalty_reward_redemptions_table`, `LoyaltyRewardRedemption`, `LoyaltyRewardRedemptionService`, `InventoryPostingService::postRewardRedemption`, rutas `loyalty.redemptions.*`, vista `loyalty/redemptions/index`; `LoyaltyRewardRedemptionTest` (11 tests, 61 aserciones).

Nota (alcance histórico de F21): el canje no incluyó vencimiento (cubierto después por F22-F23), portal (F30+), online (F36–F37) ni devoluciones de canjes (F29).

#### F22 — Vencimiento configurable — COMPLETADO

- política de vencimiento Sí/No con cantidad libre de meses enteros de inactividad (1–120, sin opciones rígidas tipo 3/6/12);
- campos `expiration_enabled` (boolean) y `expiration_months` (unsignedInteger nullable) **reutilizados de la infraestructura base**; sin migraciones nuevas ni duplicación;
- validación condicional en `UpdateLoyaltySettingRequest`: meses obligatorios, enteros, ≥ 1 y ≤ 120 solo cuando está activado; prohibido indicar meses con el vencimiento desactivado; desactivar limpia los meses para representar claramente que los puntos no vencen;
- tarjeta nueva en la pantalla de configuración de Fidelización, consistente con el estilo MVS y protegida por los permisos existentes de configuración;
- F22 SOLO configura la política: no vence puntos, no crea movimientos ni procesos automáticos.

Evidencia: cambios en `UpdateLoyaltySettingRequest`, `SettingController` y vista `settings/index`; `LoyaltyExpirationSettingTest` (7 tests, 62 aserciones).

#### F23 — Vencimiento automático — COMPLETADO

- servicio central `LoyaltyExpirationService`: procesa únicamente empresas con Fidelización activa, `expiration_enabled` y `expiration_months` ≥ 1 (empresas inactivas se omiten);
- inactividad medida sobre `last_qualifying_purchase_at` normalizado al día local de la empresa (timezone validada contra identificadores válidos con fallback a `config('app.timezone')`);
- fecha límite = día local de la última compra calificable + meses mediante `addMonthsNoOverflow` (sin aproximación a días ni overflow: 31-ene + 1 mes vence el último día válido de febrero); vence cuando el día local actual alcanzó o superó la fecha límite;
- cuentas sin compra calificable (`last_qualifying_purchase_at` null) o con saldo cero no generan movimientos;
- expiración del saldo exacto bajo transacción y `lockForUpdate`, vía `LoyaltyAccountService::subtractPoints` con `TYPE_EXPIRATION`: saldo nunca negativo, `total_expired` actualizado automáticamente y movimiento coherente en Kardex (descripción "Vencimiento de puntos por inactividad", sin usuario ni sucursal, `source_type` `loyalty_expiration`);
- metadata auditable: `due_date`, `expiration_months`, `last_qualifying_purchase_at`;
- idempotencia por `event_key` único determinista `expiration:{account_id}:{fecha_limite}`: reintentos del mismo período no duplican movimiento, puntos ni totales; un nuevo período de inactividad produce una clave distinta;
- comando `loyalty:expire-points` (`ExpireLoyaltyPoints`) delega en el servicio, continúa aunque una cuenta individual se omita y reporta contadores: cuentas vencidas, puntos vencidos y omitidas;
- scheduler registrado una sola vez en `routes/console.php`: ejecución diaria con `withoutOverlapping()`, usando la infraestructura existente.

Evidencia: `app/Services/Loyalty/LoyaltyExpirationService.php`, `app/Console/Commands/ExpireLoyaltyPoints.php`, `routes/console.php`; `LoyaltyExpirationTest` (13 tests, 78 aserciones). Regresión Loyalty + POS-Loyalty: 177 tests, 1160 aserciones, 0 fallos.

#### F24 — Centro de reglas — COMPLETADO

- pantalla "Centro de reglas" dentro de Fidelización (`loyalty.rules.index` / `loyalty.rules.update`, permiso existente `fidelidad.configuracion`), con estilo MVS y aislamiento por empresa vía sesión activa;
- edita directamente la fila única `LoyaltySetting` de la empresa (mismo registro que la configuración general): estado del módulo, porcentaje de acumulación, bono de cumpleaños, bono por retorno, acumulación en ofertas (`earn_on_offers`), valor del punto, canje (mínimo y máximo) y vencimiento;
- sin duplicación de validaciones ni persistencia: el mapeo de valores se centralizó en `UpdateLoyaltySettingRequest::toValues()` y lo consumen tanto `SettingController::update` como el nuevo `LoyaltyRuleCenterController::update`;
- tarjeta de accesos a reglas complementarias: Multiplicadores, Premios, Canjes de premios, Kardex y Configuración general (cada enlace respeta su permiso);
- entrada nueva en el sidebar bajo Fidelización; las rutas existentes no cambiaron;
- nota: el bono de cliente nuevo existe como tipo `new_customer` en la infraestructura (F09) sin política configurable propia; el centro queda listo para alojarla cuando se defina.

Evidencia: `LoyaltyRuleCenterController`, vista `loyalty/rules/index`, cambios en `UpdateLoyaltySettingRequest`, `SettingController`, `routes/web.php` y sidebar; `LoyaltyRuleCenterTest` (6 tests).

#### F25 — Ajuste manual de puntos — COMPLETADO

- pantalla "Ajustes de puntos" dentro de Fidelización (`loyalty.adjustments.index` / `store`) con permiso nuevo sembrado `fidelidad.ajustes`;
- formulario: cliente (solo de la empresa activa y activo), operación sumar/restar, cantidad de puntos (positiva, hasta cuatro decimales, DECIMAL(19,4)) y motivo obligatorio;
- persistencia exclusivamente vía `LoyaltyAccountService::adjustPoints` con `TYPE_ADJUSTMENT`: nunca edita saldo directo, sin floats, bloquea saldo negativo ("Saldo de puntos insuficiente") y actualiza balance/balance_before/balance_after;
- trazabilidad completa: usuario que ajusta, empresa, sucursal origen (sesión activa) y motivo como descripción del movimiento; metadata con dirección, puntos solicitados y motivo;
- idempotencia HTTP por token único del formulario: `event_key` = `adjustment:{uuid}` aprovechando el índice único `(company_id, event_key)` — un doble envío no duplica movimiento ni puntos;
- historial paginado de ajustes en la misma pantalla y movimiento visible/coherente en el Kardex (etiqueta existente "Ajuste");
- entrada nueva en el sidebar bajo Fidelización.

Evidencia: `StoreLoyaltyAdjustmentRequest`, `LoyaltyAdjustmentController`, vista `loyalty/adjustments/index`, `PermissionSeeder`; `LoyaltyManualAdjustmentTest` (10 tests). Regresión tras F24-F25: 193 tests Loyalty + POS-Loyalty, 1255 aserciones, 0 fallos.

#### F26 — Saldo global de empresa — COMPLETADO

- ya implementado por diseño desde la base (F06): `LoyaltyAccountService::getOrCreateAccount` resuelve la cuenta por `(company_id, customer_id)` sin intervención de sucursal; `branch_id` se registra únicamente como origen del movimiento;
- evidencia previa end-to-end: `LoyaltyPosIntegrationTest::test_customer_account_is_global_across_branches` (una sola cuenta, saldo suma ambas ventas, `branch_id` distinto por origen en Kardex);
- brecha cerrada en esta fase (pruebas): aislamiento empresarial explícito — dos empresas con el mismo escenario mantienen cuentas y saldos independientes; intentar operar el cliente de otra empresa es rechazado sin mutaciones (`LoyaltyMultiBranchTest::test_each_company_keeps_a_fully_separate_balance`);
- no hubo cambios de código de negocio: la cuenta global ya era la única fuente de verdad.

#### F27 — Canje en cualquier sucursal — COMPLETADO

- verificado sin cambios de código; los dos flujos de canje ya operan sobre el saldo empresarial y registran la sucursal ejecutora:
  - canje POS (`LoyaltyRedemptionService` vía `PosSaleProcessor`): acumulación en sucursal A y canje HTTP en sucursal B descuentan la misma cuenta; el movimiento registra `branch_id` B; reintentos del mismo token permanecen idempotentes (`LoyaltyMultiBranchTest::test_pos_redemption_at_branch_b_spends_balance_earned_at_branch_a`);
  - premios F21 (`LoyaltyRewardRedemptionService`): premio `unlimited` canjeable desde cualquier sucursal activa de la empresa; cupo `limited` es global por empresa (lock sobre la fila del premio) y bloquea desde B cuando A agotó el cupo; modo `product` consulta y descuenta stock exclusivamente de la sucursal ejecutora (bloquea sin stock en B aunque A tenga existencias), con `InventoryMovement.branch_id` = sucursal B;
- cross-company bloqueado en ambos flujos: validación de contexto en servicios (premio/cliente/sucursal ajenos) más reglas validadas en controladores; cobertura previa en `LoyaltyRewardRedemptionTest` y nueva en `LoyaltyMultiBranchTest`;
- permisos y sucursal activa respetados por los flujos existentes (POS: `pos.acceder`/`ventas.crear` y sesión activa; premios: `fidelidad.canjes`).

Evidencia: `tests/Feature/LoyaltyMultiBranchTest.php` (5 tests, 40 aserciones). Regresión tras F26-F27: 198 tests Loyalty + POS-Loyalty, 1295 aserciones, 0 fallos.

#### F29 — Ajuste por devolución — COMPLETADO

- nuevo `LoyaltySaleReturnAdjustmentService` invocado dentro de la transacción de `SaleReturnService::store()` (tras crear la devolución y sus líneas, antes de actualizar el estado de la venta): cualquier fallo de fidelización revuelve también inventario, caja y estado;
- **regla proporcional documentada** (BCMath escala 4, sin floats, redondeo half-up explícito):
  - puntos ganados: proporción = subtotal neto acumuladamente devuelto ÷ `base_amount` elegible del movimiento original de compra, con tope 1; objetivo = `round4(puntos_ganados × proporción)`; se aplica solo el delta contra lo ya revertido;
  - puntos canjeados: proporción = total acumuladamente devuelto ÷ total de la venta, con tope 1; objetivo = `round4(puntos_canjeados × proporción)`; delta contra lo ya restaurado;
- los deltas acumulativos garantizan que devoluciones parciales sucesivas nunca excedan lo originalmente ganado/canjeados, incluso con redondeo;
- bonos fijos (cumpleaños/retorno/promoción/cliente nuevo) no se revierten: no son proporcionales a la mercancía devuelta;
- idempotencia doble: `event_key` determinista `sale:return:{id}:earned` / `:redeemed` (índice único empresa+clave) y cálculo de deltas desde movimientos previos vía `related_movement_id`;
- saldo insuficiente para revertir puntos ya gastados: la devolución se rechaza completa con "Saldo de puntos insuficiente" y rollback total — sin saldos negativos ni inconsistencias silenciosas;
- Kardex: movimientos tipo existente `return` vinculados al earn/canje original, con sucursal, usuario, cliente, venta, devolución y metadata auditable (`kind`, `ratio`, montos acumulados, números de venta/devolución, motivo);
- `LoyaltyAccountService::reverseMovement` generalizado con monto parcial opcional (retrocompatible: F28 intacto); `updatedTotals` ahora descuenta del total correspondiente el monto realmente aplicado.

Evidencia: `app/Services/Loyalty/LoyaltySaleReturnAdjustmentService.php`, cambios en `SaleReturnService` y `LoyaltyAccountService`; `SaleReturnLoyaltyTest` (9 tests, 79 aserciones). Regresión tras F29: Devoluciones + F28 + Loyalty + POS-Loyalty: 228 tests, 1481 aserciones, 0 fallos.

#### F28 — Reversión de puntos por anulación — COMPLETADO (ADELANTADO)

Implementada durante la integración POS, antes de su posición en el cronograma (entre F19 y F27). Anular una venta revierte sus efectos de fidelización con trazabilidad e idempotencia.

Evidencia: commit `7be1f80`; `SaleVoidService`; `SaleVoidLoyaltyTest`.

Importante: el adelanto de F28 **NO** altera el orden del cronograma.

Auditoría posterior a la integración POS: se ejecutaron 152 tests relacionados con Loyalty / POS-Loyalty con 0 fallos.

Evidencia histórica:

- `8392dd4` — completar canje de puntos.
- `7be1f80` — integración de fidelización en POS.

#### F30 — Portal del cliente — COMPLETADO

- vista web responsive/mobile-first (`layouts/portal` + `loyalty/portal/show`) donde se consulta la fidelización de un cliente: saldo actual, valor monetario equivalente, historial de movimientos, premios activos y promociones vigentes;
- ensamblado de datos centralizado y de solo lectura en `LoyaltyCustomerPortalService`: saldo desde `LoyaltyAccount`, valor monetario reutilizando `LoyaltyPointValueService::moneyFromPoints` (sin cálculos propios; `null` si la configuración del punto es inválida), historial paginado (15 por página, más recientes primero) acotado a `(company_id, customer_id)`, premios `is_active` de la empresa ordenados por costo y promociones = multiplicadores vigentes con la misma semántica temporal/zona horaria de `LoyaltyMultiplierResolver` (F12);
- historial comprensible: fecha (d/m/Y H:i), tipo con etiqueta legible, descripción, referencia de origen (`source_type`/`source_id`) y puntos firmados; etiquetas centralizadas en `LoyaltyMovement::LABELS` (el Kardex administrativo ahora las reutiliza);
- aislamiento multiempresa: ruta `fidelidad/portal/{cliente}` (`loyalty.portal.show`, nombre existente del grupo `loyalty.*`) bajo `auth` + `active.company` + `permission:fidelidad.ver`; el controlador resuelve al cliente siempre dentro de la empresa activa (404 para clientes ajenos) y ningún query sale de esa pareja empresa/cliente;
- módulo inactivo o sin configuración: banner informativo, oculta saldo/catálogo/promociones y conserva el historial;
- cliente sin cuenta ni movimientos: saldo 0 y estados vacíos por sección;
- SIN QR, PIN, login especial, magic links ni identidad nueva de cliente (F31–F35 aportarán el acceso real); `LoyaltyCustomerPortalService` recibe empresa+cliente ya resueltos para que esas fases solo agreguen el mecanismo de resolución;
- no modifica reglas de acumulación, canje, expiración, anulación ni devoluciones; sin migraciones nuevas.

Evidencia: `app/Services/Loyalty/LoyaltyCustomerPortalService.php`, `app/Http/Controllers/LoyaltyCustomerPortalController.php`, `resources/views/layouts/portal.blade.php`, `resources/views/loyalty/portal/show.blade.php`, cambios en `routes/web.php`, `LoyaltyMovement::LABELS` y `LoyaltyMovementController`; `LoyaltyCustomerPortalTest` (7 tests, 51 aserciones). Regresión Loyalty/POS-Loyalty/Devoluciones tras F30: 244 tests, 1609 aserciones, 0 fallos.

#### F31 — Identidad visual del portal — COMPLETADO

- el encabezado del portal muestra la identidad de la empresa reutilizando únicamente datos existentes de `Company`: nombre comercial (`trade_name`) siempre y logo (`logo`, disco público) cuando existe;
- logo servido con la convención ya soportada por la arquitectura (`asset('storage/'.$company->logo)`, misma que `empresa/edit` e impresiones), con alt "Logo de {nombre comercial}";
- fallback elegante sin logo: avatar cuadrado redondeado (estilo MVS, ámbar/slate) con la inicial del nombre comercial;
- estilo general único MVS Commerce: sin temas por empresa, sin columnas nuevas de branding, sin permitir que cada empresa redefina colores/tipografías/layout; título del documento incluye el nombre comercial;
- aislamiento: cada portal muestra solo la identidad de la empresa activa del cliente consultado.

Evidencia: cambios en `resources/views/loyalty/portal/show.blade.php`; `LoyaltyCustomerPortalTest::test_portal_shows_own_brand_with_logo_or_elegant_fallback` (fallback sin logo, logo presente, identidad ajena ausente, cross-company 404). Revisión visual fina pendiente para F43 (UI/usabilidad).

#### F32 — Marca MVS Commerce — COMPLETADO

- pie discreto "Hecho con MVS Commerce" en `layouts/portal`, visible en móvil y escritorio, texto pequeño gris (`text-xs text-slate-400`), legible y no invasivo;
- sin enlace: no existe URL oficial de MVS Commerce configurada en el proyecto (única referencia hallada: correo del seeder administrativo), por lo que no se inventa destino.

Evidencia: cambio en `resources/views/layouts/portal.blade.php`; `LoyaltyCustomerPortalTest::test_portal_footer_shows_mvs_commerce_brand_discretely` (presencia del `<footer>` con la marca y verificación de que no contiene enlaces).

Regresión conjunta tras F31-F32: `LoyaltyCustomerPortalTest` (9 tests, 66 aserciones); suite Loyalty/POS-Loyalty/Devoluciones completa: 246 tests, 1624 aserciones, 0 fallos; `git diff --check` limpio.

#### F34 — Acceso por enlace seguro — COMPLETADO

- tabla `loyalty_portal_accesses`: empresa, cliente, usuario generador (nullable, auditoría), hash del token (SHA-256, único), `revoked_at`, `last_used_at`, timestamps; índice `(company_id, customer_id)`;
- **decisión de token**: entidad dedicada con token aleatorio de 60 caracteres vía CSPRNG (`Str::random`) almacenado únicamente como hash SHA-256 — el enlace en claro se muestra una sola vez al generar/regenerar (patrón password-reset/API tokens). Se descartaron signed URLs (`URL::temporarySignedRoute`) porque obligan a colocar el customer_id en la ruta, revelando un ID interno; también se descartó guardar el token plano para permitir revocación/regeneración/auditoría robustas sin exponer credenciales en la base;
- regeneración atómica: dentro de transacción y con lock se revoca el acceso activo previo del cliente y se crea uno nuevo — siempre un único acceso activo por cliente; revocación explícita desde la administración; `last_used_at` audita cada uso;
- ruta pública `/fidelidad/portal/acceso/{token}` (`loyalty.portal.access`) fuera del grupo de staff: valida formato del token, resuelve por hash, verifica acceso vigente + empresa activa + cliente no eliminado y renderiza el mismo portal F30–F32 reutilizando `LoyaltyCustomerPortalService`; protegida con `throttle:30,1`;
- la URL no contiene IDs internos ni datos personales (verificado contra identificación/email/teléfono/nombre del cliente);
- administración "Accesos al portal" (`loyalty.accesses.index/store/revoke`) bajo permiso nuevo sembrado `fidelidad.portal` (asignado automáticamente al rol Administrador por el `PermissionSeeder`), entrada condicionada en sidebar; genera/regenera mostrando el enlace una sola vez con botón copiar, lista accesos activos y permite revocar; aislamiento estricto por empresa activa (404 ante clientes ajenos);
- cross-company imposible: el propio token define la pareja empresa+cliente; ningún query depende de sesión;
- el acceso staff existente (`loyalty.portal.show`) no cambió.

Evidencia: migración `2026_08_23_000004_create_loyalty_portal_accesses_table`, `LoyaltyPortalAccess`, `LoyaltyPortalAccessService`, `LoyaltyPortalAccessController`, vista `loyalty/accesses/index`, cambios en `routes/web.php`, `PermissionSeeder` y sidebar; `LoyaltyPortalAccessTest` (7 tests, 70 aserciones).

#### F33 — QR — COMPLETADO

- generación local de QR integrada en "Accesos al portal" mediante `chillerlan/php-qrcode` 6.0.1 (estable, PHP puro, única dependencia autorizada; arrastra transitivamente `chillerlan/php-settings-container` 3.3.0). Sin APIs externas de QR: el token nunca sale del servidor;
- el QR codifica **exactamente** el enlace seguro F34 (`route('loyalty.portal.access', token)`): un solo mecanismo de tokens, sin segundo sistema, sin customer_id público y sin datos personales. Salida SVG vectorial (`QRMarkupSVG`, ECC nivel H, viewBox responsive) apta para impresión y visualización;
- `LoyaltyPortalAccessService::qrSupported()` ahora devuelve true cuando la librería está presente; `qrSvg(string $url)` genera el SVG bajo demanda. El token solo existe en claro en el momento de la generación, así que el QR se entrega junto al enlace en esa única respuesta y **nunca se persiste** (la tabla `loyalty_portal_accesses` sigue guardando solo el hash SHA-256 del token);
- regenerar o revocar el acceso invalida automáticamente el enlace y con él cualquier QR impreso anterior: el SVG anterior es determinísticamente el QR del enlace viejo, que ya no resuelve;
- flujo en `LoyaltyPortalAccessController::store`: genera/regenera acceso → flash `portal_url` + `portal_qr` (SVG); la vista "Accesos al portal" muestra enlace copiable + QR + botón "Imprimir QR" (ventana de impresión limpia con nombre del cliente); si la librería no estuviera disponible el panel lo indica y todo F34 sigue funcionando igual.

Evidencia: cambios en `LoyaltyPortalAccessService` (`qrSupported`, `qrSvg`), `LoyaltyPortalAccessController::store`, vista `loyalty/accesses/index`, `composer.json`/`composer.lock`; `LoyaltyPortalAccessQrTest` nuevo (5 tests): contenido determinista = enlace seguro exacto, sin identificación/email/teléfono/nombre/token/hash ni persistencia del QR, regeneración invalida enlace+QR previos, revocación mata el destino de un QR impreso, permisos (`fidelidad.portal`) y aislamiento por empresa; `LoyaltyPortalAccessTest` actualizado (7 tests): QR local sin servicios externos.

Regresión tras F33: suite Loyalty/POS-Loyalty/Devoluciones/Portal completa: 259 tests, 1749 aserciones, 0 fallos; Pint limpio; `git diff --check` limpio.

#### F35 — Publicidad/promociones del portal — COMPLETADO

- contenido promocional administrable por empresa, independiente de los multiplicadores F12 (que conservan su sección propia en el portal y toda su semántica);
- tabla `loyalty_promotions`: empresa, título (120), descripción corta opcional (500), `starts_at`/`ends_at` (UTC), `is_active`, `sort_order`; índice `(company_id, is_active, starts_at, ends_at)`. Sin sucursal en V1: el portal resuelve la empresa globalmente y los puntos son globales entre sucursales (F26). Sin imagen: no existe infraestructura segura/reutilizable de uploads para este módulo y no se inventó una;
- visibilidad centralizada en `LoyaltyPromotionService::vigentes()`: activa + vigente ahora, inicio y fin inclusivos, comparación en UTC con la zona horaria de la empresa (misma semántica temporal de F12); orden por `sort_order` ascendente y luego más recientes;
- administración "Promociones del portal" (`loyalty.promotions.index/store/update/toggle`) bajo permiso nuevo sembrado `fidelidad.promociones` (asignado automáticamente al rol Administrador), entrada condicionada en sidebar; formulario con validación de fechas (fin ≥ inicio) y conversión zona horaria de la empresa → UTC (`SaveLoyaltyPromotionRequest`); badge de estado por fila (Vigente/Futura/Vencida/Inactiva) vía `LoyaltyPromotionService::estado()`; aislamiento estricto: listado solo de la empresa activa y 404 al editar/cambiar estado de promociones ajenas;
- portal público F30–F34: sección nueva "Promociones vigentes" muestra solo promociones activas y vigentes de la empresa resuelta (título, descripción opcional y periodo d/m/Y en zona horaria empresarial), diseño responsive mobile-first y estado vacío elegante ("No hay promociones vigentes."); los multiplicadores pasaron a su propia sección "Multiplicadores de puntos" sin cambiar su comportamiento.

Evidencia: migración `2026_08_24_000001_create_loyalty_promotions_table`, `LoyaltyPromotion`, `LoyaltyPromotionService`, `SaveLoyaltyPromotionRequest`, `LoyaltyPromotionController`, vista `loyalty/promotions/index`, cambios en `routes/web.php`, `PermissionSeeder`, sidebar, `LoyaltyCustomerPortalService` y `loyalty/portal/show`; `LoyaltyPromotionTest` nuevo (6 tests): CRUD con precisión de zona horaria (CR UTC-6 → UTC), validaciones (fechas invertidas, título ausente, descripción >500, sort_order inválido), aislamiento por empresa (listado + update/toggle 404 cross-company), permisos completos, matriz de visibilidad en portal (vigente/futura/vencida/inactiva/ajena + orden por prioridad) y estado vacío.

Regresión tras F35: suite Loyalty/POS-Loyalty/Devoluciones/Portal completa: 265 tests, 1804 aserciones, 0 fallos; Pint limpio; `git diff --check` limpio.

#### F36 — Acumulación online — COMPLETADO

- verificado primero: NO existe infraestructura ecommerce ni integración de tienda online en el stack (INTEGRACIONES §5 futuro, D013 sin diseño aprobado); se implementó únicamente la capa mínima reutilizable para acreditar fidelización desde un evento online confirmado;
- `LoyaltyOnlineSaleService::accrueForSale(Sale $sale, ?Customer $customer, string $externalReference, string $channel = 'online')`: recibe una venta REAL ya persistida y confirmada (`status=completed`), resuelve empresa activa y sucursal válida desde la propia venta, exige referencia externa estable del pedido (`[A-Za-z0-9._:-]`, ≤100) y no inventa reglas nuevas sin cliente (no acredita, igual que hoy);
- cero duplicación de reglas: monto elegible y `earn_on_offers` vía `LoyaltyOfferEligibilityService` (F13), porcentaje/redondeo/precisión decimal vía `LoyaltyEarningService::earnFromEligibleAmount` (F08), multiplicadores vigentes vía `LoyaltyMultiplierResolver` (F12), bonos por retorno y cumpleaños con sus servicios propios (F11/F10); misma cuenta central `(company_id, customer_id)` — sin cuentas web paralelas;
- idempotencia determinista: `event_key` = `online_sale:{canal}:{referencia}:loyalty:earn` contra el índice único `(company_id, event_key)` de `loyalty_movements`; reintentos del mismo pedido/canal devuelven `duplicate=true` sin duplicar puntos, movimientos ni bonos; referencias distintas son eventos nuevos;
- origen auditado sin columnas nuevas: metadata del movimiento con `channel`, `origin='online'`, `external_reference`, `sale_number`, elegibilidad de ofertas; `source_type=Sale::class` + `source_id`; descripción distingue canal;
- seguridad: empresa inexistente/inactiva, venta no confirmada o sucursal inválida → ValidationException; cliente ajeno bloqueado por la validación central de acumulación; no toca inventario, no crea pedidos, checkout web ni API pública.

Evidencia: `app/Services/Loyalty/LoyaltyOnlineSaleService.php`; `LoyaltyOnlineSaleTest` nuevo (11 tests): acreditación con porcentaje exacto y metadata auditada, cuenta compartida con compra POS previa, F13 on/off, multiplicador x2 reutilizado, idempotencia ante evento duplicado, empresa inactiva/cliente ajeno/venta no confirmada bloqueados, sin cliente sin acreditación, inventario intacto, bono de cumpleaños fluyendo por el mismo pipeline. Regresión Loyalty/POS-Loyalty/Devoluciones/Portal: 276 tests, 1846 aserciones, 0 fallos; Pint limpio; `git diff --check` limpio.

#### F37 — Canje online — COMPLETADO

- ampliación de la capa online F36 (`LoyaltyOnlineSaleService::redeemForSale(Sale $sale, ?Customer $customer, string|int $requestedPoints, string $externalReference, string $channel)`): canje de puntos sobre una venta real confirmada, resolviendo empresa/sucursal desde la propia venta y sin confiar en IDs externos;
- cero duplicación de reglas F14–F17: valor monetario del punto, mínimo de saldo (`LoyaltyRedemptionEligibilityService`), máximo pagable con puntos calculado sobre el total de la venta (`LoyaltyRedemptionLimitService`, mismo parámetro que pasa el POS), `redeem_on_offers` y precisión decimal viven en `LoyaltyRedemptionService::redeem`; saldo central compartido con POS (una sola cuenta por `(company_id, customer_id)`);
- registro del pago con puntos idéntico al POS: `SalePayment` con el `PaymentMethod` tipo `loyalty_points` de la empresa (`affects_cash_snapshot=false`, `cash_effect_amount=0`, `created_by=sale->user_id`); movimiento y pago coordinados en UNA transacción — un fallo posterior al descuento revierte puntos y pago (probado inyectando fallo en la creación del pago);
- regla adicional de tope: nunca se acepta un canje cuyo monto supere el total aplicable de la venta (defensa explícita complementaria a F16);
- idempotencia determinista independiente del earn: `event_key` = `online_sale:{canal}:{referencia}:loyalty:redemption`; reintentos devuelven el resultado original (`duplicate=true`) sin duplicar movimiento ni pago; cliente obligatorio (null → error); venta no confirmada/empresa inactiva/sucursal inválida/cliente ajeno bloqueados;
- orden deliberado dentro de la transacción: primero las reglas de canje, después resolución del método de puntos y creación del pago — así la ausencia de configuración de pago no deja efectos parciales.

Evidencia: cambios en `LoyaltyOnlineSaleService` (+`redeemForSale`, +`paymentFor`, helper `reference()` compartido con F36); `LoyaltyOnlineRedemptionTest` nuevo (12 tests): canje exitoso con pago coherente, cuenta compartida POS/online, F15, F16 (rechazo y caso límite exacto), F17 on/off, saldo insuficiente, cliente null/ajeno, venta draft, duplicado sin doble efecto, rollback completo ante fallo, método de puntos ausente sin efectos, earn+redemption conviviendo con event_keys independientes. Regresión Loyalty/POS-Loyalty/Devoluciones/Portal: 288 tests, 1907 aserciones, 0 fallos; Pint limpio; `git diff --check` limpio.

#### F38 — Administrador (permisos) — COMPLETADO

- Auditoría completa del sistema de permisos de Fidelización;
- los 13 permisos `fidelidad.*` están sembrados en `PermissionSeeder` y asignados al rol Administrador via wildcard sync (`syncWithoutDetaching` de todos los permisos activos);
- todas las 28 rutas de Fidelización tienen middleware `permission:fidelidad.*` correcto (excepto la ruta pública `loyalty.portal.access` por diseño y `pos.loyalty.summary` que es intencional para POS);
- el sidebar muestra las 10 entradas condicionadas por permiso;
- los 10 controladores de Fidelización scopean correctamente por `session('active_company_id')` con protección cross-company;
- pruebas: `LoyaltyAdminAuthorizationTest` (28 tests, 88 aserciones): permisos sembrados, Admin recibe todos, acceso Admin a 12 rutas, 403 en 10 rutas sin permiso, sidebar completo, 2 tests de aislamiento cross-company;
- regresión: Loyalty 295/295, POS-Loyalty 18/18, Portal 26/26, Sidebar 2/2, 0 fallos.

Evidencia: `tests/Feature/LoyaltyAdminAuthorizationTest.php`; `git diff --check` limpio.

#### F39 — Cajero (permisos) — COMPLETADO

- auditoría confirmó que no era necesario modificar reglas ni rutas: la consulta de puntos vive en el POS bajo `pos.acceder` y el canje real durante checkout bajo `ventas.crear`;
- los roles son configurables por empresa, por lo que no se concedieron permisos por nombre ni se acopló el rol `Cajero` a permisos administrativos `fidelidad.*`;
- prueba end-to-end con permisos mínimos: consulta saldo y máximo canjeable, completa una venta con pago mixto de puntos + efectivo y registra el movimiento con empresa, sucursal y usuario correctos;
- configuración general, centro de reglas, ajustes, multiplicadores, premios, canjes administrativos, accesos al portal y promociones permanecen protegidos y devuelven 403;
- el sidebar del Cajero no expone entradas de administración de Fidelización.

Evidencia: `LoyaltyCashierAuthorizationTest` (3 tests, 22 aserciones); regresión `php artisan test --filter Loyalty`: 298 tests, 1910 aserciones, 0 fallos.

#### F40 — Indicadores — COMPLETADO

- `LoyaltyDashboardIndicatorService` agrega las cuentas de Fidelización acotadas por `company_id`, sin duplicar datos ni calcular desde vistas;
- indicadores acumulados: clientes con cuenta, puntos generados (`total_earned`), canjeados (`total_redeemed`), vencidos (`total_expired`) y saldo vigente (`balance`);
- precisión decimal conservada a escala 4 con BCMath al normalizar resultados; una empresa sin cuentas obtiene ceros explícitos;
- dashboard existente conserva oportunidades y movimientos del día y añade cinco tarjetas responsive mobile-first (`sm:grid-cols-2`, `xl:grid-cols-5`);
- aislamiento empresarial probado con datos ajenos de magnitud identificable; F40 no introduce desglose por sucursal, reservado expresamente para F41.

Evidencia: `LoyaltyDashboardIndicatorTest` (3 tests, 5 aserciones); validación focalizada dashboard/autorización: 40 tests, 131 aserciones; regresión `php artisan test --filter Loyalty`: 301 tests, 1915 aserciones, 0 fallos.

#### F41 — Empresa / sucursal — COMPLETADO

- el resumen F40 continúa siendo la cifra global y única de las cuentas por empresa;
- `LoyaltyDashboardIndicatorService::byBranch()` agrega por origen (`branch_id`) clientes con movimiento, puntos generados, canjeados y vencidos;
- los movimientos `return`/`void` se clasifican mediante su `related_movement_id`: restan del generado si revierten acumulación y del canjeado si restauran un canje, evitando totales brutos engañosos;
- se incluyen sucursales sin actividad con ceros y una fila "Sin sucursal" para vencimientos automáticos u otros movimientos globales;
- el saldo no se atribuye a una sucursal: conforme F26 es global por `(company_id, customer_id)` y la vista lo indica expresamente;
- UI responsive: tarjetas con métricas en móvil (`md:hidden`) y tabla contenida en `overflow-x-auto` para tablet/escritorio.

Evidencia: `LoyaltyDashboardIndicatorTest` ampliado (5 tests, 17 aserciones); validación focalizada dashboard: 14 tests, 55 aserciones; regresión `php artisan test --filter Loyalty`: 303 tests, 1927 aserciones, 0 fallos.

#### F42 — Suite de pruebas — COMPLETADO

- suite completa de Fidelización desde F01 hasta F41 ejecutada con `php artisan test --filter Loyalty`: 303 tests, 1927 aserciones, 0 fallos;
- cubre infraestructura, cuenta/Kardex, acumulación, canje, premios, vencimiento, administración, multisucursal, reversiones, portal, online, permisos y dashboard;
- auditoría adicional de la suite global sobre el punto limpio `ba8a2c8`: 728 tests, 721 pasan, 4190 aserciones y 7 fallos preexistentes fuera de F42;
- fallos reproducidos aisladamente (56 tests, 49 pasan, mismos 7): `ExampleTest` espera 200 donde la aplicación redirige 302; `PosAccessAndSearchTest` conserva tres contratos antiguos (payloads ampliados y flujo de pago actualizado); `PosSuspendedSalesTest` conserva una expectativa de tipo antigua y un checkout sin caja; `QuoteTest` convierte sin la caja ahora obligatoria;
- no se corrigieron esas pruebas ajenas durante F42. Se conserva la línea base explícita para F44 (regresión Clientes/POS/Compras/Caja).

Evidencia: ejecución completa y reproducción aislada sobre `ba8a2c8`; sin cambios de código de negocio en F42.

#### F43 — UI / usabilidad — COMPLETADO

- auditoría de las 13 vistas de Fidelización y verificación conceptual mobile-first en 360, 768 y 1280 px;
- el encabezado del dashboard ahora apila título y CTA en móvil; la acción ocupa el ancho disponible y mantiene su presentación compacta en desktop;
- oportunidades conserva sus reglas, permisos y datos, pero presenta filtros de una columna en móvil, tarjetas para filas con acciones repetitivas y tabla contenida desde `md`;
- acciones primarias con altura táctil de 44 px en móvil y mínimo de 40 px en tabla desktop; no se introdujo overflow horizontal a nivel de página;
- sin cambios de backend, reglas de negocio, permisos, empresa/sucursal ni dependencias.

Evidencia: `LoyaltyUiUsabilityTest` (2 tests, 15 aserciones); validación focalizada dashboard/UI (16 tests, 70 aserciones); regresión `php artisan test --filter Loyalty` (305 tests, 1942 aserciones); `npm run build`, Pint focalizado y `git diff --check` correctos.

#### F44 — Regresión — COMPLETADO

- se ejecutaron los 26 archivos de Clientes, POS, Compras, Caja y Cotizaciones vinculadas al POS: 289 tests, 283 pasan, 1698 aserciones y 6 fallos;
- los 6 fallos son exactamente los preexistentes documentados en F42: tres contratos antiguos en `PosAccessAndSearchTest`, dos en `PosSuspendedSalesTest` (tipo monetario y caja obligatoria) y uno en `QuoteTest` (caja obligatoria);
- evidencia limpia separada: Clientes/Compras/Caja 134/134 (761 aserciones) y POS sin los dos archivos históricos 100/100 (573 aserciones);
- reproducción aislada: los tres archivos conocidos ejecutan 55 tests, 49 pasan y repiten los mismos 6 fallos; no apareció un fallo nuevo;
- el séptimo fallo global de F42 es `ExampleTest` y queda fuera del criterio Clientes/POS/Compras/Caja; no se corrigió deuda ajena ni se cambió código de negocio.

La etapa 14 de Calidad queda completa. Evidencia adicional: árbol limpio al iniciar F44 y `git diff --check` sin incidencias.

#### F45 — Respaldo GitHub — COMPLETADO

- rama autorizada `feature/pos`, sincronizada con `origin/feature/pos` en `5decbca` antes de crear el registro final;
- F43 (`3efe76f`) y F44 (`5decbca`) publicados como commits exclusivos y recuperables;
- validación final de Fidelización: 305 tests, 1942 aserciones, 0 fallos;
- `git diff --check` correcto y working tree limpio antes del commit documental de cierre;
- el commit exclusivo de F45 completa el respaldo y se verifica con push y working tree limpio.

El Cronograma Maestro de Fidelización F01–F45 queda completo. No existe una fase funcional posterior dentro de este cronograma.

### Brechas detectadas pendientes

- **F29 — Ajuste por devolución: COMPLETADO (cerraba esta brecha).** Toda devolución total o parcial ajusta fidelización dentro de la transacción de `SaleReturnService` vía `LoyaltySaleReturnAdjustmentService`: reversión proporcional de puntos ganados y restauración proporcional de puntos canjeados (BCMath escala 4, redondeo half-up, deltas acumulativos con tope sobre lo original), idempotencia por `event_key` por devolución y tipo, Kardex auditable y rechazo atómico ante saldo insuficiente. Ver sección F29 más abajo.
- WhatsApp: actualmente registra contactos y plantillas, pero no realiza envío por API. Brecha futura fuera del cronograma; no es la siguiente tarea.
- Discrepancias adicionales entre cronograma y código están registradas en `docs/CRONOGRAMA_FIDELIZACION.md`.

### Reglas importantes

- No usar floats.
- Precisión monetaria y de puntos.
- Ámbito por empresa.
- Puntos globales entre sucursales de una misma empresa.
- `branch_id` representa el origen.
- Reutilizar servicios centrales.
- Evitar duplicación de reglas.
- Integración progresiva con POS.

### Estado reciente

F01–F45 COMPLETADOS según el Cronograma Maestro (F28 adelantada). Todas las etapas completas.

Último hito confirmado: F45 — Respaldo GitHub.

Siguiente fase según cronograma: **ninguna; F01–F45 están completas**.

Antes de continuar una fase nueva, revisar:

- código actual
- tests de Loyalty
- último commit
- servicios disponibles
- `docs/CRONOGRAMA_FIDELIZACION.md` para determinar la fase real

---

## Panel Maestro — Cronograma M

Fuente oficial: `docs/Cronograma_M_Panel_Maestro_MVS_Commerce.xlsx`.

### M01 — Licenciamiento SaaS por tenant: COMPLETADO

- Platform Admin administra el estado y referencia comercial de la licencia, `branch_limit` y el contrato completo de módulos de cada empresa reutilizando `CompanyLicense`, `CompanyModule` y el Panel Maestro existentes.
- Las mutaciones se autorizan también en `CompanyLicenseService`; un tenant no puede modificar su contrato ni el ajeno mediante HTTP o llamadas de servicio.
- La escritura de módulos es transaccional y completa; las licencias conservan actor, snapshot e historial. Todas las consultas y escrituras permanecen aisladas por `company_id`.
- Los privilegios globales siguen separados de roles tenant. No se implementó M02 ni se adelantaron controles de fases posteriores.

Evidencia: `M01SaaSLicensingTest` 6/6, 26 aserciones, cubriendo `branch_limit`, módulos, autorización Platform Admin, bloqueo tenant y aislamiento. Regresión relacionada con `CompanyLicenseTest`, `CompanyModuleAccessTest` y `PlatformAdminTest`: 26/26 pruebas, 131 aserciones.

### M02 — Listado global de tenants: COMPLETADO

- El Panel Maestro permite buscar por empresa, identificación o propietario y filtrar por estado de licencia y módulo activo.
- Cada tarjeta resume estado/plan, propietario, sucursales usadas/límite, módulos y usuarios sin abrir ni consultar operación del tenant.
- Acceso exclusivo de Platform Admin y consultas aisladas mediante relaciones empresariales existentes.

Evidencia: `M02PlatformTenantListTest` + regresión `PlatformAdminTest` y `M01SaaSLicensingTest`.

### M03 — Alta comercial mínima: COMPLETADO

- Platform Admin crea únicamente referencia del tenant, propietario inactivo, plan, `branch_limit`, estado y módulos.
- No solicita ni crea identificación fiscal, dirección, sucursal, caja u otros datos operativos; éstos quedan para el onboarding del propietario.
- Reutiliza roles/permisos, licencia y módulos existentes dentro de una sola transacción.

Evidencia: `M03CommercialTenantOnboardingTest` + regresión M01/Platform Admin (19 pruebas, 113 aserciones).

### M04 — Invitación y activación del propietario: COMPLETADO

- El alta comercial envía una invitación personal mediante el broker de contraseñas: token almacenado como hash, expirable y de un uso.
- Al definir su clave se activa únicamente la cuenta tenant invitada; nunca obtiene `is_platform_admin` y el Panel Maestro continúa prohibido.
- Tokens inválidos no activan la cuenta.

Evidencia: `M04TenantOwnerInvitationTest` + regresión M03/onboarding (9 pruebas, 62 aserciones).

### M05 — Onboarding por el tenant: COMPLETADO

- El propietario activado de un tenant comercial sin sucursal es dirigido obligatoriamente al formulario existente de empresa/primera sucursal.
- Completa datos legales y operativos sobre el mismo tenant; no crea una segunda empresa ni altera plan, límite o módulos.
- Se reutilizan aprovisionadores de métodos de pago, caja y denominaciones al finalizar.

Evidencia: `M05TenantOnboardingTest` + regresión M04/onboarding/aprovisionamiento (14 pruebas, 78 aserciones).

### M06 — Límite de sucursales: COMPLETADO

- Se auditó y reutilizó `CompanyLicenseService::assertCapacity()` en `BranchController`; el conteo se realiza por empresa.
- Con límite 2 se permite la segunda sucursal y se bloquea la tercera; al aumentar a 3 desde Panel Maestro, el tenant puede crearla.
- Reducir el límite no elimina ni desactiva sucursales existentes; solamente bloquea nuevas altas mientras el uso alcance el contrato.

Evidencia: `CustomerOnboardingAndBranchesTest` + regresión de licencia/Platform Admin (16 pruebas, 85 aserciones). **M07 siguiente.**

---

## Puesta en Producción

### P00 — Login profesional MVS Commerce: COMPLETADO

- Login, recuperación y restablecimiento de contraseña reutilizan un solo shell visual con marca MVS Commerce y el logotipo existente.
- Diseño mobile-first: una columna a 360 px, espaciado progresivo a 768 px y composición de dos paneles a 1280 px; controles táctiles de al menos 44 px.
- Se preservaron controladores, CSRF, autenticación de usuarios activos, sesión regenerada, recordarme, rate limiting, notificación de recuperación y reglas fuertes de contraseña.
- Errores y estados se presentan de forma accesible; los controles para mostrar contraseña mantienen estado ARIA.

Evidencia: `ProfessionalAuthExperienceTest` (5 tests, 32 aserciones), regresión `UserRoleSecurityTest` + `ResponsiveNavigationTest` (14 tests, 50 aserciones), build Vite, Pint focalizado y `git diff --check` correctos.

### P01 — Panel Maestro MVS / Superadmin: COMPLETADO

- Panel privado global en `/panel-maestro`, fuera del middleware de empresa activa y del shell operativo, protegido por `platform.admin`.
- La identidad `is_platform_admin` es global y distinta de roles/permisos empresariales; exige además que la cuenta permanezca activa.
- Dashboard de empresas con búsqueda y conteos de sucursales/usuarios; detalle administrativo con configuración básica, estados de empresa/sucursal/usuario y roles existentes.
- Las rutas anidadas verifican que sucursal y usuario pertenezcan a la empresa indicada; nunca consultan ventas, inventario ni otros datos operativos.
- `platform:admin correo` y `--revoke` administran el acceso inicial sin credenciales, correos o clientes hardcodeados.
- La sección de módulos no simula habilitaciones: remite correctamente a P02.

Evidencia: `PlatformAdminTest` (6 tests, 41 aserciones); regresión con `CompanyProvisioningTest` + `UserRoleSecurityTest` (20 tests, 87 aserciones en conjunto); build Vite, Pint focalizado y `git diff --check` correctos.

### P02 — Módulos por empresa: COMPLETADO

- `ModuleRegistry` define un catálogo extensible de Ventas/POS, Inventario, Compras, Clientes, Caja, Fidelización, Reportes, Agenda y Administración, sin referencias a BeautyOS ni clientes concretos.
- `company_modules` conserva el contrato por tenant. Empresas anteriores sin contrato explícito mantienen sus capacidades para evitar una desactivación accidental al desplegar.
- `User::hasPermission()` exige primero el módulo empresarial y después el permiso del rol; por ello el control alcanza Gates del sidebar, middleware de rutas y servicios/controladores existentes.
- El Panel Maestro permite guardar el contrato completo. Desactivar no borra roles/permisos, y reactivar restaura el acceso según esos permisos.
- Pruebas demuestran bloqueo de ruta POS, ocultamiento de navegación, separación módulo/permiso y aislamiento entre empresas.

Evidencia: `CompanyModuleAccessTest` (5 tests, 15 aserciones); regresión Panel Maestro, roles y navegación (27 tests, 110 aserciones); build Vite, Pint focalizado y `git diff --check` correctos.

### P03 — Onboarding nueva empresa + sucursales: COMPLETADO

- Asistente mobile-first de siete pasos desde el Panel Maestro: empresa, identidad/logo, sucursales, administrador, módulos, configuración y finalización.
- Reutiliza `CompanyProvisioner`, `PaymentMethodProvisioner`, `CompanyCashSettingsProvisioner` y `CashDenominationProvisioner`; no existe una ruta especial para MYM.
- Una transacción crea administrador, empresa, rol con permisos, múltiples sucursales, asignaciones, contrato de módulos y configuración. El administrador queda listo para entrar y operar según sus módulos.
- Valida identificación empresarial, correo del administrador y códigos de sucursal duplicados. Ante fallo revierte todos los registros y elimina el logo almacenado.
- UI una columna a 360 px, progresión a siete indicadores en 768/1280, campos de ancho completo y controles táctiles.

Evidencia: `CompanyOnboardingTest` (4 tests, 30 aserciones); regresión P00–P03 (34 tests, 164 aserciones); caja/métodos de pago aprovisionados (55 tests, 183 aserciones); build Vite, Pint focalizado y `git diff --check` correctos.

### P04 — Impresión POS y comprobantes: COMPLETADO

- Comprobante profesional MVS reutilizable en térmica 80 mm, térmica 58 mm y formato carta, con TOTAL destacado y adaptación mobile-first.
- Descarga PDF generada desde la misma vista, sin duplicar la composición ni incorporar datos ajenos al modelo real.
- Reimpresión conservada desde historial de ventas; el acceso valida empresa, sucursal activa, módulo Ventas y creador o permiso `ventas.ver`.
- Cada sucursal puede definir formato predeterminado y autoimpresión mediante el diálogo nativo del navegador; una migración aditiva conserva 80 mm como valor seguro para sucursales existentes.

Evidencia: `SaleReceiptProductionTest` (5 tests, 33 aserciones); regresión POS/ventas/caja (29 tests, 172 aserciones); configuración/Panel Maestro (11 tests, 74 aserciones); build Vite, Pint focalizado y `git diff --check` correctos.

### P05 — Correo de comprobantes: COMPLETADO

- Envío desde el comprobante y reenvío desde el detalle del historial con destinatario validado y correo del cliente como valor sugerido.
- `SaleReceiptMail` adjunta el PDF producido por `SaleReceiptService`; P04 y P05 comparten una sola composición de comprobante.
- Empresa, sucursal, módulo y autorización usan el mismo contrato de acceso de P04. Un fallo de transporte se informa sin modificar ni revertir la venta completada.
- No se configuraron credenciales reales ni se modificó el entorno.

Evidencia: `SaleReceiptMailTest` + `SaleReceiptProductionTest` (9 tests, 53 aserciones); regresión correo de caja/ventas (50 tests, 238 aserciones); build Vite, Pint focalizado y `git diff --check` correctos.

### P06 — Fidelización en todos los comprobantes: COMPLETADO

- `LoyaltySaleReceiptService` consulta exclusivamente los movimientos reales ligados a la venta y entrega snapshots de puntos ganados, utilizados, saldo anterior y saldo final.
- La sección se presenta solo cuando la venta tiene cliente y movimientos aplicables, en 80 mm, 58 mm, carta y PDF; Blade se limita a presentar el resultado del servicio.
- Reimpresión y PDF no mutan el kardex. Si existe reversión por anulación/devolución vinculada, el saldo final real se muestra con una advertencia de ajuste posterior.
- El correo P05 hereda automáticamente la misma información porque adjunta el PDF común de P04.

Evidencia: P04–P06 (12 tests, 82 aserciones); regresión real POS/Loyalty, canje, pagos mixtos, anulaciones y devoluciones (79 tests, 573 aserciones); build Vite, Pint focalizado y `git diff --check` correctos.

### P07A — Portal de Clientes — experiencia del cliente: COMPLETADO

- El portal sigue perteneciendo a `(company_id, customer_id)`: un solo saldo y acceso por empresa, con movimientos/compras de todas sus sucursales y origen visible.
- El enlace seguro F33/F34 permite activar usuario/contraseña. Se añadió login independiente del staff, cierre de sesión y recuperación con token aleatorio almacenado solo como hash, expirable y de un uso.
- Presenta saldo/valor, totales ganados y utilizados, mínimo/faltante, fecha y días de vencimiento calculados desde la política real, premios y cuánto falta, movimientos, compras, comprobantes PDF/correo y perfil/preferencias.
- Publicaciones vigentes, productos en oferta, CTA empresariales y “Para ti” V1 reutilizan catálogo/categorías y ventas reales; no se incorporó IA, carrito ni enlaces hardcodeados.
- P04/P05 se reutilizan para PDF y reenvío; el cliente solo puede acceder a ventas propias dentro de su empresa.

Evidencia: `LoyaltyCustomerPortalProductionTest` + portal/acceso/QR/P05 (29 tests, 228 aserciones); build Vite, Pint focalizado y `git diff --check` correctos.

### P07B — Gestión de Portal de Clientes: COMPLETADO

- Una sola entrada `Fidelización → Portal de Clientes`; los accesos y promociones anteriores permanecen reutilizables desde la interfaz, pero ya no ocupan entradas separadas del sidebar.
- Dashboard/resumen, publicaciones, productos destacados, enlaces/botones, configuración y vista previa se organizan en una interfaz mobile-first con targets táctiles y grids 360/768/1280.
- Publicaciones permiten crear, editar, eliminar, activar, programar vigencia, ordenar y destacar; admiten producto real opcional (nombre/imagen/precio/oferta se leen del catálogo) o mensaje/imagen propia.
- La opción empresarial “Mostrar ofertas activas automáticamente” controla la consulta real del catálogo. Los CTA aceptan web, compra, catálogo/WhatsApp u otro HTTPS válido sin destinos hardcodeados.
- Permisos separados: `fidelidad.portal.ver`, `fidelidad.portal.configurar`, `fidelidad.portal.contenido` y `fidelidad.portal.enlaces`. Un usuario con contenido no obtiene configuración ni enlaces; no se creó un módulo Vendedores.

Evidencia: `LoyaltyPortalManagementTest` + regresión portal/acceso/QR/promociones/roles/navegación (46 tests, 320 aserciones); build Vite, Pint focalizado y `git diff --check` correctos.

### P11 — Centro de Etiquetas: COMPLETADO

- Una sola subentrada bajo Productos; no se añadió otro módulo superior al sidebar.
- Reutiliza `Product`, `ProductBarcode`, categorías, marcas y roles/permisos existentes, con aislamiento por empresa y configuración por sucursal.
- Cada producto define `prints_label`; el centro filtra por nombre, código, barcode principal/adicional, categoría, marca y Sí/No, y prepara lotes de uno o varios productos con cantidades validadas.
- Seis plantillas simples y tamaños comerciales 32×19, 40×25, 50×30 y 60×40 mm; vista previa imprimible desde navegador con SVG Code 128 local, sin driver ni dependencia nueva.
- Destinos Cajero/Administrador combinables por sucursal, preparados como lista extensible; permisos separados `productos.etiquetas.imprimir` y `productos.etiquetas.configurar`.
- Evidencia: `LabelCenterTest` 5/5 (32 aserciones); regresión Productos/roles 22/22 (110 aserciones); build Vite correcto y `git diff --check` correcto.

### P12 — Verificación Digital de Mercadería: COMPLETADO

- Se añadió una capa transaccional de verificación ligada a la compra existente; no se modificaron `PurchaseProcessor`, importadores ni contabilización de inventario.
- Asignación limitada a usuarios activos de la misma empresa y sucursal con `compras.recepcion.verificar`; permisos separados para asignar, verificar y resolver/cerrar.
- Tarea persistente con badge en el encabezado y estados Pendiente, En revisión, Conforme, Con diferencias y Cerrada; persiste entre sesiones hasta el cierre.
- Revisión mobile-first por línea con esperado, recibido, faltante, sobrante, confirmación y observación. Guarda auditoría de registro, asignación, verificación, resolución y fechas.
- Las diferencias quedan registradas y no alteran `branch_product`, movimientos ni lotes. El cierre con diferencias exige nota de resolución.
- “Preparar etiquetas” reutiliza P11 solo tras conformidad/cierre, usa cantidades recibidas, configuración de sucursal y productos con `prints_label = true`; nunca imprime automáticamente.
- Evidencia: `PurchaseVerificationTest` 8/8 (48 aserciones); P11+Compras/Inventario relacionada 42/42 (233 aserciones); build Vite correcto y `git diff --check` correcto.

### P08 — Dominio, servidor provisional y backups: TÉCNICAMENTE PREPARADO / PENDIENTE DE ACTIVACIÓN

- Se separó explícitamente Desarrollo de una futura instalación de Producción con checkout, `.env`, base y datos independientes; no se copiaron secretos ni datos reales.
- `docs/produccion/env.production.example` fija los mínimos seguros versionables: `production`, debug desactivado, HTTPS, cookies seguras, logs diarios y SQLite productivo en ruta absoluta externa al checkout.
- Scripts PowerShell fail-safe cubren preflight, backup consistente de SQLite + uploads con hashes/retención, restore aislado sin sobrescritura y despliegue fast-forward con backup, mantenimiento, dependencias, migraciones no destructivas, build, caches y reinicio de cola.
- `docs/produccion/P08_OPERACION_PRODUCCION.md` documenta PC Windows provisional, servicios automáticos, recuperación tras apagón/reinicio, acceso de Liberia, TLS/DNS, seguridad, despliegue y migración posterior a VPS.
- SQLite queda permitido solo como alternativa provisional de baja concurrencia y una instancia. La elección/instalación de PostgreSQL o MySQL para VPS requiere decisión humana y ensayo de conciliación; no se cambió motor.
- Evidencia: 107 migraciones correctas en SQLite `:memory:`; backup/restore sobre copia segura con `integrity_check`, hashes y rechazo de destino existente; login 5/5 (32 aserciones), P12 8/8 dentro de la corrida focalizada, rutas/scheduler/storage correctos y build Vite correcto.
- Activación pendiente: dominio/DNS/TLS, servidor web/administrador de procesos, router/firewall o túnel, cuenta/tareas Windows, UPS/energía, SMTP, almacenamiento externo, decisión de BD y validación real desde San Ramón y Liberia.
- Discrepancia conservada: el Excel maestro v5 todavía marca fases P como pendientes (incluidas P11/P12), mientras este cronograma Markdown, Git, código y tests prueban P00–P07B/P11/P12 completadas. No se sobrescribió el Excel.

Siguiente fase del cronograma: **P09 — Primera empresa real: MYM Beauty Center**, no iniciada. P08 debe completar su activación humana antes de declarar acceso productivo estable.

### P08S — Seguridad + PostgreSQL + capacidad: VALIDADO ESTÁTICAMENTE / POSTGRESQL REAL PENDIENTE

- Auditoría completa de migraciones, SQL crudo, booleanos, fechas, JSON, constraints, transacciones, rutas públicas, autenticación, Portal, comprobantes, storage y aislamiento.
- Corregidas tres comparaciones booleanas SQL incompatibles con PostgreSQL; consultas restantes usan Query Builder o expresiones comunes (`COUNT`, `SUM`, `CASE`, `DATE`, `LOWER`).
- Headers globales (`nosniff`, `DENY`, referrer, permissions y HSTS productivo HTTPS), throttles staff/Portal, contraseña fuerte y regeneración de sesión al usar enlace seguro.
- Índices nuevos para historial de ventas, movimientos del Portal y ofertas activas.
- Herramientas PostgreSQL fail-safe: plantilla, migración sobre base `_test` vacía, `pg_dump`, validación, restore aislado `_restore_test` y uploads con hashes. No se instaló software ni se tocaron credenciales.
- Capacidad local reproducible: 3.001 clientes, 100 ventas/movimientos, cuatro consultas, 2,37–8,22 ms en SQLite `:memory:`. k6 preparado para Portal/POS solo en staging/test. No se inventa capacidad máxima.
- Evidencia verde P08S: 58 pruebas, 479 aserciones; migraciones SQLite completas; build Vite y `git diff --check` correctos.
- Suite global con 512 MB: 820 pruebas, 810 pasan, 4.745 aserciones y 10 fallos históricos reproducibles: siete ya documentados (Example/POS/Cotización) y tres contratos desactualizados de P03/P07B (`CompanyOnboardingTest`, `LoyaltyAdminAuthorizationTest`). Ninguno toca archivos P08S.
- Pendiente externa: instalar/configurar PostgreSQL y `pdo_pgsql` en staging autorizado; ejecutar migraciones/regresión, backup/restore real y k6 con telemetría.

P08S queda verde dentro del alcance permitido. Siguiente exacta: **P08L — Licenciamiento SaaS por empresa**. P09 no iniciada.

### P08L — Licenciamiento SaaS por empresa: COMPLETADO

- Licencia única por empresa con seis estados, plan, fechas, límites, notas e historial con actor/snapshot.
- Actualización diaria; bloqueo sin borrar datos y reactivación al renovar.
- Panel Maestro exclusivo, aislamiento, vista responsive y módulos separados en `company_modules`.
- Límites centralizados en altas de usuarios y sucursales. Evidencia: 10 pruebas, 60 aserciones.
- Regresión cercana: 23/23 pruebas, 102 aserciones. Suite global: 824 pruebas, 814 pasan, 4.764 aserciones y los mismos 10 fallos históricos documentados en P08S; no aparecieron fallos nuevos.
- **P09 NO INICIADA.** Pausa hasta autorización explícita; PostgreSQL/staging P08S sigue pendiente.

### P08C — Apertura de caja obligatoria antes de POS: COMPLETADO

- El dashboard posterior al login revalida a usuarios con `pos.acceder`; sin sesión aplicable los dirige a Apertura de Caja o a una pantalla clara si carecen de `caja.abrir`.
- `/pos` exige caja abierta y `pos.checkout` conserva la validación transaccional bajo lock de `CashSessionResolver`; no se puede crear una venta sin sesión válida.
- El contrato existente valida empresa, sucursal, usuario según modo individual/compartido y caja registradora activa. Cerrar caja o cambiar sucursal obliga a resolver de nuevo.
- No existe bypass por administrador. La UX aclara que caja no equivale a jornada laboral, dejando el límite futuro con RRHH sin integrarlo.
- Evidencia focal Caja/POS: 155 pruebas, 871 aserciones; fixtures afectados: 26 pruebas, 140 aserciones. Suite global: 829 pruebas, 819 pasan, 4.779 aserciones y exactamente los mismos 10 fallos históricos documentados en P08S; P08C no agrega fallos.

**P09 NO INICIADA.** Siguiente paso exacto del cronograma: activación P08 en cloud (infraestructura externa); requiere autorización y entorno correspondiente.

### Portal de Clientes — P01–P20: COMPLETADO

Fuente oficial: `docs/CRONOGRAMA_PRODUCCION.md` (P01–P50) y referencia visual `docs/Cronograma_Unico_Portal_Correcciones_MVS_Commerce_28-08-2026.xlsx`. Reemplaza cronogramas anteriores; P09A, P09B, P09C y P09D conservan esos IDs exactos. P31–P40 quedaron completados adelantadamente por autorización expresa y conservan sus IDs/posición posterior a P25–P30. Fidelización pendiente solo P41–P48 si sigue pendiente. Reconciliación documental aprobada: **P21 – Separación Platform Admin / Tenant Admin, COMPLETADO** y **P22 – Onboarding empresa + primera sucursal + primer administrador, COMPLETADO** en `a60425f` (`a60425f684e11fd0629a42ac90fe6f25e5d31a35`); **P23 – Auditoría de transferencias existentes, COMPLETADO** y **P24 – pruebas/decisión de transferencias, COMPLETADO**. **P06 COMPLETADO en 75838ae**, **P07 COMPLETADO en 53c92db**, **P08 COMPLETADO en 2023148**, **P09 COMPLETADO en a4ee549**, **P09A COMPLETADO en 5a0248b**, **P09B COMPLETADO en 0a1bf05**, **P09C COMPLETADO en 46d07ec**, **P09D COMPLETADO** (ver detalle abajo). **P09 ajuste visual QR compacto: commit `58aba11`**.

**Próximos bloques en orden (según Excel único):**
- **P10 — COMPLETADO (`b383aad`)** — Patrón UI reutilizable `x-tabs`, mobile-first, scroll horizontal controlado y targets ≥44px.
- **P11 — COMPLETADO** — Portal organizado en siete pestañas según permisos; un panel visible, teclado y QR 160–200px con impresión independiente. Evidencia: `LoyaltyPortalCentralTest` 5/5, 40 aserciones; build Vite.
- **P12 — COMPLETADO** — Detalle de Cliente agrupado en Información, Identificación/seguridad y Contactos/direcciones; Configuración agrupada en Fidelización, WhatsApp y Plantillas según permiso. La lista simple de clientes no se forzó a tabs. Evidencia: 23 tests, 167 aserciones; build Vite.
- **P13 — COMPLETADO** — Auditoría transversal: patrón aplicado al detalle complejo de Roles (Resumen, Usuarios, Permisos; acciones ≥44px). Se excluyeron POS, flujos transaccionales de Compras/Ventas/Caja, formularios únicos y detalles simples de Empresa/Proveedor para no ocultar contexto ni forzar tabs. Evidencia: `UserRoleSecurityTest` + `ResponsiveNavigationTest`, 15 tests, 59 aserciones; build Vite.
- **P14 — COMPLETADO** — trabajo parcial retomado y cerrado: configuración habilitar/deshabilitar única por empresa, control protegido por `fidelidad.configuracion`, concesión base de 10 puntos una sola vez por cliente, reutilización de `LoyaltyAccountService` con movimiento `new_customer`, aislamiento explícito y claim enlazado al movimiento de Kardex. `LoyaltyRegistrationIncentiveP14Test` 8/8; regresión Portal/P14 78 pruebas, 500 aserciones; Pint focalizado y `git diff --check` correctos.
- **P15 — COMPLETADO** — configuración única por empresa ampliada con tipo `points`, `percentage` o `fixed` y valor `DECIMAL(19,4)`; validación estricta en request y servicio (valor >0, máximo cuatro decimales, porcentaje ≤100 y capacidad decimal). Puntos reutilizan `LoyaltyAccountService`/`new_customer`; porcentaje y fijo quedan concedidos en el claim, sin aplicar una venta antes de definir P16. Se conserva una sola concesión y el snapshot no cambia si luego se reconfigura. Evidencia: `LoyaltyRegistrationIncentiveP15Test` 13/13, 52 aserciones; regresión P14/Portal/P15 91 tests, 552 aserciones; Pint y `git diff --check` correctos.
- **P16 — COMPLETADO** — compra mínima con precisión decimal; concesión al registro o después de la primera compra válida; uso en primera compra o posteriores; excepción consumible al mínimo general de canje para incentivos de puntos; vencimiento calculado en la zona horaria de la empresa y snapshot inmutable por claim. Integrado con cierre POS y canje existente. Evidencia: `LoyaltyRegistrationIncentiveP16Test` 7/7, 28 aserciones; regresión P14–P16/Portal/POS/canjes 129 tests, 790 aserciones; build Vite, Pint focalizado y `git diff --check` correctos.
- **P17 — COMPLETADO** — sucursales participantes con `null` como todas y selección validada por empresa; productos en oferta permitidos/bloqueados; descuento máximo `DECIMAL(19,4)`; stacking/combinabilidad configurable. Las reglas se congelan en el claim y se revalidan antes del consumo en el motor existente. Evidencia: `LoyaltyRegistrationIncentiveP17Test` 6/6, 16 aserciones; regresión P14–P17/Portal/POS/canjes 162 tests, 972 aserciones; build Vite, Pint focalizado y `git diff --check` correctos.
- **P18 — COMPLETADO** — constraint único e idempotencia existente preservados; requisitos independientes de teléfono y correo verificado configurables por empresa, evaluados antes de conceder y guardados en el snapshot del claim. Evidencia: `LoyaltyRegistrationIncentiveP18Test` 5/5, 16 aserciones; regresión P14–P18/Portal/POS/canje 80 tests, 395 aserciones; Pint focalizado y `git diff --check` correctos.
- **P19 — COMPLETADO** — el claim existente enlaza la regla y registra cliente, beneficio, compra calificadora/consumo, sucursal, configurador y `awarded_at`; el consumo completa compra/sucursal sin alterar la fecha original. Evidencia: `LoyaltyRegistrationIncentiveP19Test` 3/3, 15 aserciones; regresión P14–P19/Portal/POS/canje 134 tests, 785 aserciones; Pint focalizado y `git diff --check` correctos.
- **P20 — COMPLETADO** — nombre/logo y colores primario/acento configurables por empresa aplicados al acceso, registro, portal y tarjeta QR imprimible; conserva “Hecho con MVS Commerce”. Evidencia: `LoyaltyPortalBrandingP20Test` 4/4, 21 aserciones; regresión P14–P20/Portal/POS/canjes 167 tests, 1.040 aserciones; build Vite, Pint focalizado y `git diff --check` correctos.
- **P21 — COMPLETADO (`a60425f`)** — Separación Platform Admin / Tenant Admin.
- **P22 — COMPLETADO (`a60425f`)** — Onboarding empresa + primera sucursal + primer administrador.
- **P23 — COMPLETADO** — auditoría de la implementación existente de transferencias; preservada y centralizada sin reconstruir.
- **P24 — COMPLETADO** — origen/destino, stock, Kardex y permisos probados; transferencia instantánea confirmada. Evidencia: `InventoryTransferP24Test` 7/7, 54 aserciones.

**Regla producción:** desarrollo → validación local del usuario → APROBADO PARA PRODUCCIÓN → despliegue controlado. Los agentes no despliegan producción automáticamente.

- **P01 — Registrarme:** Enlace “Registrarme / Crear mi cuenta” en `loyalty.portal.login` hacia `portal-clientes/{company}/registro` (`resources/views/loyalty/portal/login.blade.php:14`, `resources/views/loyalty/portal/register.blade.php`, `routes/web.php:139`).
- **P02 — Autorregistro:** `LoyaltyPortalSessionController::register` dentro de la empresa de la URL; crea `Customer` `is_active=true` + `LoyaltyPortalCredential`, `throttle:10,1`, `RateLimiter`, disponible en `clientes`, `pos.customers.search` y Fidelización; sin factura/incentivo/QR individual.
- **P03 — Deduplicación y bloqueo por conflicto:** Busca por `identification` exacto / `phone` normalizado (`PhoneNumberService::normalizePhone`, `phone/mobile`) / `email` lower dentro de la empresa; si coincide, enlaza al cliente existente. **Si dos identificadores apuntan a clientes distintos (ej. identificación→A y teléfono→B, o correo→C distinto), bloquea** con `identification: Los datos proporcionados coinciden con clientes distintos...`, sin fusionar, sin crear `Customer` ni `LoyaltyPortalCredential`. Aislamiento multiempresa probado.
- **P04 — Visibilidad POS (evidencia actual):** Cliente nuevo activo y encontrado por `PosController::searchCustomers` (`pos.customers.search` LIKE `name/identification/phone/mobile/email`) con `pos.acceder` y sesión `active_company_id/active_branch_id`.
- **P05 — Cuenta fidelización al autorregistrarse: COMPLETADO.** `LoyaltyPortalSessionController::register` crea/activa `LoyaltyAccount` vía `LoyaltyAccountService::getOrCreateAccount` en la misma transacción; sin bono.
- **P06 — Crear acceso Portal desde Clientes y POS rápido: COMPLETADO (75838ae).** `CustomerController::createPortalAccessForCustomer` (`clientes.store`) y `PosController::createPortalAccessForQuickCustomer` (`pos.customers.quick-store`) crean `LoyaltyPortalCredential` aislado por `company_id` con validación `StoreCustomerRequest`/`QuickStoreCustomerRequest` `create_portal_access`, usuario derivado y contraseña temporal única mostrada una vez; no duplica credencial activa; vistas `clientes/_form.blade.php` y `pos/index.blade.php` con checkbox.
- **P07 — Contraseña temporal única con cambio obligatorio: COMPLETADO.** Migración `2026_08_29_000001_add_must_change_password_to_loyalty_portal_credentials` + `LoyaltyPortalCredential` casts/fillable, `CustomerController`/`PosController` `must_change_password=true`, `LoyaltyPortalSessionController::login`/`home`/`forceChangeForm`/`forceChange` con `PasswordRule::min(8)->letters()->mixedCase()->numbers()`, rutas `loyalty.customer.password.force` (GET/POST), vista `loyalty/portal/force-change.blade.php`. Flujo: login con temporal → redirect a `cambiar-clave` → bloqueo de `home` → cambio con confirmación → `must_change_password=false` → login normal.
- **P08 — Entrega de acceso al cajero: COMPLETADO.** `LoyaltyPortalDeliveryService` centraliza `portal_url` (`route('loyalty.customer.login', $company)`), `whatsapp_phone`/`whatsapp_url` (`PhoneNumberService::forWhatsApp` con `phone_country_code`/`default_phone_country_code` normalizado), `copy_text`/`message` (URL+usuario+contraseña temporal + aviso cambio). `CustomerController::store` merge entrega a flash `portal_access` (contraseña solo en memoria, hasheada en BD, flash una vez), `PosController::storeQuickCustomer` merge entrega a JSON `portal_access`. Vistas: `clientes/index.blade.php` `portal-delivery` responsive (URL link, usuario, contraseña ámbar, Copiar vía `navigator.clipboard`, WhatsApp `https://wa.me/...` prellenado sin auto-envío), `pos/index.blade.php` `quickCustomer.delivery` modal responsive con Copiar/WhatsApp/Continuar. QR no implementado (depende P09A/P09B).
- **P09 — Pantalla central Portal URL general: COMPLETADO.** `LoyaltyPortalManagementController::index` genera `portalUrl`/`portalQr` por empresa (QR local chillerlan, sin API externa), vista `loyalty/portal-management/index.blade.php` `acceso-general` con URL general, Copiar URL, Vista previa (target _blank) y QR imprimible.
- **P09A — Código público único cliente: COMPLETADO.** Migración `2026_08_29_000002` (`public_code` 12 + unique `company_id+public_code`), `Customer` booted + `CustomerPublicCodeService` (CSPRNG 8 chars, no leak, `ensure()` para legacy, `isSensitiveLeak`).
- **P09B — QR + Code128 individual: COMPLETADO.** `CustomerPublicCodeService::qrSvg` + `barcodeSvg` locales (chillerlan + picqer), `clientes/show` `Identificación pública` con código+QR+Code128+Copia/Imprimir.
- **P09C — Escaneo QR/Code128 en POS: COMPLETADO.** `PosController::searchCustomers` expone `public_code` + like, `pos/index` botón escáner cliente + `onMvsScan` (public_code exact → `selectCustomer`).
- **P09D — PIN/QR temporal de un solo uso: COMPLETADO.** `customer_one_time_tokens` (`token_hash` SHA256, 5min, `used_at`), `CustomerOneTimeTokenService` genera PIN 6 dígitos + QR local y verifica single-use/expiración/aislamiento, `clientes/show` genera/muestra/verifica.
- **Evidencia P01–P09D:** `LoyaltyPortalSelfRegistrationTest` **11/11, 52 aserciones** + `LoyaltyPortalClientAccessTest` **11/11, 55 aserciones** + `LoyaltyPortalDeliveryTest` **7/7, 51 aserciones** + `LoyaltyPortalCentralTest` **4/4, 17 aserciones** + `CustomerPublicCodeTest` **5/5, 23 aserciones** + `CustomerQrBarcodeTest` **4/4, 16 aserciones** + `CustomerPosScanTest` **3/3, 13 aserciones** + `CustomerOneTimeTokenTest` **4/4, 17 aserciones, 0 fallos**. `LoyaltyCustomerPortal` 13/13, 89 aserciones.
- **P14–P24 — COMPLETADOS.** Portal P01–P20 cerrado; P21/P22 ya estaban completados en `a60425f`; P23/P24 cierran auditoría, pruebas y decisión de transferencias. Siguiente: **P25 — Reparar/terminar sidebar responsive**.

---

## Centro de Datos

Estado: D00, D02, D03, D09 y D10 COMPLETADOS; D01 EN CURSO EN PARALELO.

### D02 — Centro de Datos base: COMPLETADO

- shell único en `/centro-de-datos`, con entradas claras a Importar, Exportar y Reportes;
- `DataCenterController` no consulta ni muta datos de negocio: solo resuelve la empresa activa, aplica autorización por permisos existentes y renderiza las cuatro vistas del shell;
- Importar reutiliza destinos existentes sin controladores paralelos: Compras Excel (`compras.index`), Compras XML (`compras.import.xml.create`) e Inventario (`importaciones.inventario`);
- `/importar-datos` conserva compatibilidad mediante redirección al área Importar, eliminando el destino roto sin alterar `DataImportController`;
- Exportar y Reportes muestran únicamente su espacio futuro (D09/D10), sin crear archivos, queries ni reportes ficticios;
- una sola entrada "Centro de Datos" en la fuente única del sidebar, visible cuando el usuario posee alguna capacidad aplicable;
- permisos reutilizados: `compras.crear`, `compras.ver`, `inventario.ver`, `reportes.exportar` y `reportes.ver`; acceso 403 cuando no existe capacidad;
- UI mobile-first con una columna a 360 px, navegación/tarjetas progresivas en `sm/md/lg`, targets de 44 px y ancho máximo seguro para 1280 px;
- no se tocaron importadores, `PurchaseProcessor`, plantillas, datos MYM, Excel maestro ni BeautyOS.

Evidencia: `DataCenterShellTest` (6 tests, 47 aserciones); regresión `ResponsiveNavigationTest` + Compras/Órdenes/Proveedor (39 tests, 210 aserciones); `npm run build`, Pint focalizado y `git diff --check` correctos.

### D03 — Caracterización Compras + blindaje Inventario: COMPLETADO

- Compras quedó caracterizado sin duplicar sus lectores, manager ni `PurchaseProcessor`: Excel, XML, plantilla, middleware, review y confirmación tienen pruebas directas; el único defecto funcional corregido fue el middleware faltante del POST XML.
- Inventario conserva el flujo y la plantilla existentes, pero su lógica segura vive en `InventoryImportService`: preview sin escrituras, stock real, resolución por `products` y `product_barcodes`, catálogo por empresa y validación completa de filas.
- La confirmación requiere `inventario.ajustar`, respeta empresa/sucursal y `inventario.ver_otras_sucursales`, es transaccional y publica stock/movimiento mediante `InventoryPostingService`, rechazando stock negativo incluso ante concurrencia.
- Productos nuevos ya no usan IDs fijos; resuelven categoría/unidad/marca existentes y registran `ProductBarcode` sin aceptar conflictos ni duplicados.
- No se modificaron plantillas, `PurchaseProcessor`, Excel maestro, datos MYM ni BeautyOS.

Evidencia: `PurchaseImportCharacterizationTest` + `InventoryImportHardeningTest` (13 tests, 68 aserciones); `DataCenterShellTest` y regresión Compras/Inventario relacionada (54 tests, 300 aserciones); build Vite, Pint focalizado y `git diff --check` correctos.

D01 continúa en paralelo. D04–D08 siguen pendientes de contratos/plantillas MYM.

### D09 — Exportadores esenciales: COMPLETADO

- Centro de Datos exporta productos, clientes, proveedores, inventario, CxC, CxP y fidelización en XLSX o CSV UTF-8 mediante una sola capa reutilizable.
- Encabezados estables, aislamiento por empresa y filtros de sucursal asignada; inventario de otra sucursal requiere el permiso específico existente.
- Autorización en dos niveles: `reportes.exportar` más lectura del dominio. La UI oculta conjuntos no autorizados y mantiene layout 360/768/1280.
- Los exportadores son solo lectura y no fijan ni cambian contratos de importación MYM.

Evidencia: `DataExportTest` (7 tests, 40 aserciones), `DataCenterShellTest` (6 tests, 47 aserciones) y regresión de dominios (31 tests, 177 aserciones). Build Vite, Pint focalizado y `git diff --check` correctos.

Fallo preexistente ajeno: `PosAccessAndSearchTest` mantiene 3 fallos por expectativas antiguas de payload/markup POS; D09 no modifica archivos POS y las otras 29 pruebas del archivo pasan. No se corrigió esa deuda.

### D10 — Centro de Reportes esenciales: COMPLETADO

- Reportes permanece dentro de la entrada única Centro de Datos; no se agregó otra opción al sidebar.
- Seis categorías funcionales consultan datos reales: Ventas, Inventario, Caja/Finanzas, Compras/Proveedores, Clientes y Fidelización.
- Los filtros de sucursal, fechas, producto, cliente, proveedor y vendedor validan pertenencia a la empresa activa y acceso del usuario. Las consultas mantienen aislamiento por empresa/sucursal.
- `reportes.ver` se combina con el permiso del dominio; CxC/CxP requieren además su lectura específica. Los botones autorizados reutilizan directamente las rutas y conjuntos D09.
- UI mobile-first 360/768/1280 con tarjetas de categoría/indicadores, filtros de 44 px y tablas con scroll interno.
- No se modificaron importadores, plantillas MYM, Excel maestro ni BeautyOS.

Evidencia: `EssentialReportTest` (7 tests, 46 aserciones); integración `DataExportTest` + `DataCenterShellTest` (20 tests, 133 aserciones); regresión de Ventas/Inventario/Caja/Compras/CxP/Fidelización (76 tests, 383 aserciones). Build Vite, Pint focalizado y `git diff --check` correctos.

Siguiente fase exacta: **D11–D12 — Históricos opcionales**. No iniciada por instrucción expresa y porque requiere confirmar necesidad/contratos de migración.

---

## Comercio electrónico

Estado: PLANIFICADO

Objetivo futuro:

Conectar MVS Commerce con tiendas online.

Debe permitir sincronizar:

- productos
- precios
- inventario
- imágenes
- descripciones
- especificaciones
- ventas

No implementar sin diseño previo.

---

## Inteligencia artificial

Estado: INFRAESTRUCTURA DE TRABAJO EN PREPARACIÓN

Objetivo:

Permitir trabajar el proyecto con diferentes agentes y modelos sin depender de una sola conversación.

Herramientas previstas:

- Codex / ChatGPT
- OpenCode
- Qwen
- GLM
- otros modelos compatibles

La memoria compartida del proyecto vive en:

- `AGENTS.md`
- `docs/ARQUITECTURA.md`
- `docs/PROGRESO.md`
- `docs/DECISIONES.md`
- `docs/NEGOCIO.md`
- `docs/INTEGRACIONES.md`

---

## Recursos Humanos / Planilla

Estado: DESARROLLO PARALELO FUERA DE ESTE REPOSITORIO

Existe trabajo realizado por otro colaborador en un proyecto separado.

Incluye trabajo relacionado con:

- empleados
- incidencias
- incapacidades
- planilla
- aguinaldo
- reglas laborales

No duplicar este módulo dentro de MVS Commerce sin revisar primero el proyecto paralelo.

---

## Contabilidad

Estado: DESARROLLO PARALELO / PLANIFICADO

Existe trabajo relacionado con contabilidad fuera del flujo principal de MVS Commerce.

Debe integrarse posteriormente mediante una arquitectura definida.

No recrear funcionalidad contable sin revisar primero ese trabajo.

---

## Prioridad actual del proyecto

Prioridades conocidas:

1. Continuar POS.
2. Completar Fidelización.
3. Mantener Caja estable.
4. Integrar correctamente módulos existentes.
5. Preparar OpenCode como agente alternativo.
6. Mantener documentación compartida entre agentes.
7. Evitar duplicar trabajo desarrollado en proyectos paralelos.
8. Preparar el camino para comercio electrónico e IA empresarial.

---

## Regla para agentes

Antes de comenzar una nueva fase:

1. Leer `AGENTS.md`.
2. Leer este archivo.
3. Revisar el módulo correspondiente.
4. Revisar `git status`.
5. Revisar pruebas existentes.
6. No asumir que el último número de fase conocido sigue vigente sin verificar el repositorio.

Cuando se complete una fase importante, actualizar este archivo.
