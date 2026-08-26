# MVS Commerce — Cronograma Maestro de Puesta en Producción v2

**Estado:** aprobado para ejecución.  
**Objetivo:** cerrar las piezas de producción, crear MYM Beauty Center como primera empresa real con San Ramón y Liberia, migrar datos con conciliación y comenzar operación sin rehacer módulos ya funcionales.

## Reglas permanentes

- Una sola plataforma MVS Commerce multiempresa/multisucursal.
- MYM Beauty Center será una empresa; San Ramón y Liberia serán sucursales.
- No hardcodear MYM dentro del producto.
- Un agente modificando la rama a la vez.
- Codex se usa primero para bloques largos; MiMo puede relevar cuando Codex se agote.
- Un commit por fase + push + working tree limpio.
- Auditar y reutilizar antes de construir.
- No inventar plantillas de migración: usar las plantillas reales MYM aprobadas.
- No cargar datos reales hasta ensayo, conciliación y punto de corte.

## Centro de Datos

**Navegación aprobada:** `Sidebar → Centro de Datos → Importar / Exportar / Reportes`.

Reportes **NO** tendrá una entrada independiente en el sidebar.

| ID | Fase | Estado |
|---|---|---|
| D02 | Centro de Datos base | COMPLETADO |
| D03 | Caracterización Compras + blindaje Inventario | COMPLETADO |
| D04 | Productos + múltiples códigos de barras | PENDIENTE |
| D05 | Clientes + proveedores | PENDIENTE |
| D06 | Inventario inicial por sucursal | PENDIENTE |
| D07 | Saldos abiertos CxC/CxP | PENDIENTE |
| D08 | Saldo inicial Fidelización | PENDIENTE |
| D09 | Exportadores esenciales | COMPLETADO |
| D10 | Centro de Reportes esenciales | COMPLETADO |
| D11 | Ventas históricas | OPCIONAL |
| D12 | Gastos/caja histórica | OPCIONAL |
| D13 | Migración real MYM | PENDIENTE |
| D14 | Auditoría post-migración + piloto | PENDIENTE |

## Preparación para producción

### P00 — Login profesional MVS Commerce
Rediseñar el login azul actual con identidad MVS Commerce moderna, limpia y responsive. Preservar autenticación y seguridad.

### P01 — Panel Maestro MVS / Superadmin
Dashboard privado del propietario de MVS para administrar empresas, sucursales, usuarios, estado, módulos y configuración. Aislamiento multiempresa estricto.

### P02 — Módulos por empresa
Permitir activar/desactivar módulos contratados por empresa. Separar módulos contratados de permisos de usuarios.

### P03 — Onboarding nueva empresa + sucursales
Asistente para datos de empresa, logo, información fiscal/comercial, sucursales, administrador, módulos y configuración inicial.

### P04 — Impresión POS y comprobantes — COMPLETADO
Auditar primero lo existente. Soportar:
- térmica 80 mm;
- térmica 58 mm;
- factura/comprobante grande;
- PDF;
- reimpresión desde historial;
- configuración por sucursal;
- impresión directa cuando el navegador/entorno lo permita.

La fotografía entregada por el usuario es **solo referencia conceptual**. No copiar el diseño ni sus datos. El diseño final debe ser MVS Commerce. El **TOTAL debe verse grande y en negrita**. No incluir elementos manuscritos o accidentales de la foto.

Implementado con un comprobante MVS único y reutilizable en 80 mm, 58 mm y carta, descarga PDF, reimpresión desde historial, formato/autoimpresión configurables por sucursal y autorización por empresa, sucursal, módulo y permiso. Evidencia: `SaleReceiptProductionTest` (5 tests, 33 aserciones), regresión relacionada (29 tests, 172 aserciones) y build Vite correctos.

### P05 — Correo de comprobantes — COMPLETADO
Enviar comprobante desde la venta y reenviar desde historial. Auditar y reutilizar PDF/correo existente antes de construir.

Implementado reutilizando el comprobante/PDF P04 y la infraestructura Laravel Mail existente. Permite envío desde el comprobante y reenvío desde el detalle histórico, valida destinatario y conserva la venta confirmada ante fallos de transporte. Evidencia: `SaleReceiptMailTest` (4 tests) y regresión de correo/caja/ventas (50 tests, 238 aserciones).

### P06 — Fidelización en todos los comprobantes
Cuando exista cliente y aplique, mostrar en térmica y factura grande/PDF:
- puntos ganados;
- puntos utilizados;
- saldo anterior;
- saldo actual.

Consumir el resultado real del módulo de fidelización; no recalcular puntos en la vista.

**Estado:** SIGUIENTE.

### P07A — Portal de Clientes — experiencia del cliente

#### Regla de alcance
El portal pertenece a la **empresa (`company_id`)**, nunca a una sucursal.

Ejemplo:
- Empresa: MYM Beauty Center.
- Sucursales: San Ramón y Liberia.
- Un cliente que compra en ambas sucursales usa **un solo portal MYM** y ve un **saldo consolidado**.
- Cada movimiento conserva/enseña su sucursal de origen.
- Si el mismo cliente compra en otra empresa MVS, sus datos y puntos son totalmente separados.

#### Acceso
- Usuario + contraseña.
- Recuperación segura.
- Cada cliente solo ve su información.

#### MVP del portal
- Saldo actual de puntos.
- Valor aproximado en dinero cuando aplique.
- Cuánto falta para alcanzar el mínimo de canje.
- Puntos ganados/utilizados.
- Movimientos con sucursal de origen.
- Premios/beneficios disponibles.
- Historial de compras de todas las sucursales de la empresa.
- Comprobantes: ver/descargar PDF y reenviar cuando P04/P05 lo permitan.
- Perfil básico y preferencias.

#### Contenido para clientes
La empresa tendrá una administración muy simple para publicar:

- 🆕 Nuevos productos
- 🔥 Ofertas
- 🎁 Promociones
- 📢 Avisos

Flujo administrativo objetivo:

1. `Nueva publicación`
2. Elegir tipo.
3. Buscar/seleccionar producto existente opcionalmente.
4. Subir imagen o reutilizar imagen del producto.
5. Mensaje.
6. Vigencia desde/hasta.
7. `Publicar`.

Si se selecciona un producto real de MVS, reutilizar:
- nombre;
- imagen;
- precio;
- `special_price`/oferta cuando corresponda.

No duplicar el catálogo.

Puede existir una opción simple para mostrar automáticamente ofertas activas del catálogo.

#### Vigencia y urgencia de puntos
- Mostrar fecha exacta de vencimiento y tiempo restante (por ejemplo, días restantes).
- Avisar visualmente cuando el vencimiento esté próximo.
- Explicar cuándo una compra renueva/extiende la vigencia.
- Consumir únicamente la regla real del módulo de Fidelización; el portal no crea ni recalcula una política distinta.
- Después de una compra, reflejar automáticamente la nueva fecha/tiempo restante cuando la configuración real determine renovación.

#### Acciones comerciales configurables
Cada empresa puede configurar uno o varios CTA del portal:
- Comprar ahora.
- Ver tienda.
- Ver catálogo.
- WhatsApp.

El destino puede ser:
- web/e-commerce de la empresa;
- página específica de compra;
- catálogo de WhatsApp;
- enlace directo de WhatsApp;
- otro enlace comercial válido.

Para MYM podrá configurarse `mymenlinea.com`, pero **nunca debe quedar hardcodeado**: cada empresa define sus propios enlaces.

#### “Te falta poco”
Cuando los datos reales lo permitan, mostrar cuánto le falta al cliente para:
- alcanzar el mínimo de canje;
- obtener un premio/beneficio.

#### “Para ti”
V1 puede recomendar contenido usando categorías o compras anteriores del cliente, sin IA compleja.

#### Fuera del MVP P07
- Carrito/compra online completa.
- Push notifications avanzadas.
- IA/recomendaciones complejas.
- Wallet.
- Campañas/segmentación avanzada.


### P07B — Gestión de Portal de Clientes

**Ubicación obligatoria:** `Sidebar → Fidelización → Portal de Clientes`.

Esta administración es interna de cada empresa. **NO pertenece al Panel Maestro MVS / Superadmin.**

Dentro de `Portal de Clientes` la empresa tendrá una interfaz simple con:
- Dashboard/Resumen.
- Publicaciones.
- Productos destacados.
- Enlaces y botones.
- Configuración.
- Vista previa.

Funciones:
- crear, editar, eliminar y programar publicaciones;
- tipos: Nuevo producto, Oferta, Promoción y Aviso;
- seleccionar un producto existente opcionalmente;
- reutilizar nombre, imagen, precio y `special_price`;
- subir imagen propia cuando corresponda;
- definir mensaje y vigencia;
- activar/desactivar;
- ordenar o destacar;
- previsualizar;
- habilitar ofertas automáticas cuando aplique;
- configurar uno o varios CTA por empresa: tienda/web, página de compra, catálogo de WhatsApp, WhatsApp u otro enlace válido.

#### Permisos granulares
La empresa controla quién puede administrar el portal. Separar al menos:
- administrar configuración del portal;
- gestionar/publicar promociones y contenido;
- gestionar enlaces/botones;
- ver/vista previa.

Un **Cajero** o **Vendedor** puede recibir permiso para crear/publicar promociones sin recibir acceso a toda la configuración o a los enlaces. No asumir este permiso por defecto: lo asigna el administrador de la empresa.

Para este alcance se reutiliza el sistema de usuarios + roles + permisos existente. **No crear un módulo separado de Vendedores** únicamente para P07B.

### P08 — Dominio, servidor provisional y backups
Preparar acceso estable para San Ramón y Liberia. PC de San Ramón puede ser servidor provisional con HTTPS estable, servicios de arranque, sin suspensión, backups automáticos y recuperación probada. Diseñar para migrar posteriormente a VPS.

### P09 — Primera empresa real: MYM Beauty Center
Crear MYM mediante el mismo mecanismo del producto:
- Empresa: MYM Beauty Center.
- Sucursal: San Ramón.
- Sucursal: Liberia.
- Usuarios, permisos, módulos, cajas, métodos de pago y configuración.

### P10 — Ensayo de migración + piloto productivo
Primero ensayo con copia; después corte final. Prioridad de datos:
1. productos y códigos;
2. clientes;
3. proveedores;
4. inventario San Ramón;
5. inventario Liberia;
6. CxC/CxP abiertas;
7. puntos vigentes;
8. usuarios/configuración.

Históricos D11/D12 no bloquean la salida salvo decisión expresa.

## V0.2 — Wallet
Evaluar Apple Wallet y Samsung Wallet/equivalente después de estabilizar portal, QR y fidelización. No bloquea el MVP.

## Orden de ejecución recomendado

1. P00 → P01 → P02 → P03.
2. P04 → P05 → P06 → P07A → P07B.
3. D04 → D05 → D06 → D07 → D08 usando plantillas MYM aprobadas.
4. P08 → P09.
5. P10 junto con D13 → D14.
6. D11/D12 y Wallet V0.2 después del piloto, salvo necesidad operativa aprobada.

## Criterio final de salida

MVS Commerce se considera listo para piloto MYM cuando:
- login/onboarding/superadmin están operativos;
- MYM y sus dos sucursales están correctamente aisladas;
- POS imprime/reimprime y genera comprobantes;
- correo funciona de forma controlada;
- fidelización aparece correctamente en comprobantes;
- Portal de Clientes protege los datos y consolida por empresa;
- la empresa administra publicaciones, promociones y enlaces desde Fidelización con permisos granulares;
- contenido del portal se publica fácilmente desde MVS;
- importadores necesarios están probados;
- inventario, saldos y puntos concilian;
- acceso remoto y backups están probados;
- una venta end-to-end valida caja → inventario → cliente → fidelización → comprobante.
