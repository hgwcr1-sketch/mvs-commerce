# MVS Commerce — Cronograma de Producción (Portal de Clientes + Correcciones + Migración)

**Fuente oficial del orden P01–P50:** este archivo.
**Referencia visual única:** `docs/Cronograma_Unico_Portal_Correcciones_MVS_Commerce_28-08-2026.xlsx` (7 hojas: Cronograma Maestro, Portal Detalle, Migración Completa, Fidelización Pendiente, Decisiones, Reconciliación, Resumen).

> Este cronograma reemplaza los Excel de seguimiento anteriores (`docs/produccion/Cronograma_Maestro_Puesta_Produccion_MVS_Commerce_v7.xlsx`, `docs/Cronograma_Correcciones_Produccion_MVS_Commerce_29-08-2026.xlsx` y `docs/Cronograma_Maestro_Fidelizacion_MVS_Commerce_Actualizado_23-08-2026.xlsx` para el bloque general). Ningún agente puede usar cronogramas Excel anteriores ni renumerar tareas.

> **Git + código + tests = evidencia.** El orden lo determina este archivo; la implementación real la determina el repositorio.

---

## Regla de interpretación

- **CRONOGRAMA_PRODUCCION.md (este archivo)** = orden oficial P01–P50.
- **Excel único** = referencia visual consolidada (Portal P01–P20 primero; luego Correcciones P21–P30, Migración P31–P40, Fidelización pendiente P41–P48 y Cierre P49–P50).
- **Git + código + tests** = qué está realmente implementado.
- **ESTADO_ACTUAL.md / PROGRESO.md** = relevo.

Si cronograma y código difieren: registrar discrepancia, no saltar bloques silenciosamente.

---

## Tabla maestra P01–P50 (consolidada del Excel único)

### Bloque Portal de Clientes — P01–P20 (prioridad inmediata)

| ID | Trabajo | Estado | Evidencia / resultado |
|---|---|---|---|
| **P01** | Registrarme / Crear mi cuenta en login público | **COMPLETADO** | `resources/views/loyalty/portal/login.blade.php:14`, `register.blade.php`, `LoyaltyPortalSelfRegistrationTest::test_login_shows_register_link` |
| **P02** | Autorregistro por link/QR asociado a empresa correcta | **COMPLETADO** | `LoyaltyPortalSessionController::register`, `routes/web.php:139`, aislamiento `company_id`, `10/10 tests` |
| **P03** | Deduplicación por identificación, teléfono normalizado y correo; bloquear conflictos entre clientes distintos | **COMPLETADO** | `LoyaltyPortalSessionController` `uniqueIds>1` → `identification: Los datos...`, `PhoneNumberService`, `10/10 tests, 46 aserciones` (incl. conflictos) |
| **P04** | Cliente nuevo visible inmediatamente en Clientes y búsqueda POS | **COMPLETADO – evidencia actual** | `PosController::searchCustomers` LIKE, `LoyaltyPortalSelfRegistrationTest` `posCustomerVisible` por nombre y `8888` |
| **P05** | Crear/activar automáticamente cuenta de fidelización al autorregistrarse | **COMPLETADO** | `LoyaltyPortalSessionController::register` `LoyaltyAccountService::getOrCreateAccount`, `LoyaltyPortalSelfRegistrationTest::test_register_creates_loyalty_account_automatically` 11/11, 52 aserciones |
| **P06** | Crear acceso al Portal al registrar/crear cliente rápido (POS/Clientes) | **COMPLETADO** | `CustomerController::createPortalAccessForCustomer`, `PosController::createPortalAccessForQuickCustomer`, `StoreCustomerRequest`/`QuickStoreCustomerRequest` `create_portal_access`, `clientes/_form.blade.php` + `pos/index.blade.php` checkbox, `LoyaltyPortalClientAccessTest` 11/11 |
| **P07** | Contraseña temporal única, mostrada una vez, con cambio obligatorio en primer ingreso | **COMPLETADO** | `LoyaltyPortalCredential.must_change_password` (`2026_08_29_000001`), `LoyaltyPortalSessionController::forceChange` + `must_change_password` en `login`/`home`, `force-change.blade.php`, `LoyaltyPortalClientAccessTest::test_temporary_password_requires_change_on_first_login` 11/11, 55 aserciones |
| **P08** | Mostrar URL, usuario, Copiar acceso, WhatsApp y QR al cajero | **COMPLETADO** | `LoyaltyPortalDeliveryService::build` (`portal_url` `route('loyalty.customer.login', $company)` + `whatsapp_url` `PhoneNumberService::forWhatsApp` + `copy_text`), `CustomerController::store` flash `portal_access` + `PosController::storeQuickCustomer` JSON `portal_access`, `clientes/index.blade.php` + `pos/index.blade.php` `quickCustomer.delivery` responsive (Copiar/WhatsApp), `LoyaltyPortalDeliveryTest` 7/7 |
| **P09** | Pantalla central con URL general, QR, copiar y vista previa (Fidelización → Portal de Clientes) | **COMPLETADO** | `LoyaltyPortalManagementController::index` `portalUrl` `route('loyalty.customer.login', $company)` + `portalQr` `LoyaltyPortalAccessService::qrSvg`, `loyalty/portal-management/index.blade.php` `acceso-general` (URL, Copiar URL, Vista previa, QR + Imprimir), `LoyaltyPortalCentralTest` 4/4. Ajuste visual QR compacto: commit `58aba11`. |
| **P09A** | Código público único del cliente sin exponer ID interno/cédula/teléfono | **COMPLETADO** | `customers.public_code` (`2026_08_29_000002`, unique `company_id+public_code`), `Customer` `booted` + `CustomerPublicCodeService` (8 chars A-Z0-9, CSPRNG, no leak cédula/teléfono), `CustomerPublicCodeTest` 5/5 |
| **P09B** | QR individual + Code 128 generados por MVS | **COMPLETADO** | `CustomerPublicCodeService::qrSvg` (chillerlan H) + `barcodeSvg` (picqer Code128 SVG) local, `clientes/show.blade.php` `Identificación pública` (código+QR+Code128+Copia/Imprimir), `CustomerQrBarcodeTest` 4/4 |
| **P09C** | Escaneo QR/Code128 para seleccionar cliente en POS | **COMPLETADO** | `PosController::searchCustomers` `public_code` (like + exact priority), `pos/index.blade.php` botón escáner cliente + `onMvsScan` (public_code 6-12 → `pos.customers.search` exact → `selectCustomer`), `CustomerPosScanTest` 3/3 |
| **P09D** | PIN o QR temporal/de un solo uso para canjes y autorizaciones sensibles | **COMPLETADO** | `customer_one_time_tokens` (`2026_08_29_000003`, token_hash SHA256, expires 5min, used_at), `CustomerOneTimeTokenService` (PIN 6 dígitos, QR local, single-use, expiración, `isStaticQrTrustedForRedeem=false`), `clientes/show` PIN+QR+verificar, `CustomerOneTimeTokenTest` 4/4 |
| **P10** | Patrón UI reutilizable de pestañas MVS (responsive, mobile-first, touch ≥44px, sin overflow horizontal, scroll horizontal controlado en móvil) | **COMPLETADO** | `b383aad`; componente `x-tabs`, Alpine, estilos y carga Vite |
| **P11** | Portal de Clientes por pestañas y menos scroll | **COMPLETADO** | 7 secciones tabuladas según permisos, un panel visible, teclado, QR 160–200px; `LoyaltyPortalCentralTest` 5/5, 40 aserciones + build Vite |
| **P12** | Clientes + Configuración en pestañas | **COMPLETADO** | Cliente: Información, Identificación/seguridad, Contactos/direcciones. Configuración: Fidelización, WhatsApp, Plantillas según permiso. 23 tests, 167 aserciones + build Vite |
| **P13** | Extensión patrón pestañas al resto de MVS donde corresponda | **COMPLETADO** | Auditoría selectiva: tabs en detalle complejo de Roles (Resumen/Usuarios/Permisos); POS, transacciones, formularios únicos y detalles simples excluidos. 15 tests, 59 aserciones + build Vite |
| **P14** | Incentivo de registro configurable (habilitar/deshabilitar) | **COMPLETADO** | Configuración única por `company_id`, control en Centro de reglas, concesión base idempotente de 10 puntos vía `LoyaltyAccountService`/`new_customer`, claim enlazado al movimiento de Kardex; `LoyaltyRegistrationIncentiveP14Test` 8/8 |
| **P15** | Beneficio: puntos, % descuento o descuento fijo | **COMPLETADO** | Tipo y valor `DECIMAL(19,4)` por empresa; puntos al Kardex, descuentos como claim pendiente sin adelantar P16; `LoyaltyRegistrationIncentiveP15Test` 13/13 |
| P16 | Compra mínima, primera compra/después, excepción mínimo de canje, vencimiento | **PENDIENTE** | Reglas comerciales |
| P17 | Sucursales, ofertas, descuento máximo y stacking/combinabilidad | **PENDIENTE** | Condiciones por empresa |
| P18 | Una vez por cliente + teléfono/correo verificado | **PENDIENTE** | Prevención abuso |
| P19 | Auditar incentivo: cliente, regla, beneficio, compra, sucursal, configurador y fecha | **PENDIENTE** | Trazabilidad |
| P20 | Logo, nombre y colores de empresa en portal/QR manteniendo identidad MVS | **PENDIENTE** | Responsive |

### Bloque Base SaaS y Onboarding — P22–P23 (completados previamente, sin alterar secuencia actual)

| ID | Trabajo | Estado | Evidencia |
|---|---|---|---|
| **P22** | Separación Platform Admin / Tenant Admin | **COMPLETADO en a60425f** | `a60425f feat: completar onboarding de empresa y separacion platform tenant`, `ManagePlatformAdmin.php`, `LoginController`, `BranchController`, `PlatformAdminTest` |
| **P23** | Empresa + primera sucursal + primer administrador | **COMPLETADO en a60425f** | `a60425f feat: completar onboarding de empresa y separacion platform tenant`, `CompanyProvisioner`, `CompanyController`, `EnsureActiveCompany`, `BranchController` |

### Bloque Correcciones — P23–P30

| ID | Trabajo | Estado | Origen |
|---|---|---|---|
| P23 | Auditar implementación existente de transferencias; no reconstruir | **PENDIENTE** | Corrección |
| P24 | Probar origen/destino, stock, Kardex, permisos y decidir instantánea vs envío/recepción | **PENDIENTE** | Corrección |
| P25 | Reparar/terminar sidebar responsive | **PENDIENTE** | Corrección |
| P26 | Nombres claros 58 mm, 80 mm, Carta, etc. | **PENDIENTE** | Corrección |
| P27 | Crear empresa/sucursal MVS Commerce Demo con datos ficticios | **PENDIENTE** | Demo |
| P28 | Usuario administrativo demo + cliente Portal demo + QR demo | **PENDIENTE** | Demo |
| P29 | Bloquear operaciones peligrosas y comunicaciones reales | **PENDIENTE** | Demo |
| P30 | Reset automático de datos ficticios | **PENDIENTE** | Demo |

### Bloque Migración Completa — P31–P40 (después del bloque actual, sin pisar IDs)

| ID | Trabajo | Estado | Criterio clave |
|---|---|---|---|
| P31 | Auditar importadores/exportadores/plantillas existentes | **PENDIENTE** | No duplicar funcionalidad existente |
| P32 | Importar clientes desde Excel con deduplicación | **PENDIENTE** | Cliente disponible para historial y POS |
| P33 | Importar productos, códigos, precios y catálogo; inventario opcional | **PENDIENTE** | Errores por fila y plantilla |
| P34 | Importar encabezados de facturas: fecha, cliente, sucursal, totales y origen | **PENDIENTE** | Histórico sin afectar caja |
| P35 | Importar detalle por artículo + cliente + sucursal + fecha | **PENDIENTE** | Última compra por cliente |
| P36 | Importar inventario inicial y Kardex histórico por sucursal | **PENDIENTE** | Modo histórico no altera stock actual |
| P37 | Importar saldos y movimientos históricos de puntos | **PENDIENTE** | Conciliación por cliente |
| P38 | Exportar paquete completo: clientes, productos, facturas, líneas, Kardex y puntos | **PENDIENTE** | Migración futura |
| P39 | Vista previa, conciliación, errores descargables, reintento y rollback | **PENDIENTE** | Ensayo sin duplicados |
| P40 | Ensayo de migración completa + conciliación final | **PENDIENTE** | Última compra, ventas, Kardex y saldos coinciden |

### Bloque Fidelización Pendiente — P41–P50 (solo si siguen pendientes según código/tests)

> El maestro de Fidelización `23-08-2026` está desactualizado. **Solo los pendientes reales** pasan a este bloque, previa auditoría contra código/tests (no reconstruir lo ya hecho).

| ID | Referencia | Estado | Tratamiento |
|---|---|---|---|
| P41 | Auditoría general fidelización | **PENDIENTE** | Auditar todo el maestro antiguo contra código actual |
| P42 | Premios stock/disponibilidad/historial (F19–F21) | **PENDIENTE DE AUDITORÍA** | F19 CRUD ya completado; implementar solo brechas (stock, canje, historial) |
| P43 | Vencimiento configurable + automático (F22–F23) | **PENDIENTE DE AUDITORÍA** | Confirmar si ya existe |
| P44 | Centro de Reglas + ajustes manuales (F24–F25) | **PENDIENTE DE AUDITORÍA** | No duplicar Centro de Reglas existente; P14–P19 de Portal ya cubren incentivo |
| P45 | Saldo global y canje entre sucursales (F26–F27) | **PENDIENTE DE AUDITORÍA** | Aislamiento empresa, saldo compartido |
| P46 | Fidelización online acumulación/canje (F36–F37) | **PENDIENTE DE AUDITORÍA** | Auditar integración existente |
| P47 | Permisos administrador/cajero/portal (F38–F39) | **PENDIENTE DE AUDITORÍA** | 403/permitido correcto |
| P48 | Dashboard indicadores empresa/sucursal (F40–F41) | **PENDIENTE DE AUDITORÍA** | Clientes, generados, canjeados, vencidos, saldo |
| P49 | Regresión completa Portal + Fidelización + POS + Inventario + migraciones | **PENDIENTE** | Suite verde; documentar fallos históricos |
| P50 | Instalación limpia, colas/scheduler, despliegue, prueba de humo y rollback | **PENDIENTE** | Producción repetible |

**F01–F45 del maestro de Fidelización:** F01–F18, F28, F29 ya **COMPLETADOS previamente** y no se reconstruyen; F30–F35 parcialmente en Portal P01–P20; el resto queda en P41–P48 solo si la auditoría confirma que sigue pendiente.

---

## Estado y próxima fase

- **P01–P09D: COMPLETADOS** (evidencia `LoyaltyPortalSelfRegistrationTest` 11/11 + `LoyaltyPortalClientAccessTest` 11/11 + `LoyaltyPortalDeliveryTest` 7/7 + `LoyaltyPortalCentralTest` 4/4 + `CustomerPublicCodeTest` 5/5 + `CustomerQrBarcodeTest` 4/4 + `CustomerPosScanTest` 3/3 + `CustomerOneTimeTokenTest` 4/4; P09D PIN temporal single-use). Ajuste visual QR compacto P09: commit `58aba11`.
- **P10: COMPLETADO** – Patrón UI reutilizable `x-tabs` (`b383aad`).
- **P11: COMPLETADO** – Portal de Clientes organizado en siete pestañas según permisos, accesible por teclado, QR compacto con impresión independiente.
- **P12: COMPLETADO** – Detalle de Cliente y Configuración tabulados selectivamente; lista simple sin pestañas.
- **P13: COMPLETADO** – Extensión selectiva al detalle de Roles; POS y pantallas no aptas preservadas.
- **P14: COMPLETADO** – Habilitar/deshabilitar por empresa, concesión única por cliente y auditoría básica enlazada al Kardex existente. Regresión Portal/P14: 78 pruebas, 500 aserciones.
- **P15: COMPLETADO** – Tipo y valor estrictamente validados por empresa; puntos inmediatos vía Kardex y descuentos concedidos como claim pendiente. Regresión P14/Portal/P15: 91 pruebas, 552 aserciones.
- **P16: SIGUIENTE BLOQUE** – Compra mínima, momento de aplicación, excepción del mínimo de canje y vencimiento.
- **P22 y P23: COMPLETADOS en a60425f** – P22 Separación Platform/Tenant y P23 Onboarding empresa + sucursales + primer administrador.
- **P09A–P09D: COMPLETADOS** – conservan esos IDs exactos.
- **Migración P31–P40:** después de los bloques existentes, sin reutilizar IDs.
- **Fidelización:** solo pendientes reales P41–P48 tras auditoría (no reconstruir F01–F18, F28, F29 ya completados).

---

## Regla para agentes

- **Fuente oficial:** `docs/CRONOGRAMA_PRODUCCION.md` (este archivo). 
- **Referencia visual única:** `docs/Cronograma_Unico_Portal_Correcciones_MVS_Commerce_28-08-2026.xlsx`. 
- **Prohibido** usar cronogramas Excel anteriores (`Cronograma_Maestro_Puesta_Produccion_MVS_Commerce_v7.xlsx`, `Cronograma_Correcciones_Produccion_MVS_Commerce_29-08-2026.xlsx`, `Cronograma_Maestro_Fidelizacion_23-08-2026.xlsx`) como fuente de orden, y prohibido renumerar tareas.

Antes de cualquier tarea: leer este archivo + `docs/ESTADO_ACTUAL.md` + sección `docs/PROGRESO.md`; no saltar bloques.

---

## Decisiones oficiales (del Excel único, hoja Decisiones)

- D01: Primero cerrar Portal P01–P20; luego correcciones/migración/fidelización pendiente.
- D02: Este archivo reemplaza los Excel anteriores de seguimiento general.
- D03: Este cronograma debe leerse antes de trabajar.
- D04: Cliente Portal no es usuario empleado.
- D05: QR/link siempre ligado a empresa correcta.
- D06: Conflictos entre clientes distintos bloquean; no fusionar.
- D07: Contraseña temporal única; no genérica compartida.
- D08: QR/barcode los genera MVS.
- D09: Canjes sensibles usan PIN o QR temporal.
- D10: Toda importación crítica tendrá exportación equivalente.
- D11: Histórico no altera caja/stock actual.
- D12: Detalle histórico debe permitir saber qué compró, cuándo, sucursal y última compra.
- D13: Maestro viejo 23-08 solo como referencia auditada.

---

## Control de cambios

- 2026-08-29 (mañana): creado como fuente oficial P01–P30 (basado en correcciones 29-08), marca P22/P23 en a60425f.
- 2026-08-29 (tarde): **reconciliado con Excel único `Cronograma_Unico_Portal_Correcciones_MVS_Commerce_28-08-2026.xlsx`** – amplia a P01–P50, incorpora P31–P40 migración y P41–P50 fidelización pendiente, sin pisar IDs ni reutilizar numeraciones. P01–P04 se mantienen COMPLETADOS, P05 sigue como siguiente. P09A–P09D y P22/P23 se conservan con IDs exactos.
- 2026-08-29 (noche): **sincronizado P09–P14 con Excel único y estado real del repositorio** – P09 ajuste QR compacto commit `58aba11` documentado; P10–P13 añadidos como bloques siguientes en orden (P10 patrón pestañas, P11 Portal por pestañas, P12 Clientes+Configuración, P13 extensión transversal); P14 marcado PAUSADO/PARCIAL con código parcial preservado; regla de producción añadida (desarrollo → validación local → APROBADO PARA PRODUCCIÓN → despliegue controlado); regla P10–P13 responsive/touch/overflow añadida.
- 2026-08-29 (noche): **P10 y P11 completados** – P10 publicado en `b383aad`; P11 aplica el patrón al Portal con panel único, permisos existentes, teclado y QR compacto. P12 queda siguiente.
- 2026-08-29 (noche): **P14 completado retomando el trabajo parcial** – configuración por empresa, activación/desactivación, concesión única, `LoyaltyAccountService`/Kardex y claim auditable; P15 queda siguiente sin implementar sus reglas comerciales.
- 2026-08-29 (noche): **P15 completado** – beneficio configurable como puntos, porcentaje o descuento fijo con valor decimal, validación doble HTTP/servicio, aislamiento e idempotencia; descuentos quedan pendientes hasta P16.
