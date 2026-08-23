# MVS Commerce — Estado actual

Documento corto de relevo entre agentes. Actualizar al terminar cada tarea importante.

> La información de este archivo es una fotografía. Antes de programar, comprobar el estado real del repositorio: `git status`, último commit y código del módulo.

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

- pedidos internos (`Order`) y órdenes de compra con conversión a compras;
- canje de puntos de fidelización;
- integración de caja con POS;
- documentación: `INTEGRACIONES.md` completado, módulos nuevos registrados en arquitectura/progreso y este archivo creado.

## Trabajo en curso

- POS: expansión activa (uno de los módulos principales).
- Fidelización: avance posterior a acumulación, Kardex y canje; revisar tests de Loyalty antes de continuar.
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
- Fidelización: `app/Services/Loyalty/*`, `LoyaltyAccount`, `LoyaltyMovement`, `LoyaltyMovementLine`.
- Caja: `app/Services/Cash/*`, notificaciones por correo con reintentos.
- Pedidos/órdenes: `OrderService`, `PurchaseOrderPreparationService`, `PurchaseOrderConversionService`.
- Apartados: `LayawayService`. Devoluciones: `SaleReturnService`. Pagos a proveedores: `AccountsPayableService`.

## Pruebas importantes

Suite principal: `tests/Feature`.

- POS: `PosCheckoutTest`, `PosSuspendedSalesTest`, `PosCashSessionIntegrationTest`.
- Fidelización: `tests/Feature/Loyalty*Test.php`.
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
