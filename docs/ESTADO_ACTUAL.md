# MVS Commerce — Estado actual

## Operaciones no fiscales con perfil pendiente (2026-10-02)

**Rama:** `feat/cabys-regimen-prod-3419e9d`, base `3419e9d`. Cambio local; sin push ni producción.
- Compras, recepción e importación de compra ya no exigen clasificación fiscal de línea para registrar costo/impuesto operativo. Las líneas documentales conservan su evidencia cuando está disponible; productos nuevos desde compras quedan pendientes salvo selección explícita en formulario.
- POS ordinario, cotizaciones/conversión y apartados aceptan productos pendientes, calculan con la tasa operativa y no crean snapshots/impuestos fiscales inventados. La entrega de apartado y reserva de inventario continúan normalmente.
- CABYS confirmado puede actualizar la tasa operativa/evidencia oficial, pero nunca asigna `fiscal_profile_id`. La validación estricta de FE/TE se conserva en `FiscalPreflightService`; POS pendiente sigue rechazando esas emisiones antes de persistir.
- Auditoría: ajustes, traslados, devoluciones/movimientos internos y las importaciones masivas/CRUD de productos no tenían bloqueo por perfil pendiente; no se alteraron.
- Pruebas focales: **81 tests / 791 aserciones PASS** en Compras/XML/importación, creación de producto, inventario, POS/emisión, cotización, apartado, productos/CABYS; `git diff --check` PASS.

## Integración FE: perfil pendiente y reconciliación CABYS (2026-09-30)

**Rama:** `integration/prod-facturacion-electronica`. Validación local; sin producción, SSH, HTTP fiscal ni deploy.
- Productos: el perfil pendiente permanece NULL; alta/edición e importación no lo infieren de `tax_rate`. La confirmación explícita sincroniza la tasa operativa; omitir el campo al editar conserva un perfil ya confirmado.
- POS normal: calcula con la tasa operativa si no hay perfil, sin inventar snapshot ni filas fiscales. FE/TE: preflight local bloquea antes de persistir si faltan perfil, CABYS, unidad o emisor; FE exige receptor identificado. El mapper rechaza líneas históricas sin datos fiscales explícitos, sin inferir del porcentaje.
- Migraciones: BD SQLite limpia y upgrade local desde el commit base `8014283` convergen en tablas/índices/FK de CABYS y perfiles. Se conservaron IDs y contenido de versión/entrada CABYS de prueba; `PRAGMA foreign_key_check` vacío y segunda migración idempotente. No certifica ejecución sobre una BD productiva real.
- Validación: 52 tests focales únicos (productos, POS fiscal, emisión con fake, mapper, servicio fiscal, importaciones y reconciliación); bloques secuenciales menores de 30 segundos. No se ejecutó `PosSuspendedSalesTest` completo.
- Se preservan los untracked preexistentes. No implica aprobación para despliegue.

Documento corto de relevo entre agentes. Actualizar al terminar cada tarea importante.

## Códigos de actividad con punto decimal + timeout vs "sin actividades" (2026-09-28)

**Worktree:** `mvs-prod-integration`, rama `fix/prod-fiscal-capabilities`. **Sin producción.**

- **Causa raíz de "0 actividades":** la fuente oficial entrega `actividades[].codigo` como **texto y con punto decimal** (ej. `0144.0`, `0141.1`, `960113`). El filtro `^\d{1,20}$` descartaba los decimales **en silencio**: el contribuyente tenía actividades y el sistema mostraba cero.
- **Segundo problema:** la API es intermitente (se midieron timeouts con Guzzle mientras curl respondía en 0.15 s). Con `timeout(4)` un corte transitorio se reportaba como `unavailable` ** indistinguible de "consultó y no hay"** en la UI.
- **Cambios:** el código acepta `\d{1,10}(\.\d{1,4})?` en `TaxpayerLookupService`, `CustomerTaxpayerActivityService`, `StoreCustomerRequest` y `QuickStoreCustomerRequest` (la columna `code` es `string(20)`, no hubo migración). `connectTimeout` 3 s, `timeout` 8 s y **1 reintento con backoff de 400 ms solo ante timeout/5xx** (4xx no se reintenta). SeAdded `activities_queried` y `has_activities` para separar "se consultó y no hay" de "no se pudo consultar".
- **Regla crítica aplicada:** `unavailable` **nunca** se muestra como "Hacienda no informó actividades" ni borra lo ya aplicado. Cliente y POS muestran 3 mensajes distintos (hay / no hay / no se pudo consultar) y un fallo temporal **no** persiste lista vacía ni elimina actividades existentes (el POS conserva `applied` con `keepApplied`).
- **Tests:** 6 nuevos en `HaciendaActivityFlowTest` (timeout→unavailable no vacío, retry recupera, decimal se conserva y persiste en Cliente y POS, timeout no borra lo existente, consultado-vacío correcto) y 2 casos nuevos por JS. Verde: **43 PHP (309 aserciones) + 3 JS**, `npm run build` OK, Pint PASS (5 archivos).
- **No se tocó** CABYS, MF05 ni factura electrónica.

## POS: el input del modal NUNCA llegaba al estado (causa real) (2026-09-28)

**Worktree:** `mvs-prod-integration`, rama `fix/prod-fiscal-capabilities`. **Sin producción.**

- **Síntoma real:** en el modal "Nuevo cliente" del POS se escribía `109880401` y el tipo seguía en "Seleccione…", sin nombre ni propuesta.
- **Causa raíz:** el input estaba declarado con `:value="quickCustomer.form.identification"` + `x-on:input="identificationInput()"` **sin `x-model`**. Ese binding `:value` reescribe el input con el estado en cada tecla, y el handler leía `form.identification` (el valor **viejo**, vacío) en vez del evento: `resolveType('', '')` devolvía `null`, no había tipo, no había debounce y nunca se llamaba a `/clientes/contribuyente`. La inferencia de tipo del hotfix anterior era correcta pero nunca se alcanzaba.
- **Fix:** `x-model` en el input + `identificationInput($event)` que lee `$event.target.value`. La deducción por dígitos, la máscara y el guardado usan ese valor real. Sin segundo lookup: sigue `/clientes/contribuyente`.
- **Cliente normal:** no tenía defecto de binding; la cédula probada (`109880401`) **no tiene actividades en la API real** (0 registros verificados en vivo). Para que "no aparece" nunca vuelva a confundirse con "no se muestra", `taxpayer_activities_box` **ya no arranca oculto**: siempre se ve y, sin actividades, dice explícitamente "Hacienda no informó actividades económicas para esta identificación" (`#taxpayer_activities_empty`).
- **Tests:** `tests/js/pos-modal-binding-real.cjs` afirma el marcado real del Blade (x-model, sin `:value`, `$event` en el handler) y `pos-hacienda-real.cjs` reproduce el caso exacto del usuario (`109880401`, tipo vacío, evento real) verificando inferir `01`, máscara, consulta, nombre oficial y actividades. Verde: **37 PHP + 3 JS**, `npm run build` OK, Pint PASS.
- **No se tocó** CABYS, MF05 ni factura electrónica.

## Auditoría flujo actividad económica Hacienda — SIN DEFECTO DE CÓDIGO (2026-09-28)

**Worktree:** `mvs-prod-integration`, rama `fix/prod-fiscal-capabilities` @ `dbf2d80`. **Sin producción.**

- **Forma REAL de Hacienda (verificada en vivo, solo claves):** `api.hacienda.go.cr/fe/ae` devuelve `nombre`, `tipoIdentificacion`, `regimen{codigo,descripcion}`, `situacion{moroso,omiso,estado,administracionTributaria,mensaje}` y `actividades[]{estado,tipo,codigo,descripcion}`. Sin contribuyente: HTTP 200 con `status` textual ("Information no available...") y `actividades` vacío; inexistente: HTTP 404.
- **Capa 1 servicio:** OK en vivo (local 1579 ms y **producción 289 ms**: `found`, 1 actividad `960113`, régimen y situación). El filtro `^\d{1,20}$` NO descarta códigos reales (son de 6 dígitos).
- **Capa 2 endpoint:** devuelve `status/name/type/regime/situation/activities` con `code`/`description` — los nombres exactos que lee la UI. Sin caché `hacienda*` en producción (0 filas); `CACHE_STORE=database`.
- **Capa 3 Cliente:** `_form.blade.php` renderiza los 11 hooks (`taxpayer_proposal`, `taxpayer_activities_list`, `taxpayer_activities_inputs`…), `layouts/app` carga el bundle y `x-input`/`x-select` sí generan `id="identification"` / `id="identification_type"`. El JS real, ejecutado en DOM contra el JSON real, **muestra, marca, etiqueta y envía** `taxpayer_activities[][code|description]`.
- **Capa 4 POS:** el componente Alpine real consulta el endpoint, guarda `ident.regime/situation/activities`, aplica `applied` y **aplica el nombre oficial**; `storeQuickCustomer()` envía `{code, description}`. Sin actividades no inventa ninguna.
- **Conclusión:** no hay incompatibilidad de nombres (`activities`/`code`/`description` son consistentes en las 4 capas) ni estado temporal que se limpie al aplicar. Los 31 tests previos pasaban porque solo cubrían servicios aislados. **La causa reportada NO está en el código de esta rama**: queda como hipótesis a confirmar en navegador real (cédula consultada sin actividades, o caché/JS del navegador).
- **Tests agregados (sin tocar código de aplicación):** `HaciendaActivityFlowTest` (5) ata endpoint → payload real → persistencia usando la forma real de Hacienda; `tests/js/hacienda-actividades-real.cjs` y `tests/js/pos-hacienda-real.cjs` ejecutan el JS real en DOM. Verde: 36 PHP (261 aserciones) + 2 JS. Pint PASS.
- **Anomalía preexistente (no tocada, ajena a la tarea):** `clientes/create.blade.php` tiene `<form` duplicado (líneas 31-32) desde `17dc37d`, también presente en producción.

## Causa raíz del flujo Hacienda invisible — TIPO DE IDENTIFICACIÓN VACÍO (2026-09-28)

**Worktree:** `mvs-prod-integration`, rama `fix/prod-fiscal-capabilities`. **Sin producción.** Corrige la conclusión de la auditoría anterior.

- **Síntoma real del usuario (navegador):** en `/clientes/create` y en Nuevo Cliente del POS no aparecía consulta, propuesta, nombre oficial, régimen, situación ni actividades.
- **No era deploy ni assets.** Auditoría por MD5 de los 7 archivos frente a producción (`932d432`): **idénticos**. La vista Blade compilada en producción contiene los 11 hooks, `manifest.json` es coherente, no hay `public/build/hot`, el bundle se sirve 200 (116.615 B) e `identificacion.js` está dentro de él. **Ningún archivo faltaba ni difería.**
- **Causa raíz:** `identification_type` inicia en `''` en ambos formularios (`_form.blade.php` y `resetQuickCustomer()`), y `canLookup('')` es `false` porque `LOOKUP_TYPES` solo cubre `01-04`. `schedule()` y `scheduleTaxpayerLookup()` entoncesaban la consulta y llamaban `hideTaxpayerProposal()`: sin consulta no había propuesta, ni régimen, ni situación, ni actividades. El flujo solo funcionaba si la persona **tocaba el desplegable de tipo**, algo que no ocurre al abrir el formulario.
- **Fix mínimo y único:** `inferType()`/`resolveType()` en `resources/js/modules/identificacion.js` deducen el tipo por cantidad de dígitos (9→`01`, 10→`02`, 11/12→`03`) **solo cuando el tipo está vacío**, y se reflejan en el `<select>` para que se guarde el mismo tipo que se consultó. Un tipo ya elegido por la persona **nunca** se sobreescribe. El POS reutiliza esa **misma** función: no hay segunda integración ni ruta nueva; se sigue usando `/clientes/contribuyente`.
- **Tests:** los 3 archivos previos ahora reproducen el caso real (tipo vacío) y se añadió `test_la_vista_real_expone_los_hooks_del_flujo_hacienda`, que valida el HTML renderizado de `/clientes/create`. Verde: **37 PHP (274 aserciones) + 2 JS**. `npm run build` correcto (`app-C1cOIp4W.js`), Pint PASS.
- **No se tocó** CABYS, MF05 ni factura electrónica. Producción intacta.

## Pagos mixtos de apartado desde POS (2026-09-24)

**Rama:** `feature/pos` @ `b89465d` + cambios locales sin commit. Sin migraciones ni producción.

- `PosController::storeLayaway` acepta `payments[]` además del legacy `payment_method_id`; reutiliza `LayawayService` (sin duplicar lógica: suma exacta vs prima, sin método repetido, referencia obligatoria según método, `affects_cash` exige sesión, sin crédito/puntos).
- Modal de apartado en `resources/views/pos/index.blade.php`: filas dinámicas `payments[i]` (método, monto, referencia, notas) + botón “+ Agregar medio”, validación client-side de suma exacta y referencias.
- Tests: `tests/Feature/PosLayawayMixedPaymentsTest.php` (10 casos) + contrato JS `tests/js/pos-layaway-mode.cjs` actualizado (payload ahora `payments[]`). Corrida: 71/71 PASS (PosLayaway*, PosCheckout, Layaway*, MvsPrintLayawayTicket).
- Reconciliación menor en `tests/js/pos-layaway-mode.cjs`: aserción de clases del botón “Apartar” (esperaba `bg-primary text-black` que ya no existen; fallo preexistente).
- Preexistentes sin tocar (documentados abajo): `PosAccessAndSearchTest` (Puntos futuros), `PosSuspendedSalesTest` (125 vs '125.00'), `AdministrativeDashboardTest`.

## Cierre documental B1–B7 — LISTO PARA INTEGRACIÓN A RAMA DESTINO (2026-09-23)

**Rama:** `feature/notas-credito` @ `ff1fd58`. Sin merge, push, deploy ni producción.

| Bloque | Commit | Descripción |
|--------|--------|-------------|
| B6 Ventas/NC | `3b56a89` | Fecha de anulación visible + filtro por cliente en Ventas/Devoluciones |
| B4 Productos | `a4c2be1` | Atributos en listado, filtro categoría, proveedor principal, formato costo |
| B5 Dashboard/períodos | `9eecdfb` | Selector año/personalizado, NC en movimientos, reorganización de tarjetas |
| B2 Variantes | `1241c53` + `4e046e4` | Variantes estilo/talla/color en POS, cotizaciones, devoluciones y apartados |
| B7 Carga masiva | `17b346e` | Proveedor opcional en importación de productos |
| B1 Analítico de caja | `7b4ece7` | Analítico por medio de pago en historial de caja |
| B3 Pagos mixtos apartados | `ff1fd58` | Pagos mixtos en apartados (LayawayService + vistas + tests) |

### Verificación de integración

- Integración focal revisada: **253 tests, 250 PASS, 2F + 1E**.
- Focales por bloque (corridas finales): B3 19/19, B1 7/7 + Cash 89/89, B7 31/31 — PASS.
- Sin regresiones nuevas atribuibles a B1–B7.

### 3 fallos preexistentes documentados (no causados por B1–B7)

- `PosAccessAndSearchTest::test_checkout_modal_has_responsive_permanent_summary_and_dynamic_direct_payment_flow` — espera `Puntos futuros`.
- `QuoteTest::test_quote_mode_executes_frontend_transitions_stock_rules_and_save_contract` — JS espera clases antiguas del botón `Cotizar`.
- `AdministrativeDashboardTest::test_admin_can_consult_without_cash_but_pos_and_checkout_still_require_it` — expectativa de mensaje de caja vs métodos de pago.

### Estado

**LISTO PARA INTEGRACIÓN a rama destino.** No se ejecuta merge/push/deploy sin instrucción explícita.

---

## Pulido operativo P1–P4 — pre N10 / producción (2026-09-17)

Base `feature/pos`, HEAD `7fe7e79`. Trabajo local sin commit, push ni producción.

**Cambios realizados:**
- **P1 — Scanner de cámara en productos (AUDITADO / YA EXISTÍA):** confirmado funcional en `resources/views/productos/_form.blade.php` vía `<x-scanner.mvs-scanner />`, escucha `@mvs-scan.window` y copia el código leído al campo de código de barras. Componente reutilizable en `resources/views/components/scanner/mvs-scanner.blade.php`, lógica en `resources/js/scanner/index.js`, importado en `resources/js/app.js`.
- **P2 — Producto recién agregado arriba en Compras:** en `resources/js/modules/compras.js` `addProduct` ahora inserta nuevos ítems con `unshift` (arriba) y enfoca/selecciona el input de cantidad de la primera fila; si el producto ya existe, incrementa la cantidad y enfoca la línea existente. Se agregaron `data-item-id` y `data-field="quantity"` a los inputs de cantidad en `resources/views/compras/create.blade.php` y `resources/views/compras/edit.blade.php`. Ingreso de mercadería/verificación no aplica porque sus líneas vienen prefijadas por la compra.
- **P3 — Reset Demo (CÓDIGO/SCHEDULER LOCAL VERIFICADO; PRODUCCIÓN PENDIENTE DE CERTIFICAR):** `routes/console.php` ya programa `demo:company --reset --force` a las 02:00 con `withoutOverlapping()` y `onOneServer()`. Se agregó `appendOutputTo(storage_path('logs/demo-reset.log'))` y un checklist de producción en comentario. `php artisan schedule:list` y `php artisan schedule:run` verifican que el scheduler responde; el comando no se ejecutó ahora porque `dailyAt('02:00')` aún no vence. No se corrió el reset destructivo en local. El reset nocturno de producción NO está certificado hasta comprobar el cron/scheduler del VPS.
- **P4 — Icono propio MVS en móvil:** generados `public/icons/favicon-32x32.png`, `apple-touch-icon.png`, `icon-192x192.png` e `icon-512x512.png` desde `public/images/logo-mvs.png` usando GD; creado `public/manifest.json` con identidad dorada `#D4AF37`; agregados `<link rel="icon">`, `<link rel="apple-touch-icon">`, `<link rel="manifest">`, `theme-color` y capacidad web-app en `resources/views/layouts/app.blade.php`. El favicon `.ico` genérico/ vacío de Laravel queda sin uso.

**Validación:**
- `npm run build` correcto; `git diff --check` limpio.
- `php artisan schedule:list` muestra el comando Demo programado.

**Pendiente:**
- Validación visual en navegador real (360/768/1280) para P2 y P4.
- En producción: confirmar cron del scheduler y `APP_TIMEZONE` para P3; no autorizado deploy.

## APARTADOS POS — RECIBIDO/VUELTO + MVS PRINT (2026-09-16 noche)

Implementación local terminada para pre-commit; sin commit, push, deploy ni migración en producción.

**Funcionalidad completada:**
- Migración aditiva `received_amount` / `change_amount` en `layaway_payments` (DECIMAL 19,4 nullable).
- Casts y validación backend en `LayawayService::receivedAndChange` con BCMath.
- UI de Recibido/Vuelto en POS (`resources/views/pos/index.blade.php`) y vista de apartado (`resources/views/layaways/show.blade.php`).
- Corrección del mojibake "Cambiar a cotización".
- `EscPosLayawayTicket` para comprobantes 58/80 mm con símbolo `₡`, word-wrap y sin mojibake.
- Endpoints MVS Print: `mvs.print.ticket.layaway` y `mvs.print.ticket.layaway.payment`.
- Auto-print de apartado y abono desde POS, apertura de cajón solo para efectivo, reimpresión read-only sin drawer ni mutaciones.
- Actualización de `AGENTS.md` y `docs/GUIA_VISUAL.md`.

**Pruebas ejecutadas:**
- `PosLayawayModeTest` 17/17, 167 aserciones.
- `LayawayV1Test` 7/7, 42 aserciones.
- `MvsPrintLayawayTicketTest` 11/11, 73 aserciones (nuevo).
- `MvsPrintAutoPrintTest` 32/32, 309 aserciones.
- `QuoteTest` 12/12, 159 aserciones.
- `PosCheckoutTest` 17/17, 3 aserciones reportadas por PHPUnit (focalizado junto a los anteriores).
- Suite focalizada total: **96 pruebas, 96 aprobadas, 753 aserciones, 0 fallos**.
- Regresión POS + Apartados + Cotización + MVS Print: 298 pruebas, 292 aprobadas, 2036 aserciones, 6 fallos preexistentes documentados (no atribuibles a esta tarea).
- Tests JS: `mvs-print-test.cjs` 20/20; `pos-layaway-mode.cjs` ejecutado vía `PosLayawayModeTest`.
- `npm run build` correcto; `git diff --check` limpio; Pint aplicado a archivos PHP modificados.

**Pendiente:**
- Migración `2026_09_16_000002_add_received_change_to_layaway_payments_table.php` pendiente de ejecución en producción (NO autorizada todavía).
- Prueba física con impresora térmica y cajón pendiente.

## Modo Apartado integrado al POS — auditoría pre-commit (2026-09-16)

Implementación local aprobada para pre-commit, todavía sin commit, push ni producción. El POS permite entrar a Apartado con `apartados.crear`, exige cliente y prima positiva, reserva únicamente stock real de la sucursal activa bajo `lockForUpdate`, admite precio manual sólo con `pos.cambiar_precio` y reutiliza las reglas de pago de `LayawayService` (sin crédito ni puntos). Cotización y Apartado son mutuamente exclusivos. La UI nueva usa el dorado oficial `bg-primary` (`#D4AF37`) y mantiene acciones de 44/48 px.

Idempotencia: migración nueva nullable para `client_token` UUID y `request_fingerprint` SHA-256, con UNIQUE exacto `(company_id, client_token)`; los apartados históricos permanecen válidos. Replay idéntico devuelve el mismo apartado sin repetir reserva ni prima; el mismo token con payload distinto devuelve 409; empresas distintas pueden reutilizar el token. La migración fue auditada como compatible con PostgreSQL 16, pero no se ejecutó contra PostgreSQL en esta estación porque `pdo_pgsql` no está cargado.

Integración existente verificada: el apartado creado en POS aparece en el listado, acepta abonos y entrega final; conserva el precio manual en `SaleItem`, mantiene descuento cero y no vuelve a descontar inventario al entregar. Descuentos quedan pendientes: requieren persistencia explícita de `discount_total`/`gross_total` y preservación durante entrega; cualquier payload de descuento se rechaza en esta fase.

Validación: `PosLayawayModeTest` **14/14, 134 aserciones**; `LayawayV1Test` **7/7, 42**; `QuoteTest` **12/12, 159**; PosCheckout relacionados **46/46, 408**; MVS Print PHP **70/70, 348**, JS **41/41**; Vite build y `git diff --check` correctos. Regresión POS + Quote: **198 pruebas, 193 aprobadas, 1469 aserciones**, con exactamente los cinco fallos históricos ya documentados y sin fallos nuevos. Validación visual real a 360/768/1280 y PostgreSQL ejecutado quedan pendientes; no autorizado para producción.

## Variantes (estilo / talla / color) en POS, cotizaciones, devoluciones y apartados — COMPLETADO (2026-09-23)

**Commit `1241c53`** en `feature/notas-credito`.

Se añadió la identificación clara de variantes en los flujos de venta del POS y sus derivados, sin cambiar lógica de negocio, caja, dashboard ni producción.

### Cambios principales

- `PosController::searchProducts()` incluye eager load de `style`, `size`, `color` y devuelve `style_name`, `size_name`, `color_name`.
- POS muestra variantes en: resultados de búsqueda, líneas del carrito, pedidos internos, carga de cotizaciones y recuperación de ventas suspendidas.
- `QuoteController::load()`/`show()`/`print()` cargan variantes; el payload de carga en POS las incluye.
- `ReturnController::create()` y `devoluciones/crear.blade.php` muestran variantes.
- `LayawayController::create()`/`show()` y vistas `layaways/create.blade.php`/`layaways/show.blade.php` muestran variantes.
- Helper reutilizable: `app/Support/ProductVariantFormatter.php` y partial `resources/views/partials/product-variant.blade.php`.

### Verificación

- `PosAccessAndSearchTest` (excepto fallo preexistente de modal): 33/33 ✅
- `SaleReturnTest`: 20/20 ✅
- `LayawayV1Test`: 8/8 ✅
- `DevolucionesIndexTest`: 18/18 ✅
- `QuoteTest` (excepto fallo preexistente de JS de cotización): 12/12 ✅

### Deuda preexistente (no causada por este bloque)

- `PosAccessAndSearchTest::test_checkout_modal_has_responsive_permanent_summary_and_dynamic_direct_payment_flow` — espera `Puntos futuros` en respuesta.
- `QuoteTest::test_quote_mode_executes_frontend_transitions_stock_rules_and_save_contract` — test JS espera clases antiguas del botón `Cotizar`.

### Pendiente / siguiente paso

No se inicia otro bloque hasta instrucción del usuario.

---

## Notas de Crédito — Fase 4B Consumer Final — COMPLETADA / CERTIFICADA / PRODUCCIÓN (2026-09-22)

**Estado: PRODUCCIÓN.** Fuente certificada `5ca8389` (`feature/notas-credito`) → merge integración certificado `40685e86a086c152d178e511af1c0ba454fadb24`. Producción actual: **`40685e86a086c152d178e511af1c0ba454fadb24`** (deploy 2026-09-22).

Cadena 4B: `82c82db` (4B-1 emisión segura) → `93aa4cc` (4B-2 aplicación número+código) → `e682e9a` (4B-3 POS + comprobantes) → `3f59e67` (4B-4 regeneración segura) → `5ca8389` (cierre documental).

Puntos certificados:
- Activación opcional por empresa.
- Consumer Final identificado internamente por `customer_id NULL`.
- Emisión exclusivamente desde devolución válida.
- Código secreto de 12 caracteres efectivos, formato `XXXX-XXXX-XXXX`.
- Solo SHA-256 persistido del código; plaintext de entrega única.
- Vencimiento reutiliza configuración 4A.
- Aplicación por número + código; parcial/total/pagos mixtos.
- Multitenancy, rate limiting, idempotencia y protección de doble gasto.
- POS integrado; NC nominativa preservada.
- Regeneración administrativa con motivo/auditoría; código anterior invalidado inmediatamente.
- Cajero únicamente `notas_credito.aplicar`.
- NC NO es PaymentMethod; sin `SalePayment`/`CashMovement` por NC.

Verificación en producción (smoke 2026-09-22, únicamente Empresa Demo): Consumer Final emisión PASS; aplicación POS bearer PASS; rotación segura PASS; reversión PASS; NC nominativa preservada; seguridad/multitenancy/contabilidad PASS; NC fuera de PaymentMethod/SalePayment/CashMovement; datos reales preservados. Migraciones 4B aplicadas Ran: `2026_09_20_000003` (application_code_hash + toggle empresa) y `2026_09_22_000001` (rotación); 4A (`2026_09_20_000001/000002`) sin cambios.

Certificación: **406 tests PASS** en auditoría conjunta; 4B1/4B2/4B3/4B4 PASS; Security PASS; Build PASS.

Deuda PREEXISTENTE (no causada por 4B, no corregida): `QuoteTest` expectativa visual antigua `bg-sky-700`; 7 tests loyalty con drift de puntos.

### Incidente de deploy 2026-09-22
- 6 HTTP 500 transitorios durante la ventana **10:30:16–10:32:33 CST** (usuarios reales de empresa).
- Causa: swap no atómico del árbol de trabajo + regeneración de route-cache durante el despliegue (ViewException "Route not defined"); rutas presentes en código y caché, sin efectos posteriores.
- 0 HTTP 500 posteriores; **no fue defecto funcional de 4B; producción estable desde 10:32 CST.**

### Decisión operativa de deploy (futuro)
- Los deploys de producción deben anunciarse previamente.
- El usuario acepta una ventana aproximada de hasta 2 minutos.
- NO iniciar la ventana de interrupción sin confirmación del usuario.
- Futuro recomendado: deploy atómico; posteriormente soporte Offline.

## Notas de Crédito — Fase 4A vigencia configurable — COMPLETADA / INTEGRADA / PRODUCCIÓN CERTIFICADA (2026-09-20)

Cadena certificada: feature `9b55929` (`feat(credit-notes): add configurable expiration`) → documentación previa `4737a93` → **integración `645e564`** → **hotfix visual POS `5246e38`** (`fix(pos): restore payment method active states`) → **deploy producción certificado** (2026-09-20). PRODUCTION_HEAD: **`5246e38c15d214132e54641c7883fd219c81cad8`**.

- **Vencimiento configurable por empresa**: `credit_note_expiration_policy` en `companies` con opciones `none` (Sin vencimiento), `30` (30 días), `60` (60 días), `90` (90 días) y `custom` (Personalizado, `credit_note_custom_expiration_days` 1–3650). Migración `2026_09_20_000002`, default `none`. `Company::ncExpirationDays()` traduce la política a días.
- **`expires_at` persistido al emitir**: se calcula UNA vez al emitir la NC; los cambios posteriores de configuración NO son retroactivos (no alteran NC ya emitidas).
- **NC vencida**: conserva historial y saldo pero no puede aplicarse. NO existe status persisted `expired`. Enforcement backend dentro de la transacción: `applyToSale`/`applyBatchToSale` rechazan NC vencidas. La reversión de una aplicación NO elimina `expires_at`.
- **Preparación estructural futura**: `credit_notes.customer_id` nullable y `credit_note_applications.customer_id` nullable (migración `2026_09_20_000001`, FK preservadas).
- **CONSUMER FINAL: NO HABILITADO EN FASE 4A.** **APPLICATION CODE: NO IMPLEMENTADO EN FASE 4A.** Fase siguiente: **4B — Consumer Final + mecanismo seguro de autorización/aplicación**.
- **Permiso**: `notas_credito.configurar` — Administrador / Administrador Local según modelo de permisos; Cajero NO lo recibe automáticamente.
- **Hotfix visual POS (`5246e38`)**: mientras exista saldo pendiente, Efectivo/Tarjeta/SINPE (y Crédito cuando es elegible) → `primary`/dorado + texto negro + habilitado + `cursor-pointer`; cuando el saldo queda cubierto → neutro + `disabled` + `cursor-not-allowed`. Los pagos parciales mantienen los métodos disponibles.
- **Deploy producción**: backup predeploy `/home/mvsadmin/backups/mvscommerce_predeploy5246e38_20260920_201843.dump` (2,369,193 bytes; `pg_restore --list` PASS; PostgreSQL 16.15). Migraciones `2026_09_20_000001` y `2026_09_20_000002` **Ran**. Resultado: migraciones PASS, build PASS, smoke Demo PASS, configuración NC PASS, POS visual PASS, NC nominativa preservada, NEW_HTTP_500 = 0, datos reales preservados, MVS Print preservado, cotizaciones preservadas, apartados preservados.

## Notas de Crédito Nominativa — IMPLEMENTADA Y CERTIFICADA (2026-09-19)

**Commit `a734f5d`** en `feature/notas-credito` (`mvs-commerce-paralelo-3`). Módulo completo certificado y congelado.

**ESTADO: CÓDIGO CERTIFICADO / PENDIENTE DEPLOY PRODUCCIÓN**

### Cadena completa de Notas de Crédito

| Fase | Commit | Descripción |
|------|--------|-------------|
| 0 | `b748c5d` | fix(inventory): restore missing posting operations |
| 1 | `e035cea` | feat(credit-notes): implement core credit note domain |
| 2B | `7bb6b2d` | feat(credit-notes): reconcile credit notes with receivables |
| 2C | `85907e9` | feat(credit-notes): support receivable offset reversals |
| 3A | `3f55ba9` | feat(credit-notes): integrate credit notes with pos backend |
| 3B | `58bc902` | docs: record POS backend certification |
| **final** | **`a734f5d`** | **feat(credit-notes): complete pos workflow and reversals** |

### Funcionalidad certificada

- NC nominativa ligada a cliente identificado.
- Consumidor Final puede devolver pero NO genera NC reutilizable.
- Búsqueda de venta/factura para iniciar devolución.
- Devolución parcial/total según reglas existentes.
- Emisión automática de NC cuando corresponde.
- Múltiples NC por venta, aplicación parcial.
- NC + efectivo, tarjeta/SINPE, crédito, loyalty.
- 100% NC sin CashSession.
- Cross-branch POS permitido solo same company/customer.
- Conciliación CxC mantiene reglas de sucursal.
- Comprobante muestra NC separada de formas de pago.
- Reimpresión recupera aplicaciones persistidas.
- `SaleVoidService` revierte formalmente `CreditNoteApplication`.
- `voided_by` / `voided_at` / `void_reason` preservan auditoría.
- Doble reversión protegida (idempotencia).
- NC NO es PaymentMethod, NO genera SalePayment, NO genera CashMovement.
- Precisión interna DECIMAL(19,4) / BCMath preservada.
- UI no muestra `.0000` técnico.
- Permisos: `notas_credito.crear`, `notas_credito.aplicar`.
- Asignación explícita solo a: Administrador, Administrador Local.
- Cajero no recibe esos permisos automáticamente.

### Tests core certificados

132/132 tests focales NC pasando en certificación final (626+ assertions).

| Suite | Tests | Estado |
|-------|-------|--------|
| PosCreditNoteTest | 42 | PASS |
| CreditNoteTest | 19 | PASS |
| CreditNoteReconciliationTest | 18 | PASS |
| SaleReturnTest | 19 | PASS |
| DevolucionesIndexTest | 16 | PASS |
| OrderPermissionSeederTest | 2 | PASS |
| PosCheckoutTest | 14 | PASS |
| SaleVoidTest | 2 | PASS |

### Deuda técnica preexistente (NO causada por NC)

- `SaleVoidLoyaltyTest`: 5 fallos de expectativas loyalty (diff 25 pts, canje proporcional).
- `SaleReturnLoyaltyTest`: 2 fallos de expectativas loyalty (diff 20-30 pts).
- `PosAccessAndSearchTest`: 3 fallos de payload fields históricos.

### Pendiente de producción

Antes del deploy:
- Backup PostgreSQL.
- Verificar migraciones ya existentes (no hay nuevas en este commit).
- `PermissionSeeder` no destructivo (updateOrCreate).
- `npm run build`.
- Caches Laravel (`config:cache`, `route:cache`, `view:cache`).
- Smoke tests: crear devolución → verificar NC → aplicar en POS → verificar receipt → anular → verificar reversión.
- Verificar permisos por rol.

### Fase futura — NO implementada (roadmap)

**Nota de Crédito al Portador** — decisiones aprobadas:
- Destinada principalmente a ventas Consumidor Final.
- Configurable por empresa.
- Número interno NC consecutivo para trazabilidad.
- Canje mediante código secreto aleatorio NO consecutivo.
- Número NC por sí solo NO permite canje.
- Código perdido = NO recuperable; MVS no reemite ni revela.
- Posesión del código constituye mecanismo de canje.
- Diseño debe impedir doble uso/replay.
- Debe admitir aplicación parcial y saldo.
- NO implementar antes de estabilizar NC nominativa en producción.

## Notas de Crédito — Fase 2C reversión NC↔CxC (2026-09-17)

Base `7bb6b2d` (Fase 2B commit), rama `feature/notas-credito`, trabajo local **sin commit** (así debe permanecer hasta orden explícita). Fase 2C implementada: mecanismo formal de reversión de compensaciones NC↔CxC según decisión D029. Mig `2026_09_17_000003_add_reversal_fields_to_ar_adjustments_table` agrega `reversed_amount` y `reversal_adjustment_id`. `AccountReceivableAdjustment` extiendido con constante `TYPE_CREDIT_NOTE_OFFSET_REVERSAL`, relaciones `reversalAdjustment`/`reversedBy` y helpers `isFullyReversed()`/`remainingAmount()`. `AccountsReceivableReconciliationService::reverseOffset()` implementado: validación, lock order AR→NC→adjustment, idempotencia, reversión idempotente, cap a `original_amount`, creación de reversal adjustment. `AccountsReceivableController::reverseAdjustment` con permiso `cuentas_cobrar.revertir`. Ruta `POST cuentas-por-cobrar/{ar}/revertir-ajuste/{adjustment}`. Vista `accounts-receivable/show.blade.php` actualizada con historial de reversas (badge violeta), formulario de reversión con motivo. Suite: `AccountsReceivableReversalTest` 23/23, 65 aserciones; `CreditNoteReconciliationTest` 18/18; `CreditNoteTest` 19/19; regresión broader 96/103 (7 fallos preexistentes loyalty). Pendiente: commit, push, documentación, revisión del usuario.

Commits previos:
- Fase 2B: `7bb6b2d` (conciliación NC↔CxC automática, 13 archivos, 1453 insertions).
- Fase 1: `e035cea` (núcleo NC).

## MVS Print — instalador 1.0.2 en preparación (2026-09-16)

Versión bump a 1.0.2 en todos los archivos fuente: NSI (`!ifndef` guard, única definición), Launcher.cs, launcher.manifest, build-installer.ps1, README.md, VERSION. Dorado oficial documentado en AGENTS.md y docs/GUIA_VISUAL.md. Impresion.blade.php migrado de amber/blue a `bg-primary` dorado. Tests PHP 62/62, JS security 25/25, JS print 14/14. Launcher build + tests PASS. Pre-existente: ResponsiveNavigationTest fallo de logo (no relacionado). Build completo del instalador requiere QZ source tree (JDK+Ant); preparado para ejecutar cuando el árbol QZ esté disponible. Prueba física final pendiente. Nota: archivos fuente del installer (`storage/app/mvs-print-release/`) están gitignored; el version bump es local y efectivo en el próximo build.

## MVS Print — instalador 1.0.1 completado, producción actualizada (2026-09-16)

Instalador 1.0.1 completado con launcher propio C# x64 (`MVS Print.exe`, 27,648 bytes), QZ 2.2.6 embebido, ruta automática `C:\Program Files\MVS Print`, identidad dorada oficial (`#D4AF37`), certificado público para trust management, single instance vía Mutex, y tests automatizados. Builds y uploads a producción verificados: SHA256 `1d8a5e66...`, URL `https://app.mvscommerce.com/mvs-print/MVS-Print-Setup.exe?v=1.0.1`. SmartScreen requiere certificado de firma de código (limitación externa, no bug). Pruebas PHP 62/62, JS 25/25 (QZ real), JS 14/14, npm build PASS. Prueba física final pendiente en Liberia.
## Fix focal Paso 2 wizard fiscal — credenciales exigidas (2026-09-29, rama feature/factura-electronica)

Commit `b0be769` (`fix(fe): require provider credentials on initial fiscal connection step`), push a `origin/feature/factura-electronica` limitado a 3 archivos. `FiscalPortalController::storeConnection()` exige `api_key`/`api_secret` cuando la empresa no tiene credenciales activas (`configs->ensure()->hasCredentials()`), con mensajes propios ("Registre la llave/secreto de conexión: esta empresa aún no tiene credenciales activas."); vacío = conservar activas (validación inline `@error` en `setup.blade.php`); botón "Guardar y verificar" → "Guardar y continuar". 4 tests nuevos en `FiscalPortalTest` (sin config fiscal, staging de ambas llaves, vacío conserva activas, rotación sobrevive re-staging vacío) + ajuste del test de cuota de licencia. Evidencia: `FiscalPortalTest` **44/44**, `git diff --check` limpio, CERO HTTP fiscal real, sin tocar untracked ni company 10/9. Hallazgo de test: con `serialization=json`, `Store::marshalErrorBag()` vacía un `errors` que llegue como objeto `ViewErrorBag` si el handler no devuelve la cookie entre peticiones; los tests reinyectan errores en formato arreglo. No es bug de producción (cookie compartida). Pendiente: validación visual del usuario.

## Corrección final de prueba manual del configurador fiscal (2026-09-28, rama feature/factura-electronica)

Textos: Paso 2 "Ingrese las credenciales necesarias para conectar MVS Commerce con Hacienda", Paso 3 "Comprobamos que su conexión fiscal esté correctamente configurada, sin emitir ningún documento ni consumir cuota" (sin presentar auth/verify como comunicación con Hacienda), Paso 4 "Emitir automáticamente al completar una venta en el POS" + "Guardar y continuar"; Paso 5 agrega read-only actividad económica, ubicación fiscal (Completa/Pendiente) y sucursal/terminal. Diagnóstico Master: "Actividad con Hacienda: Aún no se han enviado documentos." separado de "Última comprobación". Auditoría de ubicación: obligatorios solo identificación + nombre fiscal + credenciales (mapper + `identityComplete`); provincia/cantón/distrito, actividad y sucursal/terminal son informativos y no viajan en payloads (−37 = configuración del emisor en el proveedor); regla documentada. Evidencia: `FiscalPortalTest` 37/37, `FiscalMasterTest` 15/15, `FiscalAdjustmentTest` 34/34, `FiscalConsumptionTest` 16/16, CERO HTTP real, `git diff --check` limpio. Detalle: `docs/fiscal/PORTAL_FISCAL_MVS.md`.

## Pulido final configurador fiscal (2026-09-27, rama feature/factura-electronica)

Causa CTA fantasma: CSS desactualizado (rebuild Vite); títulos por estado; cadena 1→5 con Paso 5 "Revisar y finalizar" real (preferencias→confirmación→finalizar), mensajes por paso y Anterior en cada paso; pendiente oculta fecha vieja de verificación; desconexión en Zona de seguridad; Series solo en avanzada. Evidencia: portal+master 49/49, core 62/62, CERO HTTP real. Detalle: `docs/fiscal/PORTAL_FISCAL_MVS.md`.

## Fix visual wizard fiscal (2026-09-27, histórico, previo a pulido final)

Causa botón fantasma: `bg-[#D4AF37]` no estaba en el CSS compilado (build desactualizado); rebuild `npm run build` + affordance (sombra/focus) en CTAs. Título del wizard por estado (Conectar vs Configuración de Facturación Electrónica). Navegación 1→5 con mensajes por paso + enlaces Anterior (datos persisten en servidor, PUTs idempotentes). Series solo en Configuración avanzada. Evidencia entonces: portal+master 47/47, core 62/62, CERO HTTP real. Detalle: `docs/fiscal/PORTAL_FISCAL_MVS.md`.

## Limpieza tenant master fiscal (2026-09-27, histórico, previo a fix visual)

Sin botón Proveedor ni acceso tenant a cambio de provider (403; pantalla solo en Panel Maestro). Series en Configuración avanzada con estado neutral sin alerta falsa. Master sin máscara de credencial (solo "Conexión fiscal": Verificada/Pendiente/Requiere atención). Arquitectura multi-provider intacta. Evidencia entonces: portal+master 43/43, core 62/62, CERO HTTP real. Detalle: `docs/fiscal/PORTAL_FISCAL_MVS.md`.

## Master visual MVS + CTA (2026-09-27, histórico, previo a limpieza tenant)

Centro "Centro de Facturación Electrónica" con CTA dorado inmediato (Completar/Administrar + Actualizar conexión), tarjeta Configuración fiscal, estados Configuración vs Hacienda sin contradicción, consumo con barra dorada y desglose, recientes con badges y detalle, diagnóstico con Resolver al paso exacto. Solo UI/controlador-vista (motor intacto). Evidencia entonces: portal 24/24, master+ajustes 49/49, core 43/43, CERO HTTP real. Detalle: `docs/fiscal/PORTAL_FISCAL_MVS.md`.

## Master fiscal marca MVS + rotación segura (2026-09-27, histórico, previo a visual/CTA)

Master "Centro de Facturación Electrónica" con accesos Completar/Administrar/Actualizar + Configuración fiscal; wizard crear/editar de 9 bloques (ubicación con catálogo real); rotación por etapas (pendiente→verificar→activar, anterior intacta si falla, auditoría sin secretos, producción con confirmación, desconexión independiente sin borrar historial); proveedor oculto en las 5 vistas tenant (marca MVS, arquitectura intacta); diagnóstico neutro. Evidencia entonces: portal+master+ajustes 71/71, core 47/47, CERO HTTP real. Detalle: `docs/fiscal/PORTAL_FISCAL_MVS.md`.

## Master Fiscal MVS fase 2 (2026-09-27, histórico, previo a marca/rotación)

Portal convertido en centro de control: banner de ambiente (PRUEBAS sin valor fiscal / PRODUCCIÓN), emisor, actividad, sucursal/terminal, conteos, series, diagnóstico extendido y detalle por documento con custodia. Onboarding con actividad + códigos 3/5; series provider-neutrales (observan, importan sin retroceder, sin resets, claim con lock); gate de cambio de proveedor (sin MvsFiscal real); webhooks con contrato neutral pero receptor NO implementado (docs oficiales sin espec de firma — bloqueador documentado, polling vigente); custodia payload-inmutable + respuesta. Evidencia entonces: `FiscalMasterTest` 15/15, portal 15/15, core 49/49, CERO HTTP real. Docs: `PORTAL_FISCAL_MVS.md`, `MIGRACION_PROVEEDOR_FISCAL.md`.

## Portal Fiscal MVS por empresa (2026-09-27, histórico, previo a fase 2)

Portal "Facturación Electrónica" (`fiscal.*`): estado, ambiente, consumo, historial FE/TE/NC/ND, diagnóstico y asistente de 5 pasos con verificación SIN emitir. Config por empresa (`company_fiscal_configs`, secretos cifrados, sandbox/producción separados); manager resuelve proveedor por empresa; FacturaEnCR opera con contexto empresarial; futuro MvsFiscalProvider sin rehacer portal/POS. Panel Maestro sigue autoridad comercial; tenant solo consulta. Evidencia entonces: `FiscalPortalTest` 15/15, fiscal core 46/46, CERO HTTP real. Preexistente ajeno: `ResponsiveNavigationTest::test_tenant_header...logo` falla también en HEAD limpio. Detalle: `docs/fiscal/PORTAL_FISCAL_MVS.md`.

## Fiscal reemisión tras rechazo (2026-09-27, histórico, previo al portal)

Doc3 rejected intacto (sin tocar). Ciclo modelado: `attempt_number` + unique `(company,sale,type,attempt)` + idempotency por intento (attempt 1 conserva formato histórico); solo `rejected` habilita N+1, otro estado devuelve el vigente sin fila/POST; secuencia estricta; backstop de concurrencia con retorno del ganador; consumo por intento, retry/polling = 0. Migración `2026_09_27_000005` aplicada en dev. Cobertura 03/02 con HTTP falso + backstop unique + aislamiento + FE/TE sin regresión. Evidencia entonces: `FiscalAdjustmentTest` 34/34, CERO HTTP real.

## Fiscal NC03 -496 corregido (2026-09-27, histórico, superado por reemisión)

Doc3 rechazado preservado (-496 bloqueante + -37 acompañante, sin reenvío, consumo INCLUDED intacto). Fix: `medioPago` derivado de pagos completados de la venta original (uno → código; varios → tipo/monto; crédito exento; contado sin pagos o método no mapeable bloquea pre-POST, nada hardcodeado). -37 es configuración del emisor sandbox (payloads nunca envían ubicación; FE01/TE04 aceptadas igual). Preflight endurecido a nivel mapper + provider. Venta7 tiene 1 pago cash 1010.00 → segundo E2E derivable. Evidencia entonces: `FiscalAdjustmentTest` 25/25, CERO HTTP real. Sin commit de datos E2E (doc3/consumo solo en `database.sqlite` local).

## Fiscal NC03/ND02 adapter oficial (2026-09-27, histórico, superado por fix -496)

Push recuperado (`0e7d03e..d6fb2ef`). Docs oficiales facturaencr.com/docs (API v2 v4.4) consultados: NC03 → `POST documents/nota-credito`, ND02 → `POST documents/nota-debito`, `referencia[]` obligatoria (tipoDocumento/numero clave-50/fechaEmision/codigo/razon). Adapter implementado SIN inferencias: mapper emite `referencia[]` oficial (corregido `informacionReferencia`), clave-50 validada localmente, provider con idempotencia + conciliación igual que 01/04. Tests con HTTP falso: emisión 03/02 aceptada, error sin consumo, retry sin duplicar. CERO POST real, CERO sandbox, sin Sale6/Sale7 ni producción. Evidencia entonces: `FiscalAdjustmentTest` 20/20. Sin módulo comercial NC/ND.

## Fiscal NC03/ND02 local previo (2026-09-27, histórico, superado por adapter oficial)

TE04 sandbox accepted REAL (Sale 6/doc 1). FE01 sandbox accepted REAL (Sale 7/doc 2). NC03 LOCAL implementado: referencia congelada, builder, `FiscalManager`, mapper neutral, `ElectronicDocument(03)`, 1 consumo, cuota/overage, retry sin duplicar, gates pre-provider, CERO HTTP (fake). ND02 LOCAL espejo con tipo 02 en verde. Referencias negativas, casos fiscales locales (IVA 13/4/2/1, exento explícito, exoneración parcial, multi-tax, descuento, multi-línea, BCMath, CABYS inválido, tasa ambigua bloqueada) y observabilidad (`source_type/source_id/original_document_id`, sin secretos) en verde. Evidencia entonces: `FiscalAdjustmentTest` 17/17. Matriz: `docs/MATRIZ_COMPROBANTES_FISCALES.md`; diseño: `docs/fiscal/NC03_ND02_DESIGN.md`.

## Fiscal: concurrencia, UI de consumo y matriz (2026-09-27, local sin commit)

`FiscalConsumptionService::record()` endurecido contra race check-then-insert (lock de licencia + recuento del ledger + backstop de constraint único que devuelve la fila ganadora); doble consumo/cobro imposible. Licencia tenant muestra uso del mes con desglose por tipo, disponibles y excedentes (2 decimales, solo lectura); Panel Maestro suma bloque de consumo y administra habilitación/cuota/excedentes/precio. NC03 auditado: sin modelo/mapper/endpoint/job en esta rama (E2E BLOCKED); gate y ledger probados listos para `03` sin POST. Matriz real en `docs/MATRIZ_COMPROBANTES_FISCALES.md` (TE04/FE01 E2E accepted; NC03/ND02 no implementados). Evidencia: `FiscalConsumptionTest` 16/16; regresión S3b 15/15, caja 21/21, checkout 19/19, licencias/plataforma 35/35; `git diff --check` limpio. Sin HTTP nuevo, sin emisiones reales, sin producción.

## Primera FE01 real aceptada en Sandbox (2026-09-27, documentado sin cambios de código)

Sale ID 7 / `electronic_invoice` / tipoDocumento 01; ElectronicDocument ID 2; receptor `01`/`109880401` válido; CABYS `0111100000100` IVA 1% intacto; preflight mapper FE01 → `documents/factura`; exactamente **1 POST** vía `FiscalManager` (idempotency estable `1d30621c…`, provider_ref `6ab9101eede704dd046aafc0`); estado inicial `queued`; **accepted en el primer GET** (sin más polling); clave `50627092600310191287705001034010000000001114095176`, consecutivo `05001034010000000001`; gate fiscal local habilitado con cuota 50 (`authorize` = included pre-POST); `fiscal_consumptions` exactamente **1 fila INCLUDED** con `unit_price` NULL; el GET no agregó consumo; Sale 6 / doc 1 TE04 `accepted` intacto; `auto_emit` **false**; sandbox únicamente, cero producción.

Aprendizaje para MVS Fiscal: `queued` ≠ `accepted` (requiere polling); polling/retry del mismo documento no duplica consumo (identidad local estable); el consumo vive atado al documento fiscal local, no a la venta; el gate comercial/licencia debe ocurrir siempre antes del POST.

## Primer E2E fiscal Sandbox exitoso (2026-09-27, documentado sin commit de código)

Sale ID 6 / `electronic_ticket` / tipoDocumento 04; ElectronicDocument ID 1; CABYS `0111100000100` (Trigo duro, para siembra) con perfil fiscal IVA 1% (ID 2, `01`/`02`); exactamente **1 POST** real al Sandbox FacturaEnCR vía `FiscalManager → FiscalProviderInterface → FacturaencrProvider`; estado inicial `queued`; **2 GET** de seguimiento al mismo documento; estado final `accepted`; clave `50627092600310191287705001034040000000001192986306` y consecutivo `05001034040000000001` generados correctamente; `fiscal.emission.auto_emit` permaneció **false**; cero producción, sin cambios de código.

Aprendizajes para MVS Fiscal: preflight/mapper local obligatorio antes de cualquier POST; evidencia E2E auditable (sale/documento/clave/consecutivo/conteo POST+GET); idempotency key estable `company+sale+provider`; `queued`/`pending` ≠ `accepted` (requiere polling); CABYS validado contra proveedor (13 dígitos + impuesto real 1%); validación de identificación por tipo (01 acepta cédula 9 dígitos como `109880401`); sandbox ≠ producción.

## S3b — Trigger de emisión electrónica POS (2026-09-25, local sin commit)

Rama `feature/factura-electronica`, base `afb3976`, trabajo local sin commit ni push. Nuevo `app/Jobs/EmitElectronicDocument` (ShouldQueue + ShouldBeUnique, tries=1, captura total de Throwable, guard idempotente `company_id+sale_id+provider`) y `app/Services/Fiscal/PosEmissionDispatcher` invocado desde `PosController::checkout` después del commit; flag `fiscal.emission.auto_emit` en `config/fiscal.php` default **false** (dispatch condicionado), no toca venta/caja/inventario ni reglas B1–B7, sin HTTP real ni `Facturaencr*` desde POS. Evidencia: `PosElectronicEmissionTriggerTest` **9/9, 66 aserciones**; Unit **222/222, 796 aserciones**; filtro `Pos|Fiscal|Facturaencr|Electronic|Layaway|Quote` **547 tests, 538 aprobados, 8 fallos + 1 error**, los nueve reproducidos idénticos en base sin cambios (preexistentes, ver "Fallos históricos conocidos"); `git diff --check` limpio. Sin commit, push, migraciones ni producción.

## MVS Print — AUTO_PRINT, Imprimir postventa y Reimprimir (2026-09-15, cierre aprobado)

Base `21d5329`, `feature/pos`. Listado y detalle de Ventas reutilizan `EscPosSaleTicket` mediante el endpoint existente y QZ; fallback del navegador exclusivamente manual tras error. Terminal por UUID local o única terminal configurada en empresa/sucursal; no resolver ambigüedad silenciosamente. Reprint conserva ancho/corte, desactiva cajón y reutiliza autorización del comprobante. `auto_print` corrige conexión QZ ya activa y resolución `undefined`; venta nueva conserva cajón configurado. Diagnóstico temporal retirado. Botón Imprimir postventa integrado en el mismo canal MvsPrint.printSale; fallback manual tras error y mensaje si no se resuelve terminal. JS ejecuta confirmCheckout y el botón reales con QZ/HTTP simulados. Demo sin terminal nunca reutiliza MYM (test); configuración física de Demo no consultada. PHP MvsPrint **56/56, 226 aserciones**; JS **14/14**; build y diff-check correctos. Snapshot de todas las tablas y SQL prueban cero escrituras del GET de reprint. Prueba física y visual pendiente. Commit y push de esta corrección autorizados; producción no autorizada. Detalle: [MVS_PRINT_TEST_PRINT_AUDIT.md](MVS_PRINT_TEST_PRINT_AUDIT.md). Cambios locales ajenos preservados.

## Corrección focal — canje proporcional sobre base pre-impuesto (2026-09-15)

Base `8cf8714`, `feature/pos`, limpia al iniciar la corrección; trabajo local sin commit. Cambio funcional únicamente en PosSaleProcessor: la base elegible neta deriva de SaleItem.subtotal, después de descuentos, sin impuestos y respetando earn_on_offers. Se excluye su porción financiada con puntos: `base_elegible * canje_real / Sale.total`, BCMath, sin truncar el ratio y con redondeo único de la porción a cuatro decimales. Sustituye la resta íntegra del canje rechazada por el usuario. Ejemplo base 10000 + IVA 1300 y canje 2260: earning base 8000; canje total: cero; sin canje: base intacta. Multiplicador después del prorrateo; redemption, impuestos y point_value intactos.

Validación final: **86/86 pruebas focales, 679 aserciones, cero fallos**; cubre tasas mixtas, exentos, descuentos, ofertas on/off, multiplicadores, redondeo, efectivo/tarjeta/SINPE, retry y aislamiento empresarial. Lint PHP y diff-check correctos. Sin UI, migración, commit, push ni producción. Problemas preexistentes de devoluciones/anulaciones/premios/comprobante fuera de alcance; no se reejecutaron esas suites. Regla en docs/DECISIONES.md y detalle en docs/POS_CANJE_PUNTOS.md. Listo para auditoría focal.

## Canje monetario en POS — auditoría aprobada, cierre autorizado (2026-09-14)

Auditoría final del usuario aprobada: canje, pago parcial/total/mixto, autoridad backend, atomicidad, idempotencia y multitenant correctos. Commit y push a feature/pos autorizados; producción no autorizada. Los problemas consignados abajo se confirman preexistentes y fuera del alcance de este commit, por lo que no bloquean el cierre del pago con puntos. El párrafo siguiente conserva la evidencia de la implementación local anterior al cierre.

Paso 32 pausado por el usuario. Reutilizada fidelización existente; habilitado pago total con puntos sin pago normal, validación de cantidades en checkout y BCMath para puntos/restante. Focal final: 51/51, 373 aserciones; canje total, retry y JavaScript renderizado probados. Regresión POS/Loyalty/USD: 75/76, 590 aserciones; fallo de texto esperado del comprobante fuera del parche. Bloqueo: devoluciones/anulaciones llaman a métodos de inventario ausentes (`saleReturn`, `voidSale`), 13 errores; sin ampliar alcance. Multisucursal añade error de premio por `postRewardRedemption` ausente. Detalle y entrega: [POS_CANJE_PUNTOS.md](POS_CANJE_PUNTOS.md). Sin migración, commit, push ni producción; validación visual pendiente.

## Modo cotización en POS — implementación local (2026-09-12)

Corrección visual posterior (2026-09-12): `Cotizar` ahora tiene fondo azul, texto blanco, cursor activo y mínimo 44px; su bloqueo refleja permiso/flujo sin depender del carrito. Ambos `Guardar cotización` se deshabilitan con carrito vacío. Pruebas de expresiones del botón y JavaScript renderizado, permiso Blade verdadero/falso: `QuoteTest` 12/12, 159 aserciones; build y `git diff --check` correctos. Se confirmó ausencia de fondo en el botón previo; no se reprodujo el fallo de activación inicial ni se verificó la sesión del usuario en navegador. Validación visual pendiente; los cinco fallos preexistentes no se modificaron ni se reejecutó la regresión amplia para este ajuste. Cambios de este ajuste: vista POS, pruebas Quote PHP/JS y esta nota; se preservó el trabajo local anterior.

Base verificada: `feature/pos`, `305466d`, árbol limpio antes de editar. `Cotizar` activa modo Alpine local con carrito vacío, conserva carrito existente y permite stock cero/cantidades superiores al stock. `Guardar cotización` reutiliza servicio y ruta existentes; cliente opcional y cálculos intactos. Guarda, limpia el carrito, confirma y vuelve a venta en la misma página. `Volver a venta` y carga de cotización restauran controles; checkout no acepta saltarse stock mediante `quote_mode`.

Alcance de código: vista POS; prioridad de stock en búsqueda solo para cotización autorizada; respuesta JSON de errores limitada al POST `cotizaciones`. No cambia QuoteService, rutas, inventario, caja, fidelización, demo ni compras. La prueba de conversión existente ahora prepara caja abierta y verifica stock real, corrigiendo un fixture que fallaba antes de llegar al inventario.

Validación SQLite en memoria: `QuoteTest` **12/12, 158 aserciones, cero fallos**, incluida ejecución del JavaScript renderizado con Node y snapshots/query log de cero efectos en ventas/items/pagos, stock/Kardex, caja y fidelización. Regresión final POS + Quote: **173 pruebas, 168 aprobadas, 1161 aserciones, 5 fallos preexistentes**. Comparación directa con código/tests de `305466d`: 167 pruebas, 161 aprobadas, 1070 aserciones, 6 fallos; se resolvió únicamente el fixture focal de conversión. Persisten tres fallos de `PosAccessAndSearchTest` (payloads ampliados de productos/clientes y texto antiguo del modal), uno de `PosCheckoutTest` (texto antiguo del comprobante) y uno de `PosSuspendedSalesTest` (125 vs '125.00'). Evidencia local: `storage/logs/quote-mode-baseline.{txt,xml}` y `quote-mode-regression.{txt,xml}`. Comando final: `php -d memory_limit=512M vendor/bin/phpunit --filter='Tests\\Feature\\(QuoteTest|Pos(?!tgre))'`; APP_KEY temporal de proceso, sin `.env` ni configuración global.

Build Vite y `git diff --check` correctos. Pint pasa en bootstrap/test; el controlador conserva los mismos cuatro avisos de formato de la base, sin refactor ajeno. Responsive conceptual 360/768/1280, acciones nuevas de 44/48px y barra móvil existente; sin navegador, pendiente de revisión visual del usuario. Listo para auditoría local, sin declarar verde la regresión global. Sin commit, push ni producción.

## Toma de Inventario — pruebas finales locales (revalidación 2026-09-11)

Corrección de auditoría por contrato del usuario: **stock final = físico confirmado**; el ajuste firmado se calcula contra el stock actual bajo bloqueo. Snapshot 10, venta deja 8 y físico 12: stock final 12, movimiento +4; snapshot/diferencia original y venta intactos. `InventoryCountTest` pasa **39/39, 361 aserciones** con y sin `--stop-on-failure`. Creación incorpora marker responsive y detalle elimina scanner sin uso; edición conserva scanner compartido. `InventoryPostingService.php` intacto. Auditoría de precisión: ya existe migración a `decimal(19,4)` para las tres columnas Kardex, registrada en SQLite local; no se requiere nueva migración. Regresión relacionada: **46/49, 502 aserciones**; dos errores por `postImportMovement()` ausente y un fallo de texto mojibake en transferencias, fuera de alcance. Detalle y límites de UI/concurrencia en [TOMA_INVENTARIO_TESTS.md](TOMA_INVENTARIO_TESTS.md). Sin commit, push ni producción; listo para auditoría del módulo, sin declarar verde la regresión general.

## Decisión USD en POS — 2026-09-09

Registrada en `docs/DECISIONES.md`: USD se ofrecerá automáticamente dentro de Efectivo según `accepts_usd`, snapshot y tipo de cambio válido de la sesión aplicable, sin requerir PaymentMethod manual. Auditoría local: no hay métodos USD/Dólares, MYM tiene USD deshabilitado y no hay sesiones abiertas/en cierre. Implementación integral pendiente: el checkout actual es CRC y falta persistencia de moneda/importe USD para conciliación. Sin cambios de datos, métodos de pago ni producción.

## Separación Dashboard administrativo / sucursal operativa — 2026-09-09

El bloque previo fue confirmado y publicado en `feature/pos` como `f40efccf3a614eb88aa56d373ee46da3d5a1a856`. La corrección actual queda **local, sin commit**. Dashboard administrativo consulta empresa/todas por defecto; el filtro GET por sucursal no modifica `active_branch_id`. POS/Caja solicitan sucursal explícita cuando falta y conservan permisos, asignaciones y caja obligatoria. Usuarios operativos mantienen el comportamiento existente. Detalle: [DASHBOARD_ADMINISTRATIVO_CAJA.md](DASHBOARD_ADMINISTRATIVO_CAJA.md).

Sin acceso a producción, cambios de inventario, `.env` ni stash de Notificaciones. Las notas de cierre siguientes describen el estado histórico anterior al commit citado.

Validación actual: focal final **56/56, 515 aserciones**; accesos/P08/onboarding/alertas **22/22**; regresión **213 pruebas, 206 aprobadas**, cinco fallos y dos errores preexistentes de POS, logo y compras (`postPurchase()` ausente). Build y diff-check correctos. Revisión responsive conceptual, sin navegador. No se realizó commit ni push.

## Dashboard Administrativo + Caja — cierre local (2026-09-09)

Bloque retomado desde el working tree y terminado para preparar un commit único, **todavía no ejecutado**. Dashboard por Hoy/Semana/Mes/sucursal/Todas sin requisito de caja para consulta administrativa; apertura por denominaciones, cierre ciego, conciliación y correo existentes conservados. Configuración → Caja soporta múltiples destinatarios por empresa y cierre normal sin correo cuando la lista está vacía; idempotencia de cierre/job/reintento parcial probada. Caja mantiene `mail.notifications.from`, auth/sistema `mail.from` y soporte `mail.reply_to`, sin tocar `.env` ni SMTP.

`documentsBreakdown()` es la fuente única: venta + abono CxC + abono de apartado + pago CxP; excluye SalePayment y CashMovement. Se conserva el contador POS recibido. Revertida únicamente la ampliación ajena de payload en `PosAccessAndSearchTest.php`; archivo sin diff. `InventoryPostingService.php`, producción y stash de notificaciones intactos.

Validación: focal **52/52, 467 aserciones**; filtro Cash **154 pruebas, 151 aprobadas, 974 aserciones**, con el fallo histórico de Órdenes/POS 200/302 y dos errores de `postPurchase()` ausente. Build Vite correcto, 236 módulos; diff-check correcto. Cronograma maestro nuevo: bloques **11–20 completados localmente**, producción No, sin renumerar ni avanzar Notificaciones. Detalle y límites: [DASHBOARD_ADMINISTRATIVO_CAJA.md](DASHBOARD_ADMINISTRATIVO_CAJA.md). Pendiente revisión del usuario y orden explícita de commit/push; no autorización de producción.

## Continuación local — vencimiento P37 (2026-09-07)

Como antecedente histórico: posteriormente se verificó PostgreSQL de producción y se reactivó **solo** `loyalty_settings.is_active` de MYM (id/company_id 1) con autorización expresa; saldo 97 y movimiento 7109 quedaron intactos. Esta nueva tarea es exclusivamente local, sin acceso ni cambios a producción.

Implementada política compartida compra > movimiento legado P37 válido; referencia ambigua o ausente no calcula fecha. Integrada en portal y expiración bajo lock, conservando idempotencia y metadata de origen. Sin migraciones/backfill, sin tocar importador, compras ni historial. SQLite: **83/83, 553 aserciones**. PostgreSQL 16 aislado: **84 pruebas, 53 pasan, 31 errores**, todos en fixtures existentes de `LoyaltyMigrationP37Test` que usan `identification_type=national`, rechazado por `customers_identification_type_check`. Las pruebas nuevas, incluido P37 real integrado y concurrencia de dos procesos (espera de lock, compra confirmada y relectura), pasan en PostgreSQL. **PAUSA por instrucción del usuario ante anomalía importante; no corregir fixtures ni hacer commit/deploy sin retomar explícitamente.** Pint focalizado y diff-check correctos. La instancia local 127.0.0.1:55439 fue detenida; sus datos ficticios se conservan en `storage/framework/testing/p37-pg-test`. No se cambió php.ini ni .env; pdo_pgsql se cargó solo por CLI. La nueva política debe aprobarse y volver a medir su impacto antes de producción.

## Validación posterior — fixtures de identificación (2026-09-07)

El usuario autorizó retomar únicamente los fixtures: 24 valores `national` y uno `physical` cambiados a `01` en DataExportTest, HistoricalSaleImportP34P35Test, LoyaltyMigrationP37BulkTest, LoyaltyMigrationP37Test, PosAccessAndSearchTest y RepairP37IncompatibleSnapshotsTest. No quedan inserciones inválidas de identification_type detectadas en la suite; se conserva el caso negativo de importación P32 con tipo 6. Sin cambios adicionales a lógica, schema, validadores ni datos reales.

Validación focalizada (seis archivos afectados + vencimiento P37): SQLite **97 pruebas, 597 aserciones, 3 fallos POS y 1 omisión de concurrencia PostgreSQL**; PostgreSQL aislado **97 pruebas, 587 aserciones, 5 fallos y 2 errores**. Los 15 casos nuevos de política pasan en ambos motores; concurrencia PostgreSQL pasa (8 aserciones). Pendientes PG: dos triggers escritos con sintaxis SQLite, expectativa de ID fijo en P37 y formato decimal de exportación; además los tres fallos POS comunes.

Regresión amplia `Loyalty|RepairP37IncompatibleSnapshotsTest|DataExportTest|HistoricalSaleImportP34P35Test|PosAccessAndSearchTest`: SQLite **519 pruebas, 2838 aserciones, 45 fallos, 2 errores, 1 omitida**; PostgreSQL **519 pruebas, 2705 aserciones, 47 fallos, 32 errores**. Cada ejecución reporta también un test riesgoso. Cero errores de customers_identification_type_check. Persisten problemas fuera del alcance: métodos de inventario ausentes, expectativas de permisos/vistas, fixtures UUID y products.product_type inválidos, y manejo de transacción abortada PostgreSQL. No se corrigieron. Evidencia local en storage/logs/fixture-*-regression.json y fixture-focused-*.xml. CLI con memory_limit=512M y pdo_pgsql, sin cambiar configuración global.

La corrección de identificación está terminada. La revisión independiente confirmó que la política P37 y estos fixtures no introdujeron regresiones; el usuario autorizó un único commit limitado, sin corregir la deuda ajena ni desplegar. Validación final pre-commit de la suite nueva: SQLite 15 aprobadas, 69 aserciones y una omisión (concurrencia requiere PostgreSQL); PostgreSQL aislado 16 aprobadas, 77 aserciones, incluida concurrencia real. No declarar la regresión global en verde ni autorización para deploy. Sin acceso a producción en esta tarea.

> La información de este archivo es una fotografía. Antes de programar, comprobar el estado real del repositorio: `git status`, último commit y código del módulo.

---

## Panel Maestro — Cronograma M

- **M01 — Licenciamiento SaaS por tenant: COMPLETADO.** Platform Admin controla estado/plan, `branch_limit` y módulos por empresa mediante el contrato existente; las mutaciones están protegidas en middleware y servicio.
- Bloqueo tenant e aislamiento entre empresas cubiertos por pruebas; roles tenant no administran privilegios globales.
- Fuente oficial: `docs/Cronograma_M_Panel_Maestro_MVS_Commerce.xlsx`.
- **M02 — Listado global: COMPLETADO.** Búsqueda por empresa/propietario, filtros de licencia/módulo y resumen de propietario, plan, sucursales usadas/límite, módulos y usuarios.
- **M03 — Alta comercial mínima: COMPLETADO.** Crea propietario, tenant y contrato sin datos fiscales, sucursales ni operación.
- **M04 — Acceso propietario: COMPLETADO.** Invitación segura expirable/de un uso, activación al definir contraseña y separación estricta de Platform Admin.
- **M05 — Onboarding tenant: COMPLETADO.** El propietario completa datos legales y primera sucursal en el tenant comercial existente sin alterar contrato.
- **M06 — Sucursales: COMPLETADO.** `branch_limit` se aplica por tenant; aumentar desde Panel Maestro habilita la siguiente alta sin borrar datos.
- **M07 — Módulos: COMPLETADO.** Desactivación bloquea navegación/URL sin borrar permisos y reactivación los restaura; tenant no auto-habilita. M08 queda siguiente.
- **M08 — Ciclo de vida: COMPLETADO.** Suspender/cancelar bloquea sin borrar; reactivar restaura acceso y conserva datos. M09 queda siguiente.
- **M09 — Ficha tenant: COMPLETADO.** Vista única con contrato, módulos, sucursales/uso, usuarios, fechas, historial y acciones maestras. M10 queda siguiente.
- **M10 — Auditoría/seguridad: COMPLETADO.** Historial con actor/snapshot para licencia, límites, estado y módulos; aislamiento y escalada cubiertos. M11 queda siguiente.
- **M11 — UX responsive: COMPLETADO.** Panel y onboarding validados para móvil/tablet/escritorio, sin overflow de página y con acciones claras.
- **M12 — Regresión: COMPLETADO; DESPLIEGUE NO EJECUTADO.** M01–M11 integrados en verde (53 pruebas, 273 aserciones), build correcto y MYM preparado mediante escenario aislado. Listo para aprobación humana de producción; pausa obligatoria antes de desplegar o crear datos reales.
- **M13 — Planes/licencias: COMPLETADO.** Plan como plantilla y licencia como contrato efectivo con overrides aislados. Panel Maestro contractual; operación tenant en solo lectura.
- **M14 — Renovaciones/ciclo: COMPLETADO localmente.** Trial/Active/Grace/Expired/Suspended/Cancelled, renovación y trazabilidad contractual no destructiva. Sin cobros ni despliegue; listo para aprobación de producción tras validación final.

## Onboarding de clientes y sucursales

- Implementado onboarding obligatorio para usuarios cliente sin empresa: empresa y primera sucursal se crean mediante `CompanyProvisioner` antes de habilitar el dashboard.
- Los administradores de plataforma son dirigidos siempre a `/panel-maestro`; el Panel Maestro conserva su middleware exclusivo.
- La cuenta de plataforma y la administración tenant son identidades separadas. `platform:admin correo --create` permite crear interactivamente una identidad global activa, sin empresa ni sucursal; la promoción de cuentas tenant se rechaza y cualquier revocación necesaria se realiza explícitamente con `--revoke` durante la operación del despliegue.
- Sucursales queda visible bajo Configuracion para usuarios autorizados. La creacion adicional reutiliza `BranchController` y respeta `company_license.branch_limit` mediante `CompanyLicenseService`.
- Empresas existentes conservan su contexto y no repiten onboarding.


## Estado actual de Portal de Clientes (P01–P20) — reconciliado con Excel único

**P10–P20 COMPLETADOS.** P14–P18 crean el incentivo único y sus reglas; P19 completa su trazabilidad. P20 aplica nombre/logo y colores configurables por empresa al acceso, registro, portal y tarjeta QR, manteniendo “Hecho con MVS Commerce”. Regresión P14–P20/Portal/POS/canje: 167 tests, 1.040 aserciones.

Fuente oficial: `docs/CRONOGRAMA_PRODUCCION.md` (P01–P50) y referencia visual `docs/Cronograma_Unico_Portal_Correcciones_MVS_Commerce_28-08-2026.xlsx`. Reemplaza cronogramas anteriores; **P01–P25 COMPLETADOS** (incluidos P09A–P09D con sus IDs exactos). Reconciliación documental aprobada: P21 corresponde a Separación Platform/Tenant y P22 a Onboarding, ambos con evidencia `a60425f`; P23/P24 cierran transferencias y P25 la navegación tenant responsive. **P26 es el siguiente bloque. P09 ajuste visual QR compacto: commit `58aba11`**.

- **P01 — Registrarme: COMPLETADO.** Enlace “Registrarme / Crear mi cuenta” en `loyalty.portal.login` (`resources/views/loyalty/portal/login.blade.php:14`) hacia `portal-clientes/{company}/registro`.
- **P02 — Autorregistro: COMPLETADO.** `LoyaltyPortalSessionController::register` crea cliente activo (`is_active=true`) disponible en `clientes`, `pos.customers.search` y Fidelización, dentro de la empresa de la URL (`portal-clientes/{company}`), vía `Customer` + `LoyaltyPortalCredential`; sin factura/incentivo/QR individual. Rutas `loyalty.customer.register` / `register.store` (`routes/web.php:139`, `throttle:10,1`).
- **P03 — Deduplicación + bloqueo por conflicto: COMPLETADO.** Antes de crear, busca por `identification` / `phone` normalizado (`PhoneNumberService`) / `email` lower dentro de la empresa; si algún dato coincide, enlaza al cliente existente en vez de duplicar. **Si dos identificadores apuntan a clientes distintos (ej. identificación→A y teléfono→B, o correo→C distinto de teléfono→B), bloquea** con mensaje seguro `Los datos proporcionados coinciden con clientes distintos...`, sin fusionar, sin crear `Customer` ni `LoyaltyPortalCredential`, sin crear credencial. Aislamiento multiempresa obligatorio probado (identificación `ID-123` duplicada en empresas distintas crea registros separados).
- **P04 — Visibilidad POS: COMPLETADO (evidencia actual).** Cliente nuevo queda activo y es encontrado por `PosController::searchCustomers` (`pos.customers.search` LIKE `name/identification/phone/mobile/email`) con `pos.acceder` y sesión `active_company_id/active_branch_id`. Evidencia: `LoyaltyPortalSelfRegistrationTest::test_register_creates_new_active_customer_and_credential` y `test_new_customer_appears_in_pos_search` (por nombre y por `8888` normalizado).
- **P05 — Cuenta fidelización al autorregistrarse: COMPLETADO.** `LoyaltyPortalSessionController::register` crea/activa `LoyaltyAccount` vía `LoyaltyAccountService::getOrCreateAccount` dentro de la misma transacción; sin bono/incentivo. Evidencia: `LoyaltyPortalSelfRegistrationTest::test_register_creates_loyalty_account_automatically` con `loyalty_accounts` `balance 0.0000`.
- **P06 — Crear acceso Portal desde Clientes y POS rápido: COMPLETADO.** `CustomerController::createPortalAccessForCustomer` (`clientes.store` con `create_portal_access`) y `PosController::createPortalAccessForQuickCustomer` (`pos.customers.quick-store` con `create_portal_access`) generan `LoyaltyPortalCredential` aislado por `company_id` con usuario derivado de nombre/teléfono y contraseña temporal única mostrada una vez; no crea acceso si cliente ya tiene credencial activa y reutiliza `PhoneNumberService`. Checkbox en `clientes/_form.blade.php` y `pos/index.blade.php` bajo permisos `clientes.crear`/`pos.acceder`.
- **P07 — Contraseña temporal única con cambio obligatorio: COMPLETADO.** `LoyaltyPortalCredential.must_change_password` (`2026_08_29_000001_add_must_change_password_to_loyalty_portal_credentials`, default false) en `true` al crear acceso P06, `LoyaltyPortalSessionController::login` redirige a `loyalty.customer.password.force`, `home` bloquea hasta cambiar, `forceChangeForm`/`forceChange` valida `PasswordRule::min(8)->letters()->mixedCase()->numbers()` y limpia flag; vista `loyalty/portal/force-change.blade.php` responsive. Contraseña no genérica: cada credencial tiene hash distinto y tests verifican `must_change_password=true`.
- **P08 — Entrega de acceso al cajero: COMPLETADO.** `LoyaltyPortalDeliveryService::build` genera `portal_url` (`route('loyalty.customer.login', $company)` aislada por `company_id`), `whatsapp_url`/`whatsapp_phone` vía `PhoneNumberService::forWhatsApp` (normalizado, digits only), `copy_text`/`message` con URL+usuario+contraseña temporal + aviso de cambio obligatorio. `CustomerController::store` añade entrega a flash `portal_access` (solo un request, no persiste plain), `PosController::storeQuickCustomer` añade entrega a JSON `portal_access`. Vistas: `clientes/index.blade.php` banner responsive `portal-delivery` con Copiar (clipboard) y WhatsApp (solo abre `https://wa.me/...` prellenado, no envía automático), `pos/index.blade.php` `quickCustomer.delivery` modal responsive con Copiar/WhatsApp/Continuar. QR no adelantado (P09B pendiente). Aislamiento multiempresa verificado.
- **P09 — Pantalla central Portal (URL general): COMPLETADO.** `LoyaltyPortalManagementController::index` genera `portalUrl` por empresa (`route('loyalty.customer.login', $company)`) y `portalQr` local `LoyaltyPortalAccessService::qrSvg` (chillerlan, sin API externa, H/ECC). Vista `loyalty/portal-management/index.blade.php` `acceso-general` muestra URL general, botón Copiar URL, Vista previa (nueva pestaña), QR vectorial e Imprimir QR. Aislamiento: URL contiene `company->id`, no mezcla empresas. Nav incorpora `Acceso general` sin duplicar sidebar.
- **P09A — Código público único cliente: COMPLETADO.** Migración `2026_08_29_000002_add_public_code_to_customers` (`public_code` 12 nullable + unique `company_id+public_code`), `Customer` fillable + `booted::creating` con `CustomerPublicCodeService` (8 chars A-Z0-9, CSPRNG, reintento por colisión, sin exponer `id`/cédula/teléfono/email). `ensure()` para legacy y `isSensitiveLeak()` validado. Aislamiento por empresa y no leak verificado.
- **P09B — QR + Code128 individual: COMPLETADO.** `CustomerPublicCodeService::qrSvg` (chillerlan H, `QRMarkupSVG`) y `barcodeSvg` (picqer Code128 SVG) 100% locales, sin API externa. `clientes/show.blade.php` `Identificación pública` muestra código, QR y Code128 con Copiar/Imprimir responsive. No expone identificación/teléfono/email, solo `public_code`.
- **P09C — Escaneo QR/Code128 en POS: COMPLETADO.** `PosController::searchCustomers` incluye `public_code` (like + order `public_code = ?` exact primero) y retorna `public_code` en payload. `pos/index.blade.php` agrega botón escáner cliente (≥44px, `cameraScannerAvailable`) y `onMvsScan` async: si código 6-12 alfanumérico busca `pos.customers.search` exact `public_code` → `selectCustomer`, sino cae a `searchProducts`. Mantiene búsqueda manual, aislamiento por empresa, sin exponer datos sensibles.
- **P09D — PIN/QR temporal de un solo uso: COMPLETADO.** Tabla `customer_one_time_tokens` (`token_hash` SHA256 único, `expires_at` 5min, `used_at`, `purpose` `redeem`), `CustomerOneTimeTokenService` genera PIN 6 dígitos + QR local (chillerlan) y `verify` valida expiración y single-use atómicamente (`used_at` no null → 422). `clientes/show` genera/muestra PIN+QR y verifica. `isStaticQrTrustedForRedeem=false` — QR estático nunca basta para canjes.
- **P21 – Separación Platform Admin / Tenant Admin: COMPLETADO en a60425f** (`a60425f684e11fd0629a42ac90fe6f25e5d31a35` – Platform Admin / Tenant Admin separados, `platform:admin --create`, `LoginController`, `BranchController`).
- **P22 – Onboarding empresa + primera sucursal + primer administrador: COMPLETADO en a60425f** (`a60425f684e11fd0629a42ac90fe6f25e5d31a35` – `CompanyProvisioner`, `CompanyController`, `EnsureActiveCompany`, `BranchController`).
- **P23 – Auditoría de transferencias existentes: COMPLETADO.** Auditoría de la implementación preexistente: Kardex (`transfer_out`/`transfer_in`), ID `TR-` y stock preservados; el movimiento de inventario se centraliza en `InventoryPostingService::postTransfer` (4 decimales, locking, rollback atómico) sin reconstruir la transferencia.
- **P24 – Probar origen/destino, stock, Kardex, permisos + decisión: COMPLETADO.** `InventoryTransferP24Test` 7/7, 54 aserciones; scoping empresa/sucursal, 4 decimales, rollback atómico, permisos `inventario.transferir` + `inventario.ver_otras_sucursales` + middleware `active.branch`. Decisión: transferencia **instantánea** (`status=completed`, `transferred_at` inmediato); NO se implementó envío/recepción.
- **P31/P32 — COMPLETADOS ADELANTADAMENTE por autorización expresa.** P31 reutiliza Centro de Datos, PhpSpreadsheet, exportación D09, patrones de Compras/Inventario y `PhoneNumberService`. P32 deja Clientes con plantilla XLSX, importación XLSX/XLS/CSV, preview, validación fila/campo, deduplicación por identificación/teléfono/correo dentro de `company_id`, confirmación atómica y exportación existente. `CustomerImportP32Test` 6/6, 42 aserciones; regresión relacionada 23/23, 139 aserciones. Clientes no tiene `branch_id`: su ámbito real es empresa.
- **P33 — COMPLETADO ADELANTADAMENTE por autorización expresa; importador reforzado.** Productos reutiliza Centro de Datos, PhpSpreadsheet y `DataExportService`: XLSX/XLS/CSV, preview sin escrituras, errores fila/campo, resolución normalizada de categorías/marcas/unidades por `company_id` y creación de faltantes solo al confirmar. Catálogos y productos comparten transacción y rollback; costo conserva hasta cuatro decimales (`412.9412`) y los precios mantienen dos. Es catálogo puro: no crea `branch_product`, no cambia stock ni genera movimientos. `ProductImportP33Test` 11/11, 122 aserciones; regresión focal 41/41, 330 aserciones.
- **P34/P35 — COMPLETADOS ADELANTADAMENTE por autorización expresa.** `HistoricalSaleImportService` aporta plantilla/importación XLSX/XLS/CSV, preview, errores por fila/campo/documento, conciliación de encabezado+líneas, resolución empresarial de sucursal/cliente/producto, idempotencia por `company_id+sale_number` y confirmación transaccional. `sales.is_historical` distingue el historial y bloquea anulaciones/devoluciones operativas. No crea caja, pagos, CxC, stock, Kardex, puntos ni comunicaciones. Exportación equivalente disponible en D09. `HistoricalSaleImportP34P35Test` 6/6, 64 aserciones; regresión relacionada 55/55, 377 aserciones.
- **P36 — COMPLETADO ADELANTADAMENTE por autorización expresa.** `InventoryMigrationImportService` aporta plantilla/importación XLSX/XLS/CSV, preview, errores fila/campo, resolución producto+sucursal, conciliación decimal y exportación equivalente. `inventory_migration_batches` conserva origen/usuario/fecha con unicidad por empresa para idempotencia. `InventoryPostingService` fija el saldo inicial con locking y registra Kardex `initial_balance`; `historical_entry/exit` conserva fecha/cadena anterior→nuevo sin cambiar `branch_product`. Sin ventas, compras, caja, pagos, CxC ni fidelización. `InventoryMigrationP36Test` 6/6, 62 aserciones; regresión relacionada 51/51, 401 aserciones.
- **P37–P40 — COMPLETADOS ADELANTADAMENTE por autorización expresa.** P37 mantiene su plantilla simple de 4 columnas y añade operación masiva segura: evidencia opcional exacta para desambiguar, consolidación conservadora por cliente, pendientes trazables que no bloquean válidos, confirmación parcial y resoluciones manuales persistentes; movimientos vía `LoyaltyAccountService`, idempotencia, rollback y bloqueo de cuentas operativas. P38–P40 conservan paquete, preview/reintento y conciliación documentada.
- **P37.1 — Portal Comercial Premium de Fidelización: FASE 1 EN CURSO; A–H COMPLETADOS, K PENDIENTE.** A/B/C preservan imágenes, formulario y carrusel. D añade CTA seguro y producto Core opcional con ficha comercial/disponibilidad simple sin stock exacto. E añade redes HTTPS validadas sin APIs. F usa el saldo y fecha reales de F22/F23. G calcula progreso contra el siguiente premio activo. H aplica nombre, logo, icono, colores, bienvenida y destacado por `company_id`, con fallback empresarial y firma MVS. Sin catálogo general, carrito, pago, PWA, push, campañas ni Passkeys en esta ejecución. Evidencia focal: `LoyaltyPortalManagementTest` + `LoyaltyPortalPremiumP371Test`; migraciones `2026_09_03_000001` y `2026_09_03_000002`.
- **Principios P37.1:** "MVS Portal no es una tienda. Es un portal de fidelización capaz de convertir una oportunidad en una compra." Fidelización detecta oportunidades y facilita venta/retención; MVS recomienda y cada empresa controla sus incentivos. Aislamiento SaaS y mobile-first obligatorios.
- **Mejora transversal de plantillas P31–P40:** las cinco plantillas de entrada (Clientes, Productos, Ventas históricas, Inventario/Kardex y Fidelización) incluyen `INSTRUCCIONES`, formatos de texto para identificadores/códigos/teléfonos/CABYS, formatos decimales solo en cantidades y montos, y desplegables basados en valores cerrados reales. Productos usa categorías, marcas y unidades activas de la empresa; impuesto conserva el contrato abierto 0–100. Los importadores heredados y la lógica de negocio no cambiaron. Evidencia: `MigrationTemplateSafetyTest` 2/2, 32 aserciones; regresión P31–P40 focal 40/40, 423 aserciones.
- Evidencia P01–P09D: `LoyaltyPortalSelfRegistrationTest` **11/11, 52 aserciones** + `LoyaltyPortalClientAccessTest` **11/11, 55 aserciones** + `LoyaltyPortalDeliveryTest` **7/7, 51 aserciones** + `LoyaltyPortalCentralTest` **4/4, 17 aserciones** + `CustomerPublicCodeTest` **5/5, 23 aserciones** + `CustomerQrBarcodeTest` **4/4, 16 aserciones** + `CustomerPosScanTest` **3/3, 13 aserciones** + `CustomerOneTimeTokenTest` **4/4, 17 aserciones, 0 fallos** (PIN 6 dígitos, single-use, expiración, aislamiento, static QR no confiable). `LoyaltyCustomerPortal` 13/13.

## Estado actual de Fidelización

Fuente oficial del orden de fases: `docs/Cronograma_Maestro_Fidelizacion_MVS_Commerce_Actualizado_23-08-2026.xlsx`, reflejada en `docs/CRONOGRAMA_FIDELIZACION.md`.

**F01–F45: COMPLETADO** según el cronograma maestro (F28 de forma adelantada). Todas las etapas de Fidelización están completas, sujeto al detalle en `docs/PROGRESO.md`.

Último hito confirmado:

**F45 — Respaldo GitHub: COMPLETADO.**

- F43 (`3efe76f`) y F44 (`5decbca`) publicados en `origin/feature/pos` como puntos de recuperación exclusivos.
- Antes del registro final: HEAD local/remoto sincronizados, working tree limpio y `git diff --check` correcto.
- Suite final de Fidelización: 305 tests, 1942 aserciones, 0 fallos.
- El commit documental exclusivo de F45 completa el cronograma F01–F45; verificar push y árbol limpio en Git al tomar el relevo.

Hito anterior:

**F36 — Acumulación online: COMPLETADO.**

- Capa mínima sobre venta real confirmada (`accrueForSale`) reutilizando F08/F12/F13 y bonos F10/F11 sin duplicar lógica; misma cuenta central `(company_id, customer_id)`, sin cuentas web paralelas.
- Idempotencia determinista `online_sale:{canal}:{ref}:loyalty:earn`; origen online auditado en metadata del movimiento sin columnas nuevas. Sin cliente identificado no acredita. Sin inventario/tienda/API.
- Evidencia: `LoyaltyOnlineSaleTest` (11 tests); regresión al cierre: 276 tests, 1846 aserciones, 0 fallos.

Hitos anteriores:

**F35 — Promociones del portal: COMPLETADO.** Tabla `loyalty_promotions`, administración bajo permiso `fidelidad.promociones` y sección "Promociones vigentes" independiente de los multiplicadores F12. Evidencia: `LoyaltyPromotionTest` (6 tests).

**F33 — QR + F34 — Acceso por enlace seguro: COMPLETADOS.** Token solo como hash SHA-256, ruta pública con throttle, QR local SVG que nunca se persiste y muere automáticamente con su enlace. Evidencia: `LoyaltyPortalAccessTest` (7) + `LoyaltyPortalAccessQrTest` (5).

**F30–F32 — Portal del cliente, identidad visual y marca MVS Commerce: COMPLETADOS** (detalle en `docs/PROGRESO.md`).

Además:

- **F38 — Administrador: COMPLETADO** (etapa 12. Permisos).
- **F39 — Cajero: COMPLETADO** (etapa 12. Permisos).
- **F40 — Indicadores: COMPLETADO** (etapa 13. Dashboard).
- **F41 — Empresa / sucursal: COMPLETADO** (etapa 13. Dashboard).
- **F42 — Suite de pruebas: COMPLETADO** (etapa 14. Calidad).
- **F43 — UI / usabilidad: COMPLETADO** (etapa 14. Calidad).
- **F44 — Regresión: COMPLETADO** (etapa 14. Calidad).
- **F45 — Respaldo GitHub: COMPLETADO** (etapa 15. Cierre).
- No quedan fases pendientes en el Cronograma Maestro de Fidelización F01–F45.
- **F28 — Reversión de puntos por anulación: COMPLETADO de forma adelantada** durante la integración POS (`7be1f80`).

Evidencia histórica: `8392dd4` (canje de puntos) y `7be1f80` (integración de fidelización en POS). Auditoría posterior a F18: 152 tests Loyalty/POS-Loyalty con 0 fallos. Tras F22: regresión Loyalty en verde (134 tests) más POS-Loyalty (48 tests); vencimiento configurable: 7 tests, 62 aserciones. Tras F23: `LoyaltyExpirationTest` (13 tests, 78 aserciones); regresión Loyalty + POS-Loyalty (177 tests, 1160 aserciones) en verde. Tras F24-F25: `LoyaltyRuleCenterTest` (6) y `LoyaltyManualAdjustmentTest` (10). Tras F26-F27: `LoyaltyMultiBranchTest` (5 tests, 40 aserciones); regresión Loyalty + POS-Loyalty (198 tests, 1295 aserciones) en verde. Tras F29: `SaleReturnLoyaltyTest` (9 tests, 79 aserciones); regresión Devoluciones+F28+Loyalty+POS-Loyalty (228 tests, 1481 aserciones) en verde.

Las denominaciones F18A–F18F se usaron durante el desarrollo pero no están etiquetadas dentro del repositorio; no inventar correspondencia exacta de letras.

---

## Estado actual del Centro de Datos

Fuente de verdad: `docs/centro-datos/CENTRO_DATOS_CRONOGRAMA.md` y `docs/centro-datos/Cronograma_Maestro_Centro_de_Datos_MVS_Commerce_v2.xlsx`.

- **D00 — Auditoría existente: COMPLETADO.**
- **D01 — Contratos de plantillas MYM: EN CURSO EN PARALELO.** No bloqueó el shell y sigue siendo obligatorio antes de crear importadores nuevos dependientes de formatos reales.
- **D02 — Centro de Datos base: COMPLETADO.** Entrada única mobile-first con Inicio, Importar, Exportar y Reportes; permisos existentes por capacidad y una sola entrada en la navegación compartida.
- **D03 — Caracterización Compras + blindaje Inventario: COMPLETADO.** Compras Excel/XML quedó cubierta directamente sin reemplazar su lógica; POST XML protegido. Inventario ahora valida y previsualiza sin mutar, usa stock/barcodes reales, resuelve catálogo por empresa y confirma transaccionalmente mediante `InventoryPostingService` con `inventario.ajustar`.
- **D09 — Exportadores esenciales: COMPLETADO.** XLSX/CSV de productos, clientes, proveedores, inventario, CxC, CxP y fidelización, aislados por empresa/sucursal y protegidos por exportación + lectura del dominio.
- **D10 — Centro de Reportes esenciales: COMPLETADO.** Reportes internos de Ventas, Inventario, Caja/Finanzas, Compras/Proveedores, Clientes y Fidelización, con filtros empresariales, permisos por dominio y enlaces a D09.
- Evidencia: `DataCenterShellTest` 6/6 (47 aserciones), regresión navegación/Compras 39/39 (210 aserciones), build Vite y `git diff --check` correctos.
- D04–D08 permanecen pendientes de contratos/plantillas MYM de D01. **SIGUIENTE EN ORDEN: D11–D12 — Históricos opcionales**, no iniciados y sujetos a necesidad/contratos aprobados.

---

## Rama actual

`feature/notas-credito` en el worktree `mvs-commerce-paralelo-3`. Cadena NC: `b748c5d → e035cea → 7bb6b2d → 85907e9 → 3f55ba9 → 58bc902 → a734f5d → a5ed4ae → 9b55929 → 4737a93 → 82c82db → 93aa4cc → e682e9a → 3f59e67 → 5ca8389`; integración `645e564` + hotfix `5246e38` (4A) y merge 4B `40685e86a086c152d178e511af1c0ba454fadb24` en `integration/notas-credito`, **Fase 4A y 4B desplegadas a producción (PRODUCTION_HEAD `40685e86a086c152d178e511af1c0ba454fadb24`)**. `feature/pos` continúa siendo la rama principal del resto del trabajo.

## Estado del repositorio

R02 (POS móvil + escaneo) COMPLETADO y R03 (Productos/Inventario móvil + cámara) COMPLETADO. R03 añadió: `ProductController::search()` enriquecido (busca en `product_barcodes` secundarios, retorna `sale_price`, `cost`, `branch_stock`), `productos/index.blade.php` responsive mobile-first (tarjetas con código/precio/stock, cámara junto al buscador, listener `mvs-scan`), `inventario/index.blade.php` responsive mobile-first (tarjetas con stock/mín/máx y estado, cámara, listener `mvs-scan`), ambos incluyen `<x-scanner.mvs-scanner />`. Sin backend adicional (se reutilizó `productos.search`). Sin header/sidebar. Evidencia: `PosCameraScannerTest` 9/9, regresión POS/Loyalty: 180 tests / 1126 aserciones, mismos fallos preexistentes; `npm run build` correcto. Siguiente fase responsive: **R04**; siguiente fase funcional de Fidelización: **F39** (orden intacto). Verificar siempre con `git status` antes de trabajar.

## Objetivo actual

Continuar el desarrollo de las prioridades principales:

1. POS.
2. Fidelización.

Mantener Caja estable e integrar correctamente los módulos existentes.

## Último trabajo terminado

Según historial reciente de commits en esta rama:

- **Fase 4B Consumer Final NC: PRODUCCIÓN** (2026-09-22). Fuente certificada `5ca8389` (cierre documental, cadena `82c82db → 93aa4cc → e682e9a → 3f59e67 → 5ca8389`); merge integración certificado `40685e86a086c152d178e511af1c0ba454fadb24`; producción actual `40685e86a086c152d178e511af1c0ba454fadb24`. Migraciones `2026_09_20_000003` + `2026_09_22_000001` aplicadas; smoke Demo PASS (emisión Consumer Final, aplicación POS bearer, rotación, reversión); NC nominativa preservada; seguridad/multitenancy/contabilidad PASS; NC fuera de PaymentMethod/SalePayment/CashMovement; datos reales preservados; smoke únicamente Empresa Demo. Incidente de deploy documentado (6 HTTP 500 transitorios 10:30–10:32 CST, swap no atómico + route-cache; 0 posteriores, no defecto 4B).
- **Fase 4A vigencia configurable NC: feature `9b55929`** (recuperado post-apagado, 11 archivos, 895 inserciones) → documentación previa `4737a93` → **integración `645e564`** → **hotfix visual POS `5246e38`** → **deploy producción CERTIFICADO** (2026-09-20, PRODUCTION_HEAD `5246e38`);
- **Fase final NC: commit `a734f5d`** (flujo POS/UI/receipt/reversals, 18 archivos, 1605 insertions); documentación NC `a734f5d` (docs);
- Fase 3B NC-POS: commit `58bc902` (documentación POS backend);
- **Fase 3A NC-POS: commit `3f55ba9`** (integración backend NC con POS, 9 archivos, 1351 insertions);
- Fase 2C reversión NC↔CxC: commit `85907e9` (reversión formal de compensaciones);
- Fase 2B conciliación NC↔CxC: commit `7bb6b2d` (conciliación automática, 13 archivos, 1453 insertions);
- Fase 1 núcleo NC: commit `e035cea`;
- canje de puntos de fidelización (`8392dd4`);
- pedidos internos (`Order`) y órdenes de compra con conversión a compras;
- integración de caja con POS;
- P23/P24 transferencias: auditoría de la implementación existente + pruebas en `InventoryTransferP24Test` (7/7, 54 aserciones), centralización del movimiento de stock/Kardex en `InventoryPostingService::postTransfer` (4 decimales, locking, rollback atómico) y scoping/permiso por sucursal; decisión: transferencia instantánea (no envío/recepción);
- P31–P36 adelantados por autorización: infraestructura reutilizada y flujos completos de Clientes, Productos, Ventas históricas e Inventario/Kardex; P36 separa saldo inicial de historia sin impacto actual;
- documentación: `INTEGRACIONES.md` completado, módulos nuevos registrados en arquitectura/progreso y este archivo creado.

## Trabajo en curso

- **Notas de Crédito — Fase 4B Consumer Final: PRODUCCIÓN** (2026-09-22, PRODUCTION_HEAD `40685e86a086c152d178e511af1c0ba454fadb24`). 4A/Nominativa `5246e38` y 4B Consumer Final `40685e86` desplegadas. Incidente deploy registrado (ventana 10:30:16–10:32:33 CST, causa swap no atómico + route-cache, 0 posteriores). **Regla operativa futura: deploys anunciados previamente, ventana aceptada ≤2 min, no iniciar ventana sin confirmación; futuro: deploy atómico + soporte Offline.**
- Puesta en Producción: **P01–P25 y P31–P40 COMPLETADOS** (P31–P40 adelantados por autorización expresa). P25 unificó la navegación tenant en barra inferior para escritorio/tablet/móvil, mantuvo Panel Maestro separado y corrigió geografía/logo del onboarding solicitados. **P26 SIGUIENTE BLOQUE OFICIAL**. **Regla producción: desarrollo → validación local del usuario → APROBADO PARA PRODUCCIÓN → despliegue controlado.**
- Centro de Datos: D00, D02, D03, D09 y D10 completados; D01 continúa en paralelo con plantillas MYM. D04–D08 permanecen bloqueados por contratos; D11–D12 no se iniciaron.
- Fidelización: **cronograma F01–F45 completo**; no existe una fase siguiente dentro del maestro vigente.
- R01 — Navegación responsive: COMPLETADO (`9c03912`).
- R02 — POS móvil + escaneo: **COMPLETADO** (R02-A + R02-B escáner por cámara).
- R03 — Productos/Inventario móvil + cámara: **COMPLETADO** (responsive mobile-first, cámara integrada en ambas vistas, `productos.search` enriquecido). Pendiente commit junto con R02. Siguiente fase responsive: **R04**.
- POS: expansión activa (uno de los módulos principales).

## Próximo paso

Antes de programar cualquier tarea nueva:

1. leer `AGENTS.md`, `docs/PROGRESO.md` y este archivo;
2. verificar `git status`, rama y último commit;
3. inspeccionar el código real del módulo afectado;
4. confirmar con el usuario cuál es la tarea concreta si no está definida.

**Prioridad siguiente según `docs/CRONOGRAMA_PRODUCCION.md`: P26 — Nombres claros 58 mm, 80 mm, Carta, etc. (siguiente bloque oficial). Fidelización F01–F45 completo; R04 siguiente fase responsive.**

**P26 — Nombres claros 58 mm, 80 mm, Carta, etc. P31–P40 quedaron completados adelantadamente por autorización expresa y no desplazan P26–P30.**

## Archivos o módulos relevantes

- POS: `PosController`, `PosSaleProcessor`, `Sale`, `SaleItem`, `SalePayment`.
- Notas de Crédito: `CreditNote`, `CreditNoteApplication`, `CreditNoteService`, `AccountsReceivableReconciliationService`, `AccountReceivableAdjustment`.
- Fidelización: `app/Services/Loyalty/*`, `LoyaltyAccount`, `LoyaltyMovement`, `LoyaltyMovementLine`, `LoyaltyReward`, `LoyaltyRewardRedemption`, `LoyaltyPromotion`.
- Caja: `app/Services/Cash/*`, notificaciones por correo con reintentos.
- Pedidos/órdenes: `OrderService`, `PurchaseOrderPreparationService`, `PurchaseOrderConversionService`.
- Apartados: `LayawayService`. Devoluciones: `SaleReturnService`. Pagos a proveedores: `AccountsPayableService`.

## Pruebas importantes

Suite principal: `tests/Feature`.

- POS: `PosCheckoutTest`, `PosSuspendedSalesTest`, `PosCashSessionIntegrationTest`, `PosAccessAndSearchTest`.
- Notas de Crédito: `PosCreditNoteTest` (42/42), `CreditNoteTest` (19/19), `CreditNoteReconciliationTest` (18/18), `AccountsReceivableReversalTest` (23/23).
- Navegación: `ResponsiveNavigationTest`, `LoyaltySettingsSidebarNavigationTest`.
- Fidelización: `tests/Feature/Loyalty*Test.php` (incluye `LoyaltyExpirationTest`, `LoyaltyExpirationSettingTest`, `LoyaltyCustomerPortalTest`, `LoyaltyPortalAccessTest`, `LoyaltyPortalAccessQrTest`, `LoyaltyPromotionTest`, `LoyaltyOnlineSaleTest`, `LoyaltyOnlineRedemptionTest`), `PosCheckoutLoyaltyPointsRequestTest`, `PosCheckoutLoyaltyRedemptionTest`, `PosLoyaltyInterfaceTest`, `PosLoyaltyMixedPaymentsTest`, `SaleVoidLoyaltyTest`, `LoyaltySettingsSidebarNavigationTest`. Premios, disponibilidad, canjes y vencimiento: `LoyaltyRewardTest`, `LoyaltyRewardAvailabilityTest`, `LoyaltyRewardRedemptionTest`.
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

## Fallos históricos conocidos (no atribuibles a P24)

Estos fallos son preexistentes de HEAD (deriva `backend–tests` documentada en `docs/PROGRESO.md` R02) y **no** son causados ni por P24 ni por sus archivos (`TransferController`, `InventoryPostingService`, `InventoryTransferItem`, rutas `transferencias*`, migración de precisión, `InventoryTransferP24Test`). No se corren en el alcance de P24; se dejan registrados para no confundirlos con regresiones nuevas:

- `PosAccessAndSearchTest::test_search_finds_by_name_and_returns_minimal_payload` — el payload de `pos.customers.search` incluye claves adicionales (`allows_decimals`, `is_offer`, `price_a`, `price_b`, `price_c`, `unit`, `wholesale_price`) que el test no espera. `PosController`, no modificado por P24.
- `PosAccessAndSearchTest::test_checkout_modal_has_responsive_permanent_summary_and_dynamic_direct_payment_flow` — el HTML del modal/checkout POS difiere del snapshot esperado. Vista `pos/index.blade.php`, no modificada por P24.
- `PosAccessAndSearchTest::test_customer_search_returns_only_the_authorized_minimal_fields` — el payload de `pos.customers.search` incluye claves adicionales (`credit_due_date`, `credit_used`, `price_level`, `public_code`) que el test no espera. Crecimiento del payload atribuible al trabajo Portal (P04–P09A), no a P24.

También documentados como deriva preexistente: `PosSuspendedSalesTest::test_recovery_revalidates_customer_product_price_tax_stock` (`125` vs `'125.00'`) y `OrderPosCreationTest::test_pos_button_and_cashier_product_payload_are_permission_safe` (302 vs 200); ambos en módulos POS/Órdenes no tocados por P24.

## Instrucción para el siguiente agente

Reconstruye el contexto desde el repositorio, nunca desde memoria conversacional:

1. lee `AGENTS.md` y `docs/`;
2. revisa `git status`, rama y últimos commits;
3. identifica el módulo y sus pruebas;
4. trabaja con cambios mínimos, ejecuta pruebas y deja el repo y la documentación en estado comprensible para el siguiente agente;
5. actualiza este archivo si tu tarea cambia rama, prioridades o deja trabajo a medias.
