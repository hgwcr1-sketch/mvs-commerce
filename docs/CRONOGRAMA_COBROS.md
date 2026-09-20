# MVS CobrOS — Cronograma Maestro de Desarrollo

Fuente oficial del orden y alcance de las fases de MVS CobrOS en la rama
`feature/cobros-v1` (worktree `mvs-commerce-cobros`). Complementa
`docs/COBROS_ARQUITECTURA_INICIAL.md` (decisiones) y `docs/PROGRESO_COBROS.md`
(evidencia de avance). El cronograma define QUÉ corresponde hacer; el código,
las pruebas y los commits definen qué está realmente hecho.

## Decisiones definitivas que este plan respeta

- CobrOS es una aplicación independiente PARA EL USUARIO: login/acceso propio,
  logo e identidad propios, Portal del Dueño, experiencia móvil/PWA del
  cobrador y navegación propia. No aparece en sidebar de Commerce ni enlaza
  de regreso (Fase 1A.1).
- Arquitectura interna: monolito modular compartiendo modelos/servicios de
  MVS Commerce. CobrOS NO crea clientes, cuentas por cobrar, facturas ni
  inventario paralelos: reutiliza Customer, AccountReceivable,
  AccountReceivablePayment, Sale, Company, Branch, autenticación, auditoría y
  los servicios financieros existentes cuando corresponda.
- MVS RuteOS permanece integrado en Commerce. NO mezclar RuteOS con CobrOS.
- LOCAL → pruebas → auditoría → commit → integración/deploy controlado →
  PRODUCCIÓN. Nunca desarrollar directamente en producción.
- Orden financiero obligatorio: los cobros reales van DESPUÉS de estructura
  de cobradores + asignación de cartera + cartera/visitas, y solo cuando
  idempotencia, transacción, caja/métodos de pago y recibo/auditoría estén
  diseñados y probados.

## Fases completadas y certificadas

| Fase | Contenido | Commit | Estado |
|------|-----------|--------|--------|
| 0/0B | Auditoría y arquitectura inicial | `28bb469` | COMPLETADA |
| 1A | Fundación de acceso aislado (módulo, permisos, middleware, pruebas) | `e4d4b6a` | COMPLETADA |
| 1A.1 | Separación total de navegación Commerce/CobrOS | `9875c39` | COMPLETADA |
| 1B | Identidad propia + PWA base (logo, manifest `/cobros`, iconos) | `c12d9b2` | PASS_WITH_VALIDATION_PENDING (solo build/instalación real) |
| 1C | Portal del Dueño — dashboard cartera SOLO LECTURA | `f7c779c` | PASS / READY_FOR_PHASE_1D |

Fase 1C incluye: total por cobrar, total vencido, cobrado hoy, clientes con
saldo, próximas a vencer, vencidas, filtro por sucursal, aislamiento
multiempresa, BCMath/decimal:4, timezone de empresa y cero escrituras
financieras.

## Reglas inviolables de desarrollo

1. Nunca ejecutar `migrate:fresh`, `migrate:refresh`, `migrate:reset` ni
   rollbacks sobre la base de desarrollo. Migraciones solo pendientes y no
   destructivas.
2. Usar la base maestra local persistente
   (`C:\Users\USER000\MVS Commerce\local-data\mvscommerce-dev.sqlite`) con
   respaldo previo antes de aplicar migraciones nuevas.
3. Aislamiento multiempresa obligatorio en toda consulta
   (`forCompany`/`active_company_id`); ninguna empresa puede ver datos de otra.
4. Dinero exclusivamente con decimal/BCMath (`decimal:4`), nunca float.
5. No duplicar lógica financiera: reutilizar `AccountsReceivableService`,
   modelos y servicios existentes; CobrOS agrega capa segura, no un segundo
   sistema.
6. No desarrollar directamente en producción; flujo LOCAL → pruebas →
   auditoría → commit → deploy controlado.
7. No mezclar CobrOS con RuteOS en modelos, rutas, UI ni permisos.
8. Todo cambio nuevo se ubica primero en este cronograma; no ampliar el
   alcance de una fase sin documentarlo en `PROGRESO_COBROS.md`.
9. Cada fase termina con pruebas automatizadas (factories, datos aislados) y
   reporte de auditoría antes del commit.
10. Respetar dorado oficial `#D4AF37` y reglas responsive del proyecto
    (mobile-first, targets 44px, sin overflow horizontal).

## Definition of Done para producción

1. Implementación local completa + pruebas automatizadas verdes.
2. Auditoría de seguridad/aislamiento documentada (solo lectura, como 1A–1C).
3. Build Vite funcional en el worktree y PWA instalada en dispositivo real.
4. Staging/validación con el usuario: APROBADO PARA PRODUCCIÓN.
5. Backup verificado de la base de destino.
6. Migraciones pendientes aplicadas en staging y producción (no destructivas).
7. Deploy controlado + smoke tests (login CobrOS, dashboard dueño, un flujo
   por fase ya activada).
8. Monitoreo y plan de rollback documentados. Los agentes no despliegan
   producción automáticamente.

## Fases futuras (posterior a 1C)

Cada fase declara: objetivo, funcionalidades, modelos/servicios, migración
(SÍ/NO), riesgos, pruebas obligatorias, criterio de aceptación, qué NO
pertenece a la fase y dependencia.

### 1D — Estructura operativa de cobradores

**Estado: COMPLETADA LOCALMENTE (2026-09-20).** Suite CobrOS 34/34, 247 aserciones. Alcance específico aprobado sin migraciones ni pivotes nuevos y evidencia en PROGRESO_COBROS.md, sección Fase 1D; sustituye la previsión inicial descrita abajo.

- Objetivo: definir quiénes cobran y bajo qué empresa/sucursal operan.
- Funcionalidades: alta/gestión de cobradores y supervisores de CobrOS;
  relación cobrador ↔ empresa (+ sucursales permitidas); roles CobrOS
  (dueño, supervisor, cobrador) y permisos `cobros.*` granulares; acceso
  solo a la experiencia CobrOS (identidad 1B).
- Modelos/servicios: User/Role/Permission existentes; pivote cobrador–
  empresa–sucursal; reutiliza EnsureActiveCompany/EnsureCompanyModule/
  CheckPermission.
- Migración: SÍ (aditiva: pivote + permisos nuevos en PermissionSeeder).
- Riesgos: usuario con doble rol (Commerce + CobrOS); no usar sucursal
  activa de Commerce para CobrOS.
- Pruebas: cobrador de empresa A no opera en B; permisos granulares;
  desactivar licencia bloquea.
- Aceptación: dueño crea cobrador/supervisor con empresa+sucursal y ambos
  acceden según rol; aislamiento certificado.
- NO pertenece: asignación de clientes, agenda, cobros.
- Dependencia: 1A/1B/1C.

### 1E — Asignación de cartera
- Objetivo: qué clientes/cuentas atiende cada cobrador, sin conflictos.
- Funcionalidades: asignar clientes/cuentas a cobrador por empresa/sucursal;
  reasignación con trazabilidad (quién, cuándo, por qué); exclusividad de
  asignación activa; coherencia con sucursal del cobrador.
- Modelos/servicios: Customer, AccountReceivable; tabla aditiva
  cobros_assignments + bitácora de reasignaciones; servicio único
  `Cobros\PortfolioAssignmentService`.
- Migración: SÍ (aditiva).
- Riesgos: asignaciones históricas sin dueño; reasignación con visitas
  abiertas (regla definida en 2B).
- Pruebas: doble asignación rechazada; reasignación trazada; solo
  supervisor/dueño reasigna; aislamiento por empresa.
- Aceptación: dos cobradores asignados sin solapamiento + reasignación
  registrada.
- NO pertenece: métricas por cobrador, cobros.
- Dependencia: 1D.

### 2A — Portal del cobrador: “Mi cartera”
- Objetivo: vista operativa del cobrador con clientes y saldos propios.
- Funcionalidades: “Mi cartera” (solo lo asignado, en su empresa); saldo por
  cliente/cuenta; vencimientos; prioridad por días de vencido; ficha del
  cliente (contacto, ubicación registrada, historial de pagos de Commerce).
- Modelos/servicios: lectura AccountReceivable/Payment/Customer con
  `forCompany` + filtro por asignación; patrón PortfolioSummaryService.
- Migración: NO.
- Riesgos: fugas entre cobradores; rendimiento con carteras grandes
  (paginación).
- Pruebas: cobrador A no ve B ni otra empresa; sin permisos de dueño.
- Aceptación: cartera completa visible en móvil sin exponer datos ajenos.
- NO pertenece: agenda, visitas, cobros.
- Dependencia: 1E.

### 2B — Agenda diaria de cobranza y visitas
- Objetivo: planificar y registrar la gestión de cobro del día (NO es MVS
  RuteOS; sin compartir agenda).
- Funcionalidades: plan de visitas por cobrador (orden/estado: pendiente,
  visitado, no localizado, promesa de pago, reprogramada); observaciones;
  historial por cliente; reprogramación trazada; promesa con fecha.
- Modelos/servicios: tabla aditiva cobros_visits + `Cobros\VisitService`;
  vínculo opcional a AccountReceivable.
- Migración: SÍ (aditiva).
- Riesgos: no duplicar RuteOS (tablas/permisos con nombre distinto);
  consistencia de estados al reasignar cartera.
- Pruebas: ciclo de visita completo; solo el cobrador asignado gestiona sus
  visitas; supervisor consulta; aislamiento.
- Aceptación: visitado/no localizado/promesa registrados con historial.
- NO pertenece: cobros reales, GPS de jornada, geocercas, liquidaciones.
- Dependencia: 2A.

### 2C — Geolocalización en visitas
- Objetivo: ubicar visitas con privacidad, sin rastreo innecesario.
- Funcionalidades: coordenadas al registrar visita (opcional, con permiso
  del dispositivo); ubicación del cliente reutilizando campos R01 en
  customers (maps/waze); distancia como señal informativa, no bloqueo duro.
- Modelos/servicios: lat/lng aditivos en cobros_visits; helpers de maps
  compartidos con RuteOS (permitido por arquitectura).
- Migración: SÍ (aditiva).
- Riesgos: privacidad — captura solo al momento de la visita; consentimiento.
- Pruebas: visita con/sin ubicación; sin almacenamiento fuera de visitas;
  lectura restringida por rol.
- Aceptación: visita georreferenciada visible para supervisor/dueño.
- NO pertenece: GPS de jornada, geocercas, rutas automáticas.
- Dependencia: 2B.

### 3A — GATE: infraestructura de cobro seguro
- Objetivo: habilitar la escritura financiera con todas las garantías ANTES
  de exponer cobros en UI.
- Funcionalidades: capa segura `Cobros\SecurePaymentService` que envuelve
  `AccountsReceivableService::pay()`; clave de idempotencia por intento;
  definición de dónde queda el dinero (cobrador sin caja Commerce: sesión de
  cobros propia del día o efectivo en custodia — decisión documentada);
  efectivo + métodos no-efectivo (SINPE/transferencia/tarjeta) sin sesiones
  de caja; regla de conciliación diaria.
- Modelos/servicios: AccountsReceivableService (intacto), CashSessionResolver
  (solo si aplica caja), PaymentMethod, infraestructura de idempotencia.
- Migración: SÍ (aditiva: idempotencia; opcional sesiones de cobros).
- Riesgos: doble cobro por retry/red/doble clic (idempotencia obligatoria);
  concurrencia sobre balance_due (pay() ya usa lockForUpdate); no tocar
  lógica Commerce.
- Pruebas: misma idempotency-key dos veces → un solo pago; concurrentes;
  monto parcial exacto; método no-efectivo sin caja.
- Aceptación: suite financiera verde con retry/concurrencia + auditoría de
  revisión previa.
- NO pertenece: UI de cobro, recibos, anulaciones.
- Dependencia: 2C + aprobación explícita del diseño.

### 3B — Cobros reales y recibos
- Objetivo: registrar abonos/pago total desde CobrOS de forma segura.
- Funcionalidades: UI de cobro en ficha de cliente/visita (parcial o total);
  captura operacional explícita antes de aplicar (confirmación con detalle);
  recibo con número único por empresa (CompanySequence o equivalente), sin
  duplicados; historial de recibos; impresión/PDF/compartir según
  arquitectura MVS Print existente.
- Modelos/servicios: SecurePaymentService (3A), CompanySequence, vistas
  propias CobrOS.
- Migración: evaluar (según necesidad de secuencia de recibos).
- Riesgos: latencia móvil (cubre idempotencia); moneda consistente con la
  cuenta.
- Pruebas: parcial, total, monto mayor a saldo rechazado, doble envío → un
  recibo, número secuencial único, aislamiento.
- Aceptación: cobro parcial y total con recibos únicos; cartera del dueño
  refleja el cobro.
- NO pertenece: anulaciones, liquidaciones de efectivo.
- Dependencia: 3A.

### 3C — Auditoría financiera y anulaciones
- Objetivo: trazabilidad completa y reversión controlada.
- Funcionalidades: bitácora por cobro (quién, cuándo, empresa, sucursal,
  cliente, CxC, método, ubicación si aplica, idempotency-key); anulación con
  permiso dedicado `cobros.anular` que reconstituye balance_due vía servicio
  financiero (nunca UPDATE libre); consulta de auditoría para dueño/
  supervisor.
- Modelos/servicios: patrón RouteosAuditLog como referencia; tabla aditiva
  cobros_audit_logs.
- Migración: SÍ (aditiva).
- Riesgos: anulación tras cierre de caja del día (regla a definir).
- Pruebas: anulación restaura saldo exacto; sin permiso → 403; auditoría
  registra ambos movimientos.
- Aceptación: dueño audita y anula un cobro erróneo con saldo restituido.
- NO pertenece: liquidación de efectivo físico.
- Dependencia: 3B.

### 4A — Portal del supervisor
- Objetivo: supervisión operativa diaria.
- Funcionalidades: cartera por cobrador; visitas del día; cobrado hoy por
  cobrador; pendientes/incidencias (no localizados, promesas vencidas);
  reasignación de cartera/visitas.
- Modelos/servicios: PortfolioSummaryService + filtros por asignación;
  permiso `cobros.supervisar`.
- Migración: NO.
- Riesgos: cálculos deben partir de asignaciones vigentes.
- Pruebas: supervisor solo de su empresa/sucursales; métricas consistentes.
- Aceptación: supervisor ve y reasigna sin funciones de dueño.
- NO pertenece: reportes históricos avanzados (4B).
- Dependencia: 3B/3C.

### 4B — Portal del Dueño avanzado y reportes
- Objetivo: resultados por cobrador, sucursal y período.
- Funcionalidades: recuperación de cartera por período; antigüedad de
  vencimientos; ranking de cobradores; exportación según arquitectura;
  filtro combinado sucursal/período (reutiliza 1C).
- Migración: NO (consultas).
- Riesgos: rendimiento (lazyById ya adoptado).
- Pruebas: consistencia 1C vs 4B; aislamiento.
- Aceptación: el dueño responde “¿cuánto recuperó cada cobrador este mes?”.
- NO pertenece: comisiones (tema no definido en este cronograma).
- Dependencia: 4A.

### 5A — Notificaciones y alertas CobrOS
- Objetivo: avisos de vencimientos, promesas e incidencias.
- Funcionalidades: reutilizar Centro de Notificaciones de Commerce con canal
  CobrOS; alcance solo para roles CobrOS.
- Migración: evaluar (preferencias).
- Riesgos: no mezclar alertas de Commerce en la UI del cobrador.
- Pruebas: generación y visibilidad por rol.
- Aceptación: dueño/supervisor reciben alerta de promesas vencidas.
- NO pertenece: WhatsApp/marketing.
- Dependencia: 4A.

### 5B — PWA móvil del cobrador y conectividad
- Objetivo: instalación real, responsive y diseño para señal deficiente.
- Funcionalidades: cerrar pendiente 1B (build + instalación); UI tolerante a
  red lenta; diseño offline: la captura operacional puede guardarse como
  BORRADOR local con clave idempotente, pero el cobro financiero SOLO se
  aplica en línea vía 3A/3B; sincronización explícita con revisión. NUNCA
  confirmar cobro offline sin estrategia segura (arquitectura aprobada).
- Migración: evaluar (service worker propio de CobrOS limitado a /cobros).
- Riesgos: scope del SW sin interferir Commerce; caché de datos sensibles.
- Pruebas: instalación en dispositivo; sin conexión no aplica pago;
  sincronización idempotente al reconectar.
- Aceptación: operación en campo con mala señal sin cobros duplicados.
- NO pertenece: GPS de jornada, mapas offline.
- Dependencia: 3B (obligatoria) + 1B.

### 5C — Seguridad endurecida
- Objetivo: consolidar seguridad antes de producción.
- Funcionalidades: revisión multitenancy integral; rate limiting en rutas
  financieras; CSRF/sesiones revisadas; política de dispositivos del
  cobrador; privacidad y retención geográfica.
- Migración: NO.
- Pruebas: suite de seguridad + aislamiento extendida.
- Aceptación: auditoría de seguridad sin hallazgos bloqueantes.
- Dependencia: transversal; cierre antes de 6A.

### 6A — Pruebas integrales
- Objetivo: certificar el sistema completo.
- Funcionalidades: 2+ empresas, varias sucursales, varios cobradores;
  concurrencia real; doble clic; retry; pérdida de conexión; pagos
  parciales/totales; aislamiento y permisos completos.
- Migración: NO.
- Aceptación: suite completa verde + revisión por auditor.
- Dependencia: 5A–5C.

### 6B — Preparación producción
- Objetivo: llegar a PRODUCCIÓN de forma controlada.
- Funcionalidades: build Vite + PWA real (cierra 1B); staging/validación del
  usuario; migraciones no destructivas con backup; deploy; smoke tests;
  monitoreo/rollback. Requiere APROBADO PARA PRODUCCIÓN del usuario.
- Aceptación: Definition of Done de este documento al 100%.
- Dependencia: 6A.

## Orden resumido

1D → 1E → 2A → 2B → 2C → 3A (GATE financiero) → 3B → 3C → 4A → 4B → 5A →
5B → 5C → 6A → 6B.

Ninguna fase puede saltarse ni reordenarse sin autorización explícita del
usuario. Los cobros reales (3B) jamás se implementan antes de 3A y de tener
1D–2C completadas.