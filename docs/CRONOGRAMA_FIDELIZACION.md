# MVS Commerce — Cronograma de Fidelización

Punto permanente de control de fases del módulo de Fidelización.

**Fuente oficial del orden y la numeración de fases:** `docs/Cronograma_Maestro_Fidelizacion_MVS_Commerce_Actualizado_23-08-2026.xlsx` (hoja "Cronograma Fidelización", 45 fases + subfase F08.1).

> Este documento refleja el Excel maestro vigente a su fecha. Cualquier cambio del Excel debe reflejarse aquí. El Excel manda en ORDEN; Git + código + tests mandan en EVIDENCIA de lo implementado.

---

## Regla de interpretación

- **CRONOGRAMA (Excel maestro)** = orden oficial y numeración de fases.
- **ESTADO_ACTUAL** (`docs/ESTADO_ACTUAL.md`) = punto actual de trabajo / relevo.
- **GIT + CÓDIGO + TESTS** = qué está realmente implementado.
- **PROGRESO** (`docs/PROGRESO.md`) = resumen del avance.

Nunca utilizar únicamente uno de ellos.

Si cronograma y código difieren: **NO retroceder ni rehacer trabajo automáticamente**. Registrar la discrepancia y aplicar el protocolo de contradicción.

### Regla sobre trabajo adelantado

Si se descubre una funcionalidad implementada antes de su fase, esa fase se marca **COMPLETADO (ADELANTADO)** con su evidencia, pero eso **NO cambia cuál fase corresponde ejecutar a continuación** según el cronograma.

Ejemplo confirmado: **F28 está completado de forma adelantada durante la integración POS, pero eso NO convierte a F29 en la siguiente fase: la siguiente fase es F19 — Premios por puntos.**

---

## Regla obligatoria para agentes

Antes de trabajar en Fidelización:

1. leer `AGENTS.md`;
2. leer `docs/CRONOGRAMA_FIDELIZACION.md` (este archivo);
3. leer `docs/ESTADO_ACTUAL.md`;
4. leer la sección Fidelización de `docs/PROGRESO.md`;
5. comprobar `git status`;
6. revisar commits recientes relacionados;
7. inspeccionar código y tests de la fase;
8. determinar la fase real antes de modificar código.

Un agente NO puede:

- saltar fases silenciosamente;
- retroceder a una fase ya implementada;
- inventar una fase;
- renumerar fases;
- reinterpretar una fase;
- declarar completa una fase solo porque la documentación lo diga;
- cambiar el cronograma sin autorización del usuario.

Si existe contradicción entre fuentes:

> DETENER implementación → informar discrepancia → presentar evidencia → esperar decisión cuando afecte el orden del cronograma.

---

## Tabla maestra F01–F45

Estados tomados del Excel maestro del 23-08-2026. La columna "Evidencia" agrega la confirmación del repositorio (commits `8392dd4` — canje de puntos; `7be1f80` — integración POS; auditoría posterior: 152 tests Loyalty/POS-Loyalty sin fallos).

| ID | Etapa | Entregable | Estado | Prioridad | Depende de | Criterio de cierre | Evidencia |
|---|---|---|---|---|---|---|---|
| F01 | 1. Análisis | Revisión del proyecto | COMPLETADO | CRÍTICA | — | Informe técnico recibido; análisis sin modificaciones | análisis inicial |
| F02 | 1. Análisis | Arquitectura de Fidelidad | COMPLETADO | CRÍTICA | F01 | Arquitectura base aprobada: configuración, cuenta, Kardex, movimientos y líneas | diseño aprobado |
| F03 | 1. Análisis | Reglas funcionales finales | COMPLETADO | CRÍTICA | F01-F02 | Reglas aprobadas: porcentaje, promociones, bonos, canje, vencimiento, reversos y multisucursal | reglas aprobadas |
| F04 | 1. Análisis | Diseño visual | COMPLETADO | ALTA | F01 | Administración centralizada y portal cliente elegante, responsive, estilo MVS Commerce | diseño aprobado |
| F05 | 2. Base | Módulo y configuración | COMPLETADO | CRÍTICA | F02-F04 | Base implementada: tablas, modelos, restricciones, DECIMAL(19,4), Kardex y pruebas | `8392dd4`; `LoyaltyInfrastructureTest` |
| F06 | 2. Base | Cuenta de fidelidad | COMPLETADO | CRÍTICA | F05 | Cuenta y Kardex central con LoyaltyAccountService, transacciones, locks, BCMath, idempotencia y reversión | `8392dd4`; `LoyaltyAccountServiceTest` |
| F07 | 2. Base | Saldo y Kardex | COMPLETADO | CRÍTICA | F06 | Kardex administrativo de solo lectura con filtros, paginación, aislamiento por empresa y permiso `fidelidad.ver` | `8392dd4`; `LoyaltyKardexTest` |
| F08 | 3. Acumulación | Regla de acumulación por porcentaje | COMPLETADO | CRÍTICA | F07 | Monto elegible × porcentaje / 100, BCMath, redondeo half-up y auditoría | `8392dd4`; `LoyaltyEarningServiceTest` |
| F08.1 | 3. Acumulación | Porcentaje configurable | COMPLETADO | CRÍTICA | F08 | Cambiar porcentaje y comprobar que el cálculo cambia correctamente | `8392dd4`; `LoyaltyPercentageSettingTest` |
| F09 | 3. Acumulación | Cliente nuevo | COMPLETADO | ALTA | F01-F08 | Puntos acreditados una sola vez al completar venta | `8392dd4`; tipo `new_customer` en `LoyaltyMovement`/`LoyaltyAccountService` |
| F10 | 3. Acumulación | Cumpleaños | COMPLETADO | ALTA | F07-F08 | Bono correcto y no duplicado | `8392dd4`; `LoyaltyBirthdayServiceTest` |
| F11 | 3. Acumulación | Cliente que retorna | COMPLETADO | ALTA | F07-F08 | Regla se dispara correctamente | `8392dd4`; `LoyaltyReturningCustomerServiceTest` |
| F12 | 3. Acumulación | Multiplicadores | COMPLETADO | ALTA | F08 | Cálculo correcto en promoción | `8392dd4`; `LoyaltyMultiplierTest` |
| F13 | 3. Acumulación | Ofertas | COMPLETADO | ALTA | F08 | Configurar si acumula puntos en ofertas; regla respetada | `8392dd4`; `earn_on_offers`, `LoyaltyPosIntegrationTest` |
| F14 | 4. Canje | Valor del punto | COMPLETADO | CRÍTICA | F07 | Canje calcula valor correcto (1 punto = ₡1 por defecto, configurable) | `8392dd4`; `LoyaltyPointValueTest` |
| F15 | 4. Canje | Mínimo para usar puntos | COMPLETADO | CRÍTICA | F14 | Sistema bloquea/permite según configuración (ej. mínimo ₡3.000) | `8392dd4`; `LoyaltyRedemptionMinimumTest` |
| F16 | 4. Canje | Máximo de una compra | COMPLETADO | ALTA | F15 | POS respeta límite: % de la compra pagable con puntos | `8392dd4`; `LoyaltyRedemptionLimitTest` |
| F17 | 4. Canje | Usar puntos en ofertas | COMPLETADO | ALTA | F15-F16 | Regla respetada al canjear sobre ofertas | `8392dd4`; `redeem_on_offers`, `LoyaltyRedemptionServiceTest` |
| F18 | 4. Canje | Forma de pago Puntos | COMPLETADO | CRÍTICA | F14-F17 | Venta completa con puntos como forma de pago en POS | `7be1f80`; suite POS-Loyalty completa |
| F19 | 5. Premios | Premios por puntos | COMPLETADO | CRÍTICA | F14 | Crear, activar, desactivar y canjear premios directos | administración implementada (`loyalty_rewards`, `LoyaltyRewardController`, permiso `fidelidad.premios`, `LoyaltyRewardTest`); el canje de premios corresponde a F21 |
| F20 | 5. Premios | Stock / disponibilidad | PENDIENTE | ALTA | F19 | No permite canje de premio agotado | — |
| F21 | 5. Premios | Historial de canjes | PENDIENTE | CRÍTICA | F07,F19 | Kardex + canje coinciden | — |
| F22 | 6. Vencimiento | Vencimiento configurable | PENDIENTE | ALTA | F07 | Sí/No + meses libres de inactividad, sin opciones rígidas | — |
| F23 | 6. Vencimiento | Vencimiento automático | PENDIENTE | ALTA | F22 | Salida de puntos trazable en Kardex al vencer | — |
| F24 | 7. Bonos | Centro de reglas | PENDIENTE | CRÍTICA | F09-F13 | Cliente nuevo, cumpleaños, retorno y reglas futuras desde un solo lugar | — |
| F25 | 7. Bonos | Ajuste manual | PENDIENTE | ALTA | F07 | Permiso + Kardex + motivo obligatorio | nota: `LoyaltyAccountService` ya soporta ajustes a nivel servicio; falta interfaz administrativa |
| F26 | 8. Multisucursal | Saldo global de empresa | PENDIENTE | CRÍTICA | F06-F08 | Compra en sucursales distintas suma al mismo saldo | ver contraste abajo |
| F27 | 8. Multisucursal | Canje en cualquier sucursal | PENDIENTE | CRÍTICA | F18,F26 | Canje cruzado entre sucursales activas | ver contraste abajo |
| **F28** | **9. Anulación** | **Reversión de puntos** | **COMPLETADO (ADELANTADO)** | CRÍTICA | F07,F18 | Puntos revertidos y trazables al anular venta | `7be1f80`; `SaleVoidService`, `SaleVoidLoyaltyTest` |
| F29 | 9. Devolución | Ajuste por devolución | PENDIENTE | CRÍTICA | F07,F28 | Pruebas de devolución pasan | brecha conocida: `SaleReturnService` no ajusta fidelización; **NO es la siguiente fase** |
| F30 | 10. Portal | Portal del cliente | PENDIENTE | CRÍTICA | F19-F23 | Web responsive para puntos, premios, movimientos y promociones | — |
| F31 | 10. Portal | Identidad visual | PENDIENTE | ALTA | F04,F30 | Logo/nombre de tienda, estilo único MVS Commerce | — |
| F32 | 10. Portal | Marca MVS Commerce | PENDIENTE | MEDIA | F30 | Pie discreto "Hecho con MVS Commerce" | — |
| F33 | 10. Portal | QR | PENDIENTE | ALTA | F30 | QR seguro, sin exponer información indebida | — |
| F34 | 10. Portal | Acceso por enlace | PENDIENTE | ALTA | F30 | Link seguro funcional | — |
| F35 | 10. Portal | Publicidad/promociones | PENDIENTE | MEDIA | F30 | Contenido visible y administrable | — |
| F36 | 11. Online | Acumulación online | PENDIENTE | CRÍTICA | F08,F30 | Compra online suma al mismo saldo | — |
| F37 | 11. Online | Canje online | PENDIENTE | CRÍTICA | F15-F18,F36 | Canje online descuenta saldo central | — |
| F38 | 12. Permisos | Administrador | PENDIENTE | CRÍTICA | F24-F25 | Permisos correctos para configurar todo | nota: permisos base `fidelidad.*` ya sembrados; falta alcance completo |
| F39 | 12. Permisos | Cajero | PENDIENTE | CRÍTICA | F18,F38 | Consulta/canje permitidos; 403 en configuración | — |
| F40 | 13. Dashboard | Indicadores | PENDIENTE | MEDIA | F07,F21-F23 | Totales de clientes, generados, canjeados, vencidos y saldo | — |
| F41 | 13. Dashboard | Empresa / sucursal | PENDIENTE | MEDIA | F26,F40 | Información global por empresa y por sucursal | — |
| F42 | 14. Calidad | Suite de pruebas | PENDIENTE | CRÍTICA | F01-F41 | Suite completa aprobada | nota: ya existe suite amplia Loyalty/POS-Loyalty (152 tests OK); quedan áreas de fases pendientes |
| F43 | 14. Calidad | UI / usabilidad | PENDIENTE | CRÍTICA | F30-F41 | Prueba visual/manual aprobada | — |
| F44 | 14. Calidad | Regresión | PENDIENTE | CRÍTICA | F42-F43 | Clientes, POS, Compras y Caja siguen funcionando | — |
| F45 | 15. Cierre | Respaldo GitHub | PENDIENTE | CRÍTICA | F44 | diff --check, tests, commit, push, working tree limpio | — |

---

## Control por etapas (resumen hoja 2 del Excel)

| Etapa | Objetivo | Estado |
|---|---|---|
| 1. Análisis y diseño | Mapa técnico + reglas + diseño aprobados | COMPLETADO |
| 2. Base | Configuración, cuenta y Kardex | COMPLETADO |
| 3. Acumulación | Porcentaje configurable + bonos + multiplicadores | COMPLETADO |
| 4. Canje | Puntos como pago + límites + ofertas | COMPLETADO |
| 5. Premios | Premios directos y canjes | SIGUIENTE |
| 6. Vencimiento | Inactividad + vencimiento automático | PENDIENTE |
| 7. Administración | Centro de reglas y ajustes | PENDIENTE |
| 8. Multisucursal | Saldo global por empresa | PENDIENTE |
| 9. Reversiones | Anulación y devolución | PARCIAL — F28 anulación completada de forma adelantada; F29 devolución sigue pendiente y no debe adelantarse al orden F19–F27 |
| 10. Portal cliente | Web + QR + promociones | PENDIENTE |
| 11. Tienda online | Acumulación y canje online | PENDIENTE |
| 12. Permisos | Administrador vs cajero | PENDIENTE |
| 13. Dashboard | Empresa + sucursales | PENDIENTE |
| 14. Calidad | Tests + UI + regresión | PENDIENTE |
| 15. Cierre | GitHub respaldado | PENDIENTE |

---

## Regla central de acumulación (hoja 3 del Excel)

- Modelo: el cliente **NO** recibe 1 punto por cada ₡1 comprado.
- Cálculo: `puntos = monto elegible × porcentaje de fidelización`.
- Ejemplo: compra ₡1.000 con 5% → ₡50 de beneficio → 50 puntos.
- Configuración: el administrador elige el porcentaje; nunca fijo en código.
- Canje inicial: 1 punto representa ₡1 de valor de canje, sujeto a las reglas configuradas.

---

## Contraste cronograma vs repositorio (discrepancias registradas)

Fases que el maestro mantiene PENDIENTES pero donde código/tests ya muestran avance parcial o total. **No cambiar su estado oficial sin autorización del usuario; registrar y esperar decisión:**

- **F26 — Saldo global de empresa**: `LoyaltyPosIntegrationTest::test_customer_account_is_global_across_branches` demuestra cuenta única por empresa entre sucursales; los documentos de riesgo del proyecto ya lo asumen. Evidencia sólida de trabajo adelantado; requiere decisión del usuario para marcarlo COMPLETADO (ADELANTADO).
- **F27 — Canje en cualquier sucursal**: técnicamente el canje opera sobre la cuenta de empresa sin restricción de sucursal (`branch_id` es origen); depende oficialmente de F26.
- **F25 — Ajuste manual**: soporte a nivel servicio existe (`LoyaltyAccountService`, ajustes en ambas direcciones con test); falta interfaz administrativa con motivo obligatorio y permiso propio.
- **F42 — Suite de pruebas**: suite Loyalty/POS-Loyalty amplia ya existente y en verde; incompleta respecto a fases aún pendientes (premios, vencimiento).
- Brecha futura fuera del cronograma: WhatsApp registra contactos/plantillas pero no envía por API.

---

## Estado y próxima fase

- **Completadas:** F01–F19 según maestro; F28 completada de forma adelantada durante la integración POS (`7be1f80`).
- **F19 — Premios por puntos: COMPLETADO** (administración básica). Evidencia: migración `loyalty_rewards`, modelo `LoyaltyReward` con tipos product/discount/service/gift, `LoyaltyRewardController` + `SaveLoyaltyRewardRequest`, rutas `loyalty.rewards.*` bajo permiso `fidelidad.premios`, vista `loyalty/rewards/index`, entrada en sidebar y `LoyaltyRewardTest` (5 tests, 49 aserciones). Preparado para F20 (stock) y F21 (historial/canje).
- **SIGUIENTE:** **F20 — Stock / disponibilidad** de premios. Única fase autorizada para iniciar.
- **F29 — Ajuste por devolución:** PENDIENTE. Existe una brecha técnica conocida (`SaleReturnService` no ajusta fidelización en devoluciones parciales; la anulación completa sí revierte). Mantener registrada, pero **NO es la siguiente fase** y no debe adelantarse sobre F20–F27.
- Auditoría de referencia: suite Loyalty/POS-Loyalty en verde tras F18; regresión Loyalty en verde tras F19.
