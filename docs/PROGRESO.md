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

Estado: DESARROLLO ACTIVO — F01–F22 COMPLETADOS según el Cronograma Maestro; F28 completada de forma adelantada. Fuente oficial del orden: `docs/CRONOGRAMA_FIDELIZACION.md` (sincronizado con `docs/Cronograma_Maestro_Fidelizacion_MVS_Commerce_Actualizado_23-08-2026.xlsx`).

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

Nota: no se implementan vencimiento (F22–F23), portal (F30+), online (F36–F37) ni devoluciones de canjes (F29).

#### F22 — Vencimiento configurable — COMPLETADO

- política de vencimiento Sí/No con cantidad libre de meses enteros de inactividad (1–120, sin opciones rígidas tipo 3/6/12);
- campos `expiration_enabled` (boolean) y `expiration_months` (unsignedInteger nullable) **reutilizados de la infraestructura base**; sin migraciones nuevas ni duplicación;
- validación condicional en `UpdateLoyaltySettingRequest`: meses obligatorios, enteros, ≥ 1 y ≤ 120 solo cuando está activado; prohibido indicar meses con el vencimiento desactivado; desactivar limpia los meses para representar claramente que los puntos no vencen;
- tarjeta nueva en la pantalla de configuración de Fidelización, consistente con el estilo MVS y protegida por los permisos existentes de configuración;
- F22 SOLO configura la política: no vence puntos, no crea movimientos ni procesos automáticos.

Evidencia: cambios en `UpdateLoyaltySettingRequest`, `SettingController` y vista `settings/index`; `LoyaltyExpirationSettingTest` (7 tests, 62 aserciones).

#### F28 — Reversión de puntos por anulación — COMPLETADO (ADELANTADO)

Implementada durante la integración POS, antes de su posición en el cronograma (entre F19 y F27). Anular una venta revierte sus efectos de fidelización con trazabilidad e idempotencia.

Evidencia: commit `7be1f80`; `SaleVoidService`; `SaleVoidLoyaltyTest`.

Importante: el adelanto de F28 **NO** altera el orden del cronograma.

Auditoría posterior a la integración POS: se ejecutaron 152 tests relacionados con Loyalty / POS-Loyalty con 0 fallos.

Evidencia histórica:

- `8392dd4` — completar canje de puntos.
- `7be1f80` — integración de fidelización en POS.

### Brechas detectadas pendientes

- **F29 — Ajuste por devolución (PENDIENTE, NO es la siguiente fase):** `SaleReturnService` actualmente no ajusta fidelización en devoluciones parciales. La anulación completa sí dispone de reversión de puntos (F28), pero una devolución puede dejar puntos ganados/canjeados sin el ajuste correspondiente. Debe ejecutarse respetando el orden F23–F27.
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

F01–F22 COMPLETADOS según el Cronograma Maestro, más F28 completada de forma adelantada.

Último hito confirmado: F22 — Vencimiento configurable.

Siguiente fase según cronograma: **F23 — Vencimiento automático** (salida trazable de puntos por inactividad respetando la política F22). No iniciar ninguna otra fase sin autorización.

Antes de continuar una fase nueva, revisar:

- código actual
- tests de Loyalty
- último commit
- servicios disponibles
- `docs/CRONOGRAMA_FIDELIZACION.md` para determinar la fase real

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