# MVS Commerce — Integraciones

Este documento registra sistemas externos, proyectos paralelos e integraciones actuales o futuras.

Su objetivo es que ningún agente implemente integraciones por su cuenta sin revisar primero el diseño y las decisiones existentes.

---

## 1. Estado general

Actualmente MVS Commerce no depende de integraciones en línea con sistemas externos para su operación principal.

Lo que existe hoy son:

* funcionalidad interna de importación de datos;
* notificaciones internas (correo de eventos de caja);
* módulos desarrollados en paralelo fuera de este repositorio;
* metas futuras de integración (comercio electrónico e IA).

---

## 2. Integración interna entre módulos

La primera "integración" del sistema es la coherencia entre sus propios módulos. Flujos confirmados en el código:

* POS → venta → inventario → caja → cuentas por cobrar → fidelización.
* Compras → inventario → cuentas por pagar.
* Pedidos internos → órdenes de compra → compras.
* Apartados → venta al entregar.

Regla permanente: ampliar estos flujos reutilizando los servicios existentes, nunca creando lógica paralela.

---

## 3. Importación de datos

Capacidades actuales de entrada de datos (no son integraciones en línea):

* importación de compras desde Excel;
* importación de compras desde XML (facturas electrónicas de proveedores);
* manejo de catálogo CABYS;
* importación de inventario inicial desde plantillas.

Estas funciones deben mantenerse como procesos controlados, con validación y trazabilidad.

---

## 4. Proyectos paralelos

Módulos empresariales que se desarrollan fuera de este repositorio antes de su integración.

### Recursos Humanos / Planilla

Estado: DESARROLLO PARALELO FUERA DE ESTE REPOSITORIO.

Trabajo realizado por otro colaborador en un proyecto separado. Incluye áreas como:

* empleados;
* incidencias;
* incapacidades;
* planilla;
* aguinaldo;
* reglas laborales.

No duplicar este módulo dentro de MVS Commerce sin revisar primero ese proyecto y el plan de integración.

### Contabilidad

Estado: DESARROLLO PARALELO / PLANIFICADO.

Existe trabajo contable fuera del flujo principal de MVS Commerce.

Deberá integrarse mediante una arquitectura definida que evite duplicar modelos, reglas y responsabilidades.

Principio aplicable a ambos proyectos (decisión D014):

> integrar, no duplicar.

Antes de crear funcionalidad equivalente dentro de MVS Commerce:

1. revisar el repositorio o proyecto paralelo;
2. identificar modelos y reglas existentes;
3. definir límites de responsabilidad;
4. diseñar la integración.

---

## 5. Comercio electrónico (futuro)

Ver decisión D013 y `docs/ARQUITECTURA.md`.

Meta: conectar MVS Commerce con tiendas en línea sincronizando:

* productos;
* precios;
* inventario;
* imágenes;
* descripciones;
* ventas.

Condiciones obligatorias del diseño futuro:

* fuente de inventario explícita (sucursal o bodega configurada como origen);
* ventas de cualquier canal generan movimientos una sola vez;
* trazabilidad completa de la sincronización;
* soporte multiempresa.

No implementar sin diseño previo aprobado y registrado.

### Punto de entrada de fidelización ya disponible (F36/F37)

La capa online de fidelización existe desde F36 (acumulación) y F37 (canje); debe reutilizarse cuando se construya el canal real:

* `App\Services\Loyalty\LoyaltyOnlineSaleService::accrueForSale(Sale $sale, ?Customer $customer, string $externalReference, string $channel = 'online')` — acredita puntos por venta confirmada (F36).
* `App\Services\Loyalty\LoyaltyOnlineSaleService::redeemForSale(Sale $sale, ?Customer $customer, string|int $requestedPoints, string $externalReference, string $channel = 'online')` — canjea puntos sobre venta confirmada y registra el pago con puntos como `SalePayment` (F37).
* Ambos requieren una venta ya persistida y confirmada (`status=completed`) resuelta internamente (empresa/sucursal desde la venta); nunca confiar en IDs externos sin resolverlos.
* Idempotencia por `event_key` determinista `online_sale:{canal}:{referencia}:loyalty:earn|redemption` (índice único `(company_id, event_key)`): reintentos no duplican nada; earn y redemption usan claves independientes.
* La sincronización de productos/inventario/precios sigue pendiente de diseño (D013); F36–F37 no la implementan.

---

## 6. Inteligencia artificial (futuro)

Ver decisiones D015, D016 y D017.

La IA será una capa adicional basada en datos reales del sistema y deberá respetar permisos.

Nivel de automatización progresivo previsto:

1. observar;
2. recomendar;
3. preparar una acción;
4. solicitar aprobación;
5. ejecutar;
6. registrar resultado.

Las acciones externas o sensibles (mensajes, compras, precios, inventario, movimientos financieros) requerirán controles y autorización explícitos.

---

## 7. Canales de comunicación (futuro)

Podrán integrarse canales externos para preparar o enviar comunicaciones a clientes, proveedores, personal y administradores.

Toda comunicación automática deberá respetar:

* permisos;
* privacidad;
* configuración empresarial;
* aprobación cuando corresponda;
* trazabilidad.

---

## 8. Regla para nuevas integraciones

Cuando se incorpore una integración nueva, documentarla aquí indicando:

* sistema externo involucrado;
* alcance y sentido del flujo de datos;
* fuente de verdad de cada dato;
* control de duplicación e idempotencia;
* permisos y trazabilidad requeridos;
* decisión asociada en `docs/DECISIONES.md` cuando aplique.

Nunca implementar una integración basándose únicamente en este documento.
