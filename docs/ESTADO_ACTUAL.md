# MVS Commerce — Estado actual

Documento corto de relevo entre agentes. Actualizar al terminar cada tarea importante.

> La información de este archivo es una fotografía. Antes de programar, comprobar el estado real del repositorio: `git status`, último commit y código del módulo.

---

## Estado actual de Fidelización

Fuente oficial del orden de fases: `docs/Cronograma_Maestro_Fidelizacion_MVS_Commerce_Actualizado_23-08-2026.xlsx`, reflejada en `docs/CRONOGRAMA_FIDELIZACION.md`.

**F01–F32 y F34: COMPLETADO** según el cronograma maestro (F33 bloqueada por dependencia QR sin autorizar), sujeto al detalle en `docs/PROGRESO.md`.

Último hito confirmado:

**F34 — Acceso por enlace seguro: COMPLETADO.**

- Tabla nueva `loyalty_portal_accesses` + `LoyaltyPortalAccessService`: token aleatorio de 60 caracteres (CSPRNG) asociado a `(company_id, customer_id)`, guardado SOLO como hash SHA-256 — el enlace completo se muestra una única vez al generarlo/regenerarlo; regenerar revoca el anterior; revocación explícita.
- Ruta pública `/fidelidad/portal/acceso/{token}` (`loyalty.portal.access`, `throttle:30,1`, sin auth staff): resuelve token→empresa+cliente activos y renderiza el mismo portal F30–F32 vía `LoyaltyCustomerPortalService`.
- Administración "Accesos al portal" bajo permiso nuevo sembrado `fidelidad.portal` con entrada en sidebar; aislamiento total por empresa.
- La URL no contiene IDs internos ni datos personales. No se usaron signed URLs (expondrían customer_id).
- Evidencia: `LoyaltyPortalAccessTest` (7 tests, 70 aserciones); regresión Loyalty/POS-Loyalty/Devoluciones/Portal: 253 tests, 1696 aserciones, 0 fallos.

Hito anterior:

**F31 — Identidad visual + F32 — Marca MVS Commerce: COMPLETADOS.**

- El portal muestra la identidad de la empresa con datos existentes: `trade_name` siempre y `logo` cuando existe (convención ya soportada `asset('storage/…')`); fallback elegante con la inicial del nombre comercial. Sin columnas nuevas ni temas por empresa.
- Pie discreto "Hecho con MVS Commerce" en `layouts/portal`; texto plano sin enlace (no existe URL oficial configurada).

Además:

- **F33 — QR: PENDIENTE/BLOQUEADA.** No existe librería QR en el stack y no se instaló ninguna sin autorización. Arquitectura lista (`LoyaltyPortalAccessService::qrSupported()` devuelve false, pantalla "Accesos al portal" preparada). Propuesta para cuando el usuario autorice: `chillerlan/php-qrcode` (PHP puro, local). Prohibido usar APIs QR externas (enviarían el token del cliente).
- **F35 — Publicidad/promociones: SIGUIENTE** (única fase autorizada para iniciar; F33 requiere decisión previa sobre dependencia QR).
- **F28 — Reversión de puntos por anulación: COMPLETADO de forma adelantada** durante la integración POS (`7be1f80`).

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

- Fidelización: fases confirmadas hasta F34 (acceso por enlace seguro); etapa 10 (portal) en curso — F33 bloqueada por dependencia QR sin autorizar; pendiente F35. Siguiente fase según cronograma: **F35 — Publicidad/promociones**.
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
- Fidelización: `tests/Feature/Loyalty*Test.php` (incluye `LoyaltyExpirationTest`, `LoyaltyExpirationSettingTest`, `LoyaltyCustomerPortalTest`, `LoyaltyPortalAccessTest`), `PosCheckoutLoyaltyPointsRequestTest`, `PosCheckoutLoyaltyRedemptionTest`, `PosLoyaltyInterfaceTest`, `PosLoyaltyMixedPaymentsTest`, `SaleVoidLoyaltyTest`, `LoyaltySettingsSidebarNavigationTest`. Premios, disponibilidad, canjes y vencimiento: `LoyaltyRewardTest`, `LoyaltyRewardAvailabilityTest`, `LoyaltyRewardRedemptionTest`.
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
