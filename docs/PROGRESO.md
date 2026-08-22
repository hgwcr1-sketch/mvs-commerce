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
- apartados

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

## Fidelización

Estado: DESARROLLO ACTIVO

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
- acumulación de puntos
- valor monetario del punto
- reglas de acumulación
- earn_on_offers
- precisión decimal
- idempotencia
- última compra calificadora
- Kardex de movimientos
- mínimo monetario de canje

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

El trabajo de Fidelización ha avanzado hasta fases posteriores a acumulación y Kardex.

Antes de continuar una fase nueva, revisar:

- código actual
- tests de Loyalty
- último commit
- servicios disponibles

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