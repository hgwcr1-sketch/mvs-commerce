# MVS Commerce — Estado actual

Documento corto de relevo entre agentes. Actualizar al terminar cada tarea importante.

> La información de este archivo es una fotografía. Antes de programar, comprobar el estado real del repositorio: `git status`, último commit y código del módulo.

---

## Estado actual de Fidelización

Fuente oficial del orden de fases: `docs/Cronograma_Maestro_Fidelizacion_MVS_Commerce_Actualizado_23-08-2026.xlsx`, reflejada en `docs/CRONOGRAMA_FIDELIZACION.md`.

**F01–F32: COMPLETADO** según el cronograma maestro, sujeto al detalle documentado en `docs/PROGRESO.md`.

Último hito confirmado:

**F31 — Identidad visual + F32 — Marca MVS Commerce: COMPLETADOS.**

- El portal muestra la identidad de la empresa con datos existentes: `trade_name` siempre y `logo` cuando existe (convención ya soportada `asset('storage/…')`, alt "Logo de {nombre}"); fallback elegante con la inicial del nombre comercial. Sin columnas nuevas ni temas por empresa; estilo único MVS Commerce.
- Pie discreto "Hecho con MVS Commerce" en `layouts/portal`; texto plano sin enlace (no existe URL oficial configurada en el proyecto).
- Evidencia: `LoyaltyCustomerPortalTest` ampliado a 9 tests, 66 aserciones (`test_portal_shows_own_brand_with_logo_or_elegant_fallback`, `test_portal_footer_shows_mvs_commerce_brand_discretely`); regresión Loyalty/POS-Loyalty/Devoluciones: 246 tests, 1624 aserciones, 0 fallos.

Hito anterior:

**F30 — Portal del cliente: COMPLETADO.**

- Vista web responsive/mobile-first (`layouts/portal` + `loyalty/portal/show`) con saldo actual, valor monetario equivalente, historial de movimientos, premios activos y promociones (multiplicadores vigentes).
- Datos ensamblados en `LoyaltyCustomerPortalService` (solo lectura): reutiliza `LoyaltyPointValueService` y los modelos existentes; sin lógica de cálculo nueva.
- Aislamiento estricto por `(company_id, customer_id)`: ruta `fidelidad/portal/{cliente}` bajo `auth`, `active.company` y `permission:fidelidad.ver`; el cliente se resuelve siempre dentro de la empresa activa.
- Sin QR, PIN, magic links ni identidad nueva de cliente: el mecanismo de acceso corresponde a F31–F35. No modifica reglas de acumulación/canje/expiración/anulación/devoluciones.
- Evidencia: `LoyaltyCustomerPortalTest` (7 tests, 51 aserciones); regresión Loyalty/POS-Loyalty/Devoluciones: 244 tests, 1609 aserciones, 0 fallos.

Además:

- **F33 — QR seguro: SIGUIENTE.** Es la única fase autorizada para iniciar.
- **F28 — Reversión de puntos por anulación: COMPLETADO de forma adelantada** durante la integración POS (`7be1f80`). El adelanto NO altera el orden del cronograma.

Evidencia histórica: `8392dd4` (canje de puntos) y `7be1f80` (integración de fidelización en POS). Auditoría posterior a F18: 152 tests Loyalty/POS-Loyalty con 0 fallos. Tras F22: regresión Loyalty en verde (134 tests) más POS-Loyalty (48 tests); vencimiento configurable: 7 tests, 62 aserciones. Tras F23: `LoyaltyExpirationTest` (13 tests, 78 aserciones); regresión Loyalty + POS-Loyalty (177 tests, 1160 aserciones) en verde. Tras F24-F25: `LoyaltyRuleCenterTest` (6) y `LoyaltyManualAdjustmentTest` (10). Tras F26-F27: `LoyaltyMultiBranchTest` (5 tests, 40 aserciones); regresión Loyalty + POS-Loyalty (198 tests, 1295 aserciones) en verde. Tras F29: `SaleReturnLoyaltyTest` (9 tests, 79 aserciones); regresión Devoluciones+F28+Loyalty+POS-Loyalty (228 tests, 1481 aserciones) en verde.

Las denominaciones F18A–F18F se usaron durante el desarrollo pero no están etiquetadas dentro del repositorio; no inventar correspondencia exacta de letras.

---

## Rama actual

`feature/pos`

## Estado del repositorio

Limpio antes de la última actualización documental (solo cambios en `AGENTS.md` y `docs/`).

Verificar siempre con `git status` antes de trabajar.

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

- Fidelización: fases confirmadas hasta F32 (portal con identidad visual y marca MVS); etapa 10 (portal) en curso — pendientes F33–F35. Siguiente fase según cronograma: F33 — QR seguro.
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
- Fidelización: `app/Services/Loyalty/*`, `LoyaltyAccount`, `LoyaltyMovement`, `LoyaltyMovementLine`, `LoyaltyReward`, `LoyaltyRewardRedemption`.
- Caja: `app/Services/Cash/*`, notificaciones por correo con reintentos.
- Pedidos/órdenes: `OrderService`, `PurchaseOrderPreparationService`, `PurchaseOrderConversionService`.
- Apartados: `LayawayService`. Devoluciones: `SaleReturnService`. Pagos a proveedores: `AccountsPayableService`.

## Pruebas importantes

Suite principal: `tests/Feature`.

- POS: `PosCheckoutTest`, `PosSuspendedSalesTest`, `PosCashSessionIntegrationTest`.
- Fidelización: `tests/Feature/Loyalty*Test.php` (incluye `LoyaltyExpirationTest`, `LoyaltyExpirationSettingTest`, `LoyaltyCustomerPortalTest`), `PosCheckoutLoyaltyPointsRequestTest`, `PosCheckoutLoyaltyRedemptionTest`, `PosLoyaltyInterfaceTest`, `PosLoyaltyMixedPaymentsTest`, `SaleVoidLoyaltyTest`, `LoyaltySettingsSidebarNavigationTest`. Premios, disponibilidad, canjes y vencimiento: `LoyaltyRewardTest`, `LoyaltyRewardAvailabilityTest`, `LoyaltyRewardRedemptionTest`.
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
