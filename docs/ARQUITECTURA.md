# MVS Commerce — Arquitectura técnica

## 1. Visión general

MVS Commerce es una plataforma empresarial modular construida sobre Laravel.

Su arquitectura está orientada a:

* múltiples empresas;
* múltiples sucursales;
* usuarios con roles y permisos;
* inventario por sucursal;
* ventas mediante POS;
* caja;
* compras;
* pedidos internos;
* órdenes de compra y su conversión a compras;
* clientes;
* proveedores;
* cuentas por cobrar;
* cuentas por pagar;
* cotizaciones;
* apartados;
* devoluciones de venta;
* fidelización;
* futuras integraciones con comercio electrónico;
* futuros módulos administrativos, contables y de recursos humanos.

Principio del producto:

> Profesional por dentro. Sencillo por fuera.

---

## 2. Stack principal

Estado conocido actual:

* Laravel (`composer.json` exige `laravel/framework ^13.8`)
* PHP (`composer.json` exige `^8.3`; la versión instalada en el entorno debe verificarse con `php -v`)
* Blade
* Tailwind CSS
* Vite
* SQLite en desarrollo
* Composer
* npm
* Git
* GitHub

La interfaz principal está desarrollada en español.

Antes de depender de una versión específica, el agente debe verificar:

* `composer.json`
* `package.json`
* entorno actual

---

## 3. Estructura principal

La aplicación sigue la estructura estándar de Laravel.

Directorios relevantes:

* `app/Http/Controllers`
* `app/Http/Requests`
* `app/Models`
* `app/Services`
* `database/migrations`
* `database/seeders`
* `resources/views`
* `routes`
* `tests/Feature`

La lógica de negocio importante debe mantenerse preferentemente en servicios y no concentrarse en controladores o vistas.

---

## 4. Multiempresa

MVS Commerce trabaja con aislamiento por empresa.

La empresa activa condiciona el acceso a información empresarial.

Existen mecanismos relacionados con:

* empresa activa;
* permisos por empresa;
* límites de empresas;
* usuarios asociados a empresas;
* datos empresariales aislados.

Regla arquitectónica:

> Toda consulta que deba pertenecer a una empresa debe respetar `company_id` o el mecanismo de ámbito equivalente existente.

Nunca asumir que un registro puede consultarse globalmente si pertenece a una empresa.

---

## 5. Multisucursal

El sistema soporta múltiples sucursales por empresa.

Existe una sucursal activa utilizada por distintos módulos.

El inventario se mantiene por sucursal.

Elementos conocidos:

* `branch_id`
* sucursal activa
* middleware relacionado con sucursal activa
* inventario mediante relación sucursal-producto
* ventas asociadas a sucursal
* caja asociada a sucursal

No confundir:

* datos globales de empresa;
* datos específicos de sucursal.

---

## 6. Autenticación y seguridad

La aplicación dispone de autenticación de usuarios.

Incluye:

* inicio de sesión;
* recuperación de contraseña;
* control de usuario activo/inactivo;
* último acceso;
* roles;
* permisos;
* protección de acciones mediante permisos.

Los módulos del sistema deben respetar los permisos existentes.

No crear permisos nuevos sin verificar primero:

* seeders;
* middleware;
* rutas;
* convenciones existentes.

Existe protección especial para evitar eliminar o dejar sin control al último administrador de una empresa.

---

## 7. Navegación

El acceso principal a módulos se realiza desde la navegación de la aplicación.

Fuente única del menú:

`resources/views/components/navigation/sidebar.blade.php`

La visibilidad de opciones debe respetar permisos.

Agregar enlaces al sidebar debe realizarse normalmente al final de una implementación, cuando:

* la ruta ya existe;
* el permiso existe;
* el módulo está funcional.

### 7.1 Navegación responsive (R01)

La navegación se adapta al dispositivo sin duplicar menús ni permisos:

* **Celular (<768px):** barra inferior fija (`components/navigation/bottom-bar.blade.php`) con accesos P0 condicionados por permiso y botón "Más". El sidebar está oculto.
* **Tablet (768–1023px):** sidebar en modo rail compacto; los grupos desplegables se ocultan por CSS (`.nav-desktop-group`) y se muestra el disparador "Más" (`.nav-more-trigger`).
* **Escritorio (≥1024px):** sidebar compacto expandible por hover/foco o fijado (preferencia `localStorage` `mvs.sidebar.pinned`, componente Alpine `sidebarShell` en `resources/js/navigation.js`).

Reglas permanentes:

* El botón "Más" abre un sheet lateral (`layouts/app.blade.php`) que reutiliza el sidebar real con `context = 'sheet'`: un solo origen de rutas y permisos.
* En rutas POS (`pos.*`) no se renderizan la barra inferior ni el sheet: el POS gestiona su propio espacio de trabajo.
* La barra inferior se oculta temporalmente mientras un campo de texto recibe foco (teclado móvil).
* Al agregar módulos o permisos, actualizar únicamente `sidebar.blade.php`; la barra inferior solo lleva los accesos P0 definidos en `AGENTS.md`.

Evidencia: `ResponsiveNavigationTest`.

---

## 8. Clientes

El módulo de clientes incluye:

* CRUD;
* activar/inactivar;
* contactos;
* direcciones;
* dirección principal;
* aislamiento por empresa.

Existe unicidad empresarial sobre identificación del cliente.

Los clientes pueden relacionarse con:

* ventas;
* cuentas por cobrar;
* cotizaciones;
* fidelización.

---

## 9. Proveedores

Existe módulo de proveedores.

Se utiliza como base para:

* compras;
* abastecimiento;
* futuras recomendaciones de compra.

Las futuras funciones inteligentes de abastecimiento deberán reutilizar proveedores existentes y no crear una estructura paralela sin necesidad.

---

## 10. Inventario

El inventario se administra por sucursal.

Elementos principales conocidos:

* productos;
* categorías;
* marcas;
* unidades;
* stock por sucursal;
* movimientos de inventario;
* Kardex;
* ajustes;
* transferencias.

La relación de stock utiliza una estructura equivalente a:

`branch_product`

Los movimientos utilizan una estructura equivalente a:

`inventory_movements`

Regla central:

> Toda modificación de existencias debe dejar trazabilidad mediante el mecanismo de inventario existente.

Evitar actualizar existencias directamente si existe un servicio o flujo central para hacerlo.

---

## 11. Compras

El módulo de compras está operativo.

Incluye importación desde:

* Excel;
* XML.

Existen controles relacionados con:

* productos;
* CABYS;
* proveedores;
* inventario.

Las compras deben alimentar correctamente el inventario según la arquitectura vigente.

---

## 12. POS

El POS es uno de los módulos centrales.

Elementos conocidos:

* `PosController`
* `PosSaleProcessor`
* `Sale`
* `SaleItem`
* `SalePayment`
* `PaymentMethod`

Formas de pago base:

* efectivo;
* tarjeta;
* SINPE;
* mixto.

El POS contempla o debe contemplar:

* consumidor final;
* cliente identificado;
* descuentos;
* cambio de precio bajo permiso;
* pagos múltiples;
* vuelto;
* inventario por sucursal;
* suspender ventas;
* recuperar ventas;
* cotizaciones;
* caja;
* cuentas por cobrar;
* fidelización.

---

## 13. Flujo general de venta

Flujo conceptual:

Cliente / Consumidor Final

→ POS

→ validaciones

→ venta

→ líneas de venta

→ pagos

→ afectación de inventario

→ afectación de caja cuando corresponda

→ cuentas por cobrar cuando corresponda

→ fidelización cuando corresponda

Cada etapa debe mantener:

* aislamiento por empresa;
* sucursal correcta;
* precisión monetaria;
* idempotencia donde aplique;
* trazabilidad.

---

## 14. Formas de pago

Las formas de pago se gestionan mediante entidad propia.

Existe administración configurable de formas de pago.

Las formas base conocidas son:

* cash;
* card;
* sinpe.

No codificar lógica empresarial importante exclusivamente utilizando etiquetas visibles.

Preferir identificadores o códigos internos existentes.

---

## 15. Caja

El sistema dispone de manejo de caja y sesiones.

Incluye conceptos como:

* apertura;
* cierre;
* entradas;
* salidas;
* conciliación;
* denominaciones;
* métodos de pago;
* historial de sesiones;
* eventos;
* notificaciones.

Existe historial de sesiones de caja con filtros y detalle.

Las sesiones abiertas y cerradas deben mantener separación clara.

Los valores monetarios de una sesión abierta pueden tener restricciones de visualización.

---

## 16. Notificaciones de caja

Existe infraestructura para notificaciones relacionadas con sesiones de caja.

Elementos conocidos:

* `CashSessionMailNotification`
* `SendCashSessionMailNotification`
* `CashSessionMailRetryService`

Estados conocidos:

* pending;
* processing;
* sent;
* failed;
* skipped.

Existe control de:

* intentos;
* reintentos;
* destinatarios;
* disponibilidad futura;
* estados terminales.

---

## 17. Cuentas por cobrar

Existe módulo de cuentas por cobrar.

Puede relacionarse con:

* ventas;
* clientes;
* pagos;
* apartados;
* abonos.

Las integraciones futuras del POS deben reutilizar este módulo y evitar crear una segunda lógica de deuda.

---

## 18. Cotizaciones

El sistema cuenta con trabajo relacionado con cotizaciones.

Las cotizaciones deben permanecer separadas de las ventas definitivas hasta el momento apropiado.

No asumir que una cotización debe afectar inventario de la misma manera que una venta completada.

---

## 19. Pedidos internos

Elementos conocidos:

* `Order`
* `OrderItem`
* `OrderService`
* `OrderController`

Características confirmadas en código:

* pedidos con estado pendiente al crearse;
* creación desde el flujo del POS mediante la ruta `pedidos.store`, con cantidades enteras o fraccionarias;
* revisión de líneas de pedido;
* asociación de proveedor por línea de producto;
* permisos propios (`pedidos.ver`, `pedidos.crear`).

Los pedidos internos alimentan la preparación de órdenes de compra.

---

## 20. Órdenes de compra y conversión

Elementos conocidos:

* `PurchaseOrder`
* `PurchaseOrderItem`
* `PurchaseOrderItemSource`
* `PurchaseOrderSourceConversion`
* `PurchaseOrderPreparationService`
* `PurchaseOrderConversionService`
* `PurchaseOrderController`

Características confirmadas:

* estados: draft, prepared, sent, received, cancelled;
* preparación de órdenes a partir de pedidos internos (permiso `pedidos.preparar_compra`);
* conversión de una orden preparada en compras reales, incluyendo conversión parcial por cantidades pendientes;
* trazabilidad entre pedido interno, orden de compra y compra generada;
* permisos relacionados (`compras.ordenes`, `compras.crear`).

Flujo conceptual:

Pedido interno → orden de compra → compra → inventario → cuenta por pagar cuando corresponda

---

## 21. Apartados (Layaway)

Elementos conocidos:

* `Layaway`
* `LayawayItem`
* `LayawayPayment`
* `LayawayAlert`
* `CompanyAllowance`
* `LayawayService`
* `LayawayController`

Flujo confirmado en código:

* creación de apartado con reserva de inventario;
* abonos con bloqueo optimista del registro;
* cancelación con liberación de inventario;
* vencimiento automático de apartados expirados (`expireDue`);
* entrega: genera una venta cuando el apartado está completamente pagado;
* alertas de vencimiento próximo por empresa, con días configurables (`layaway_alert_days`);
* permisos propios (`apartados.*`).

---

## 22. Devoluciones y anulación de ventas

Elementos conocidos:

* `SaleReturn`
* `SaleReturnItem`
* `SaleReturnService`
* `SaleVoidService`
* `ReturnController`

Características confirmadas:

* devolución sobre una venta existente con motivo y líneas específicas (permiso `devoluciones.crear`);
* anulación de ventas (permiso `ventas.anular`);

Ambas operaciones deben mantener trazabilidad e impacto correcto en inventario.

---

## 23. Cuentas por pagar

Elementos conocidos:

* `AccountPayable`
* `AccountPayablePayment`
* `AccountPayableAlert`
* `AccountsPayableService`
* `AccountPayableAlertService`
* `PurchaseAccountPayableService`
* `AccountsPayableController`

Características confirmadas:

* cuentas por pagar asociadas a compras;
* abonos con afectación de sesión de caja mediante resolución de sesión activa (`CashSessionResolver`);
* alertas de vencimiento con días configurables por empresa;
* panel con alertas para el dashboard;
* permisos propios (`cuentas_pagar.*`).

---

## 24. Facturación y reportes

Estado real verificado en código:

* `InvoiceController` y `ReportController` son esqueletos vacíos;
* las rutas `facturas` y `reportes` existen sin middleware de permisos.

No debe asumirse que exista facturación electrónica ni un módulo de reportes implementado.

Lo que sí existe como funcionalidad interna:

* comprobante de venta del POS (`pos.receipt`);
* impresión y PDF de compras;
* impresión de cotizaciones.

Pendiente de diseño e implementación futura; las rutas actuales deberían protegerse cuando se desarrolle la funcionalidad.

---

## 25. Fidelización

Existe infraestructura de fidelización en desarrollo activo.

Elementos conocidos:

* `LoyaltySetting`
* `LoyaltyAccount`
* `LoyaltyMovement`
* `LoyaltyMovementLine`
* servicios en `app/Services/Loyalty`

La fidelización contempla:

* acumulación de puntos;
* valor monetario del punto;
* canje;
* mínimo de canje;
* movimientos;
* Kardex;
* integración con POS.

Reglas importantes conocidas:

* precisión decimal;
* no usar floats;
* idempotencia;
* ámbito por empresa;
* puntos globales entre sucursales de una misma empresa;
* `branch_id` identifica el origen;
* actualización de última compra calificadora;
* reglas sobre ofertas;
* cálculo basado en monto elegible.

Consultar `docs/PROGRESO.md` para conocer la fase exacta implementada.

---

## 26. Precisión monetaria

MVS Commerce maneja operaciones monetarias críticas.

Reglas:

* evitar `float`;
* usar tipos decimales;
* preservar precisión;
* centralizar conversiones;
* definir redondeo explícitamente;
* probar valores límite.

Se utilizan campos monetarios de alta precisión, incluyendo `DECIMAL(19,4)` en áreas relevantes.

---

## 27. Servicios

La lógica empresarial compleja debe preferir servicios especializados.

Patrón utilizado:

`app/Services/<Modulo>/`

Ejemplos:

* ventas;
* caja;
* fidelización;
* inventario.

Los servicios deben:

* concentrar reglas reutilizables;
* evitar duplicación;
* facilitar pruebas;
* mantener control transaccional cuando corresponda.

---

## 28. Requests y validación

Las validaciones HTTP deben preferir clases Request dedicadas cuando el módulo ya utilice este patrón.

Ubicación:

`app/Http/Requests`

Evitar trasladar validaciones complejas directamente a controladores si existe una convención de Requests.

---

## 29. Pruebas

Las pruebas de integración y negocio utilizan principalmente:

`tests/Feature`

Toda implementación relevante debe agregar o actualizar pruebas.

Antes de declarar una tarea terminada:

* ejecutar pruebas específicas;
* ejecutar regresión razonable del módulo;
* revisar errores de Blade cuando aplique;
* revisar frontend cuando aplique;
* ejecutar `git diff --check`.

---

## 30. Git

Rama principal de trabajo conocida actualmente:

`feature/pos`

El proyecto utiliza GitHub como respaldo remoto.

Reglas:

* revisar `git status` antes de trabajar;
* preservar cambios previos;
* evitar mezclar trabajos no relacionados;
* hacer commits claros;
* hacer push después de puntos estables;
* no forzar historial.

---

## 31. Comercio electrónico

Existe una meta estratégica de conectar MVS Commerce con comercio electrónico.

Caso importante:

MVS Commerce debe poder alimentar una tienda en línea con:

* producto;
* nombre;
* descripción;
* precio;
* inventario;
* imágenes;
* especificaciones.

El inventario debe sincronizarse según origen.

Ejemplo conceptual:

* venta en sucursal → rebaja sucursal;
* venta en bodega → rebaja bodega;
* venta online → rebaja inventario del origen configurado;
* sincronización sin duplicar movimientos.

La integración todavía debe diseñarse formalmente antes de implementarse.

---

## 32. Inteligencia artificial

La arquitectura futura debe permitir agentes de IA.

Posibles agentes:

* desarrollo;
* inventario;
* compras;
* ventas;
* clientes;
* marketing;
* fidelización;
* dirección empresarial.

Ejemplo futuro:

Datos históricos

→ análisis de rotación

→ stock actual

→ tiempo de entrega del proveedor

→ recomendación de compra

→ aprobación humana

→ orden o comunicación

La IA no debe alterar información crítica sin permisos y controles.

---

## 33. Proyectos paralelos

Existen desarrollos paralelos realizados fuera del repositorio principal.

Principalmente:

* Recursos Humanos / Planilla
* Contabilidad

Estos módulos pueden integrarse posteriormente.

Antes de desarrollar funcionalidades equivalentes dentro de MVS Commerce:

1. revisar el repositorio paralelo;
2. identificar modelos y reglas existentes;
3. definir límites;
4. evitar duplicación;
5. diseñar integración.

---

## 34. Principios arquitectónicos

Toda nueva implementación debe intentar respetar:

1. Multiempresa.
2. Multisucursal.
3. Seguridad por permisos.
4. Precisión monetaria.
5. Servicios centralizados.
6. Pruebas.
7. Idempotencia.
8. Trazabilidad.
9. Cambios pequeños.
10. Compatibilidad hacia atrás.
11. Reutilización antes que duplicación.
12. Código claro antes que soluciones innecesariamente complejas.

---

## 35. Fuente de verdad

Este archivo describe la arquitectura conocida del proyecto.

Sin embargo:

> El código actual y las pruebas tienen prioridad sobre este documento.

Si un agente detecta una diferencia importante entre esta documentación y el repositorio:

1. verificar el código;
2. verificar pruebas;
3. determinar cuál es el estado correcto;
4. actualizar la documentación correspondiente.

