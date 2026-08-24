# MVS Commerce — Estado actual

Documento corto de relevo entre agentes. Actualizar al terminar cada tarea importante.

> La información de este archivo es una fotografía. Antes de programar, comprobar el estado real del repositorio: `git status`, último commit y código del módulo.

---

## Estado actual de Fidelización

Fuente oficial del orden de fases: `docs/Cronograma_Maestro_Fidelizacion_MVS_Commerce_Actualizado_23-08-2026.xlsx`, reflejada en `docs/CRONOGRAMA_FIDELIZACION.md`.

**F01–F35: COMPLETADO** según el cronograma maestro (F28 de forma adelantada). Etapa 10 (Portal cliente) completa, sujeto al detalle en `docs/PROGRESO.md`.

Último hito confirmado:

**F35 — Publicidad/promociones del portal: COMPLETADO.**

- Tabla `loyalty_promotions` + `LoyaltyPromotion` + `LoyaltyPromotionService`: contenido promocional administrable por empresa (título, descripción corta opcional, inicio/fin, activo/inactivo, orden `sort_order`), independiente de los multiplicadores F12 (sección propia conservada).
- Vigencia centralizada y inclusiva (`is_active && starts_at <= ahora <= ends_at`) con zona horaria de la empresa convertida a UTC (misma semántica temporal que F12); el portal solo muestra promociones activas y vigentes de su empresa.
- Administración "Promociones del portal" bajo permiso nuevo sembrado `fidelidad.promociones`: CRUD mínimo con badge de estado (vigente/futura/vencida/inactiva) y 404 ante empresas ajenas; sidebar condicionada.
- Decisiones V1: sin sucursal (portal resuelve empresa global, puntos globales F26) y sin imagen (sin infraestructura segura/reutilizable de uploads en el módulo).
- Evidencia: `LoyaltyPromotionTest` (6 tests); regresión Loyalty/POS-Loyalty/Devoluciones/Portal: 265 tests, 1804 aserciones, 0 fallos.

Hito anterior:

**F33 — QR + F34 — Acceso por enlace seguro: COMPLETADOS.**

- F34: tabla `loyalty_portal_accesses` con token aleatorio de 60 caracteres (CSPRNG) guardado SOLO como hash SHA-256; regenerar revoca el anterior; ruta pública `/fidelidad/portal/acceso/{token}` (`throttle:30,1`, sin auth staff) resuelve token→empresa+cliente y renderiza el portal F30–F32; URL sin IDs internos ni datos personales.
- F33: QR local con `chillerlan/php-qrcode` 6.0.1 codificando exactamente el enlace F34 (SVG vectorial ECC H, botón de impresión); se entrega junto al enlace en la única respuesta donde el token existe en claro y nunca se persiste; regenerar o revocar invalida automáticamente cualquier QR impreso anterior. Sin APIs externas de QR.
- Administración "Accesos al portal" bajo permiso `fidelidad.portal`; aislamiento total por empresa.
- Evidencia: `LoyaltyPortalAccessTest` (7 tests) + `LoyaltyPortalAccessQrTest` (5 tests); regresión al cierre de F33: 259 tests, 1749 aserciones, 0 fallos.

Hito anterior:

**F31 — Identidad visual + F32 — Marca MVS Commerce: COMPLETADOS.**

- El portal muestra la identidad de la empresa con datos existentes: `trade_name` siempre y `logo` cuando existe (convención ya soportada `asset('storage/…')`); fallback elegante con la inicial del nombre comercial. Sin columnas nuevas ni temas por empresa.
- Pie discreto "Hecho con MVS Commerce" en `layouts/portal`; texto plano sin enlace (no existe URL oficial configurada).

Además:

- **F36 — Acumulación online: SIGUIENTE** (única fase autorizada para iniciar; abre la etapa 11. Tienda online).
- **F28 — Reversión de puntos por anulación: COMPLETADO de forma adelantada** durante la integración POS (`7be1f80`).

Evidencia histórica: `8392dd4` (canje de puntos) y `7be1f80` (integración de fidelización en POS). Auditoría posterior a F18: 152 tests Loyalty/POS-Loyalty con 0 fallos. Tras F22: regresión Loyalty en verde (134 tests) más POS-Loyalty (48 tests); vencimiento configurable: 7 tests, 62 aserciones. Tras F23: `LoyaltyExpirationTest` (13 tests, 78 aserciones); regresión Loyalty + POS-Loyalty (177 tests, 1160 aserciones) en verde. Tras F24-F25: `LoyaltyRuleCenterTest` (6) y `LoyaltyManualAdjustmentTest` (10). Tras F26-F27: `LoyaltyMultiBranchTest` (5 tests, 40 aserciones); regresión Loyalty + POS-Loyalty (198 tests, 1295 aserciones) en verde. Tras F29: `SaleReturnLoyaltyTest` (9 tests, 79 aserciones); regresión Devoluciones+F28+Loyalty+POS-Loyalty (228 tests, 1481 aserciones) en verde.

Las denominaciones F18A–F18F se usaron durante el desarrollo pero no están etiquetadas dentro del repositorio; no inventar correspondencia exacta de letras.

---

## Rama actual

`feature/pos`

## Estado del repositorio

Trabajo de F35 (promociones del portal) terminado y probado, pendiente de commit (sin push). Incluye: migración `loyalty_promotions`, modelo/servicio/request/controlador nuevos, vista `loyalty/promotions/index`, cambios en `routes/web.php`, `PermissionSeeder`, sidebar, portal (servicio + vista), `LoyaltyPromotionTest` nuevo y esta documentación. Existe además un directorio sin seguimiento `docs/beautyos/` ajeno a las tareas — no eliminar.

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

- Fidelización: etapa 10 (Portal cliente) completa — F30–F35 confirmados (portal, identidad, marca, QR y promociones). Siguiente fase según cronograma: **F36 — Acumulación online** (etapa 11. Tienda online).
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

- POS: `PosCheckoutTest`, `PosSuspendedSalesTest`, `PosCashSessionIntegrationTest`.
- Fidelización: `tests/Feature/Loyalty*Test.php` (incluye `LoyaltyExpirationTest`, `LoyaltyExpirationSettingTest`, `LoyaltyCustomerPortalTest`, `LoyaltyPortalAccessTest`, `LoyaltyPortalAccessQrTest`, `LoyaltyPromotionTest`), `PosCheckoutLoyaltyPointsRequestTest`, `PosCheckoutLoyaltyRedemptionTest`, `PosLoyaltyInterfaceTest`, `PosLoyaltyMixedPaymentsTest`, `SaleVoidLoyaltyTest`, `LoyaltySettingsSidebarNavigationTest`. Premios, disponibilidad, canjes y vencimiento: `LoyaltyRewardTest`, `LoyaltyRewardAvailabilityTest`, `LoyaltyRewardRedemptionTest`.
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
