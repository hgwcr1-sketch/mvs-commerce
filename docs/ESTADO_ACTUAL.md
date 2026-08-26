# MVS Commerce — Estado actual

Documento corto de relevo entre agentes. Actualizar al terminar cada tarea importante.

> La información de este archivo es una fotografía. Antes de programar, comprobar el estado real del repositorio: `git status`, último commit y código del módulo.

---

## Estado actual de Fidelización

Fuente oficial del orden de fases: `docs/Cronograma_Maestro_Fidelizacion_MVS_Commerce_Actualizado_23-08-2026.xlsx`, reflejada en `docs/CRONOGRAMA_FIDELIZACION.md`.

**F01–F45: COMPLETADO** según el cronograma maestro (F28 de forma adelantada). Todas las etapas de Fidelización están completas, sujeto al detalle en `docs/PROGRESO.md`.

Último hito confirmado:

**F45 — Respaldo GitHub: COMPLETADO.**

- F43 (`3efe76f`) y F44 (`5decbca`) publicados en `origin/feature/pos` como puntos de recuperación exclusivos.
- Antes del registro final: HEAD local/remoto sincronizados, working tree limpio y `git diff --check` correcto.
- Suite final de Fidelización: 305 tests, 1942 aserciones, 0 fallos.
- El commit documental exclusivo de F45 completa el cronograma F01–F45; verificar push y árbol limpio en Git al tomar el relevo.

Hito anterior:

**F36 — Acumulación online: COMPLETADO.**

- Capa mínima sobre venta real confirmada (`accrueForSale`) reutilizando F08/F12/F13 y bonos F10/F11 sin duplicar lógica; misma cuenta central `(company_id, customer_id)`, sin cuentas web paralelas.
- Idempotencia determinista `online_sale:{canal}:{ref}:loyalty:earn`; origen online auditado en metadata del movimiento sin columnas nuevas. Sin cliente identificado no acredita. Sin inventario/tienda/API.
- Evidencia: `LoyaltyOnlineSaleTest` (11 tests); regresión al cierre: 276 tests, 1846 aserciones, 0 fallos.

Hitos anteriores:

**F35 — Promociones del portal: COMPLETADO.** Tabla `loyalty_promotions`, administración bajo permiso `fidelidad.promociones` y sección "Promociones vigentes" independiente de los multiplicadores F12. Evidencia: `LoyaltyPromotionTest` (6 tests).

**F33 — QR + F34 — Acceso por enlace seguro: COMPLETADOS.** Token solo como hash SHA-256, ruta pública con throttle, QR local SVG que nunca se persiste y muere automáticamente con su enlace. Evidencia: `LoyaltyPortalAccessTest` (7) + `LoyaltyPortalAccessQrTest` (5).

**F30–F32 — Portal del cliente, identidad visual y marca MVS Commerce: COMPLETADOS** (detalle en `docs/PROGRESO.md`).

Además:

- **F38 — Administrador: COMPLETADO** (etapa 12. Permisos).
- **F39 — Cajero: COMPLETADO** (etapa 12. Permisos).
- **F40 — Indicadores: COMPLETADO** (etapa 13. Dashboard).
- **F41 — Empresa / sucursal: COMPLETADO** (etapa 13. Dashboard).
- **F42 — Suite de pruebas: COMPLETADO** (etapa 14. Calidad).
- **F43 — UI / usabilidad: COMPLETADO** (etapa 14. Calidad).
- **F44 — Regresión: COMPLETADO** (etapa 14. Calidad).
- **F45 — Respaldo GitHub: COMPLETADO** (etapa 15. Cierre).
- No quedan fases pendientes en el Cronograma Maestro de Fidelización F01–F45.
- **F28 — Reversión de puntos por anulación: COMPLETADO de forma adelantada** durante la integración POS (`7be1f80`).

Evidencia histórica: `8392dd4` (canje de puntos) y `7be1f80` (integración de fidelización en POS). Auditoría posterior a F18: 152 tests Loyalty/POS-Loyalty con 0 fallos. Tras F22: regresión Loyalty en verde (134 tests) más POS-Loyalty (48 tests); vencimiento configurable: 7 tests, 62 aserciones. Tras F23: `LoyaltyExpirationTest` (13 tests, 78 aserciones); regresión Loyalty + POS-Loyalty (177 tests, 1160 aserciones) en verde. Tras F24-F25: `LoyaltyRuleCenterTest` (6) y `LoyaltyManualAdjustmentTest` (10). Tras F26-F27: `LoyaltyMultiBranchTest` (5 tests, 40 aserciones); regresión Loyalty + POS-Loyalty (198 tests, 1295 aserciones) en verde. Tras F29: `SaleReturnLoyaltyTest` (9 tests, 79 aserciones); regresión Devoluciones+F28+Loyalty+POS-Loyalty (228 tests, 1481 aserciones) en verde.

Las denominaciones F18A–F18F se usaron durante el desarrollo pero no están etiquetadas dentro del repositorio; no inventar correspondencia exacta de letras.

---

## Estado actual del Centro de Datos

Fuente de verdad: `docs/centro-datos/CENTRO_DATOS_CRONOGRAMA.md` y `docs/centro-datos/Cronograma_Maestro_Centro_de_Datos_MVS_Commerce_v2.xlsx`.

- **D00 — Auditoría existente: COMPLETADO.**
- **D01 — Contratos de plantillas MYM: EN CURSO EN PARALELO.** No bloqueó el shell y sigue siendo obligatorio antes de crear importadores nuevos dependientes de formatos reales.
- **D02 — Centro de Datos base: COMPLETADO.** Entrada única mobile-first con Inicio, Importar, Exportar y Reportes; permisos existentes por capacidad y una sola entrada en la navegación compartida.
- **D03 — Caracterización Compras + blindaje Inventario: COMPLETADO.** Compras Excel/XML quedó cubierta directamente sin reemplazar su lógica; POST XML protegido. Inventario ahora valida y previsualiza sin mutar, usa stock/barcodes reales, resuelve catálogo por empresa y confirma transaccionalmente mediante `InventoryPostingService` con `inventario.ajustar`.
- Exportar y Reportes son espacios base, sin exportadores ni reportes de negocio anticipados.
- Evidencia: `DataCenterShellTest` 6/6 (47 aserciones), regresión navegación/Compras 39/39 (210 aserciones), build Vite y `git diff --check` correctos.
- D04–D08 permanecen pendientes de contratos/plantillas MYM de D01. **SIGUIENTE EJECUTABLE AUTORIZADA: D09 — Exportadores esenciales.**

---

## Rama actual

`feature/pos`

## Estado del repositorio

R02 (POS móvil + escaneo) COMPLETADO y R03 (Productos/Inventario móvil + cámara) COMPLETADO. R03 añadió: `ProductController::search()` enriquecido (busca en `product_barcodes` secundarios, retorna `sale_price`, `cost`, `branch_stock`), `productos/index.blade.php` responsive mobile-first (tarjetas con código/precio/stock, cámara junto al buscador, listener `mvs-scan`), `inventario/index.blade.php` responsive mobile-first (tarjetas con stock/mín/máx y estado, cámara, listener `mvs-scan`), ambos incluyen `<x-scanner.mvs-scanner />`. Sin backend adicional (se reutilizó `productos.search`). Sin header/sidebar. Evidencia: `PosCameraScannerTest` 9/9, regresión POS/Loyalty: 180 tests / 1126 aserciones, mismos fallos preexistentes; `npm run build` correcto. Siguiente fase responsive: **R04**; siguiente fase funcional de Fidelización: **F39** (orden intacto). Verificar siempre con `git status` antes de trabajar.

## Objetivo actual

Continuar el desarrollo de las prioridades principales:

1. POS.
2. Fidelización.

Mantener Caja estable e integrar correctamente los módulos existentes.

## Último trabajo terminado

Según historial reciente de commits en esta rama:

- integración de fidelización en POS (`7be1f80`), incluida auditoría con 152 tests de Loyalty / POS-Loyalty sin fallos;
- canje de puntos de fidelización (`8392dd4`);
- pedidos internos (`Order`) y órdenes de compra con conversión a compras;
- integración de caja con POS;
- documentación: `INTEGRACIONES.md` completado, módulos nuevos registrados en arquitectura/progreso y este archivo creado.

## Trabajo en curso

- Centro de Datos: D00, D02 y D03 completados; D01 continúa en paralelo con plantillas MYM. D04–D08 permanecen bloqueados por contratos; D09 es la siguiente fase ejecutable autorizada.
- Fidelización: **cronograma F01–F45 completo**; no existe una fase siguiente dentro del maestro vigente.
- R01 — Navegación responsive: COMPLETADO (`9c03912`).
- R02 — POS móvil + escaneo: **COMPLETADO** (R02-A + R02-B escáner por cámara).
- R03 — Productos/Inventario móvil + cámara: **COMPLETADO** (responsive mobile-first, cámara integrada en ambas vistas, `productos.search` enriquecido). Pendiente commit junto con R02. Siguiente fase responsive: **R04**.
- POS: expansión activa (uno de los módulos principales).
- Configuración de OpenCode como agente alternativo para trabajar este repositorio.

## Próximo paso

Antes de programar cualquier tarea nueva:

1. leer `AGENTS.md`, `docs/PROGRESO.md` y este archivo;
2. verificar `git status`, rama y último commit;
3. inspeccionar el código real del módulo afectado;
4. confirmar con el usuario cuál es la tarea concreta si no está definida.

No asumir que el último estado conocido sigue vigente.

## Archivos o módulos relevantes

- POS: `PosController`, `PosSaleProcessor`, `Sale`, `SaleItem`, `SalePayment`.
- Fidelización: `app/Services/Loyalty/*`, `LoyaltyAccount`, `LoyaltyMovement`, `LoyaltyMovementLine`, `LoyaltyReward`, `LoyaltyRewardRedemption`, `LoyaltyPromotion`.
- Caja: `app/Services/Cash/*`, notificaciones por correo con reintentos.
- Pedidos/órdenes: `OrderService`, `PurchaseOrderPreparationService`, `PurchaseOrderConversionService`.
- Apartados: `LayawayService`. Devoluciones: `SaleReturnService`. Pagos a proveedores: `AccountsPayableService`.

## Pruebas importantes

Suite principal: `tests/Feature`.

- POS: `PosCheckoutTest`, `PosSuspendedSalesTest`, `PosCashSessionIntegrationTest`, `PosAccessAndSearchTest`.
- Navegación: `ResponsiveNavigationTest`, `LoyaltySettingsSidebarNavigationTest`.
- Fidelización: `tests/Feature/Loyalty*Test.php` (incluye `LoyaltyExpirationTest`, `LoyaltyExpirationSettingTest`, `LoyaltyCustomerPortalTest`, `LoyaltyPortalAccessTest`, `LoyaltyPortalAccessQrTest`, `LoyaltyPromotionTest`, `LoyaltyOnlineSaleTest`, `LoyaltyOnlineRedemptionTest`), `PosCheckoutLoyaltyPointsRequestTest`, `PosCheckoutLoyaltyRedemptionTest`, `PosLoyaltyInterfaceTest`, `PosLoyaltyMixedPaymentsTest`, `SaleVoidLoyaltyTest`, `LoyaltySettingsSidebarNavigationTest`. Premios, disponibilidad, canjes y vencimiento: `LoyaltyRewardTest`, `LoyaltyRewardAvailabilityTest`, `LoyaltyRewardRedemptionTest`.
- Caja: `Cash*Test.php`.
- Módulos recientes: `Order*Test.php`, `PurchaseOrderTest`, `PurchaseOrderConversionTest`, `LayawayV1Test`, `SaleReturnTest`, `SaleVoidTest`, `AccountsPayable*Test.php`.

Ejecutar pruebas específicas más regresión razonable antes de declarar terminada una tarea.

## Riesgos / advertencias

- No usar floats para dinero ni puntos; precisión decimal obligatoria.
- Respetar aislamiento por empresa (`company_id`) y sucursal cuando corresponda.
- Los puntos de fidelización son globales entre sucursales de la misma empresa; `branch_id` es origen, no saldo.
- Las rutas `facturas` y `reportes` existen pero sus controladores están vacíos y sin permisos; no asumir funcionalidad.
- Recursos Humanos/Planilla y Contabilidad se desarrollan fuera de este repositorio: integrar, no duplicar.
- Puede haber trabajo de otros agentes en curso; revisar Git antes de modificar o respaldar.

## Instrucción para el siguiente agente

Reconstruye el contexto desde el repositorio, nunca desde memoria conversacional:

1. lee `AGENTS.md` y `docs/`;
2. revisa `git status`, rama y últimos commits;
3. identifica el módulo y sus pruebas;
4. trabaja con cambios mínimos, ejecuta pruebas y deja el repo y la documentación en estado comprensible para el siguiente agente;
5. actualiza este archivo si tu tarea cambia rama, prioridades o deja trabajo a medias.
