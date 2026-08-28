# MVS Commerce — Cronograma de Producción (Portal de Clientes y Puesta en Producción)

Fuente oficial del orden y numeración de bloques de Producción / Portal de Clientes.
Refleja `docs/produccion/Cronograma_Maestro_Puesta_Produccion_MVS_Commerce_v7.xlsx` y el bloque Portal de Clientes P01–P30.

> Git + código + tests determinan qué está realmente implementado. Ningún agente puede saltar, retroceder, renumerar o inventar bloques sin autorización.

---

## Regla de interpretación

- **CRONOGRAMA_PRODUCCION.md (este archivo)** = orden oficial y numeración P01–P30.
- **Git + código + tests** = evidencia.
- **ESTADO_ACTUAL.md / PROGRESO.md** = relevo y resumen.

Si cronograma y código difieren: registrar discrepancia y esperar decisión.

---

## Tabla maestra P01–P30

| ID | Bloque | Entregable | Estado | Evidencia |
|---|---|---|---|---|
| P01 | Portal – Registro | Agregar “Registrarme / Crear mi cuenta” al login del Portal de Clientes (`loyalty.portal.login` → `loyalty.customer.register`) | **COMPLETADO** | `resources/views/loyalty/portal/login.blade.php:14`, `resources/views/loyalty/portal/register.blade.php`, `LoyaltyPortalSelfRegistrationTest::test_login_shows_register_link` |
| P02 | Portal – Autorregistro | Permitir autorregistro desde Portal/QR dentro de la empresa de la URL; cliente nuevo queda `is_active=true` y disponible en `clientes`, `pos.customers.search` y Fidelización | **COMPLETADO** | `LoyaltyPortalSessionController::register:40`, `routes/web.php:139`, `LoyaltyPortalSelfRegistrationTest::test_register_creates_new_active_customer_and_credential` |
| P03 | Portal – Deduplicación | Antes de crear, evitar duplicados dentro de la misma empresa por identificación / teléfono normalizado (`PhoneNumberService`) / correo (case-insensitive); si existe, enlazar al cliente existente. **Si los tres identificadores apuntan a clientes distintos, bloquear** sin fusionar ni crear credencial, mensaje seguro | **COMPLETADO** | `LoyaltyPortalSessionController::register` conflicto P03 `uniqueIds>1` → `identification: Los datos proporcionados coinciden...`, `LoyaltyPortalSelfRegistrationTest` 10 tests (incl. `test_register_blocks_when_identification_and_phone_match_different_customers`, `test_register_blocks_when_email_matches_different_customer_than_phone` sin crear `customers` ni `loyalty_portal_credentials`) |
| P04 | Portal – Visibilidad POS | Cliente nuevo activo y visible en búsqueda POS (`pos.customers.search` like) | **COMPLETADO – evidencia actual** | `LoyaltyPortalSelfRegistrationTest::test_new_customer_appears_in_pos_search` y `test_register_creates_new_active_customer_and_credential` (`posCustomerVisible` por nombre y `8888`) |
| P05 | Portal – Incentivo de registro | No implementado – pendiente, no crear bono/puntos automáticos | **PENDIENTE** | — |
| P06 | Portal – Validaciones | Harden de validaciones de registro (límites, formatos) | **PENDIENTE** | — |
| P07 | Portal – UX registro | Pulido mobile-first del formulario de registro | **PENDIENTE** | — |
| P08 | Portal – Términos | Aceptación de términos / privacidad | **PENDIENTE** | — |
| P09A | Portal – Identidad pública única | Identidad pública única por cliente/empresa (sin exponer IDs internos) | **PENDIENTE** | No implementar todavía |
| P09B | Portal – QR individual + Code 128 | QR individual + Code 128 por cliente | **PENDIENTE** | No implementar todavía |
| P09C | Portal – Lectura desde POS | Lectura del QR/Code 128 desde POS para identificar cliente | **PENDIENTE** | No implementar todavía |
| P09D | Portal – PIN/QR temporal | PIN o QR temporal para autorizaciones sensibles (canje, crédito, etc.) | **PENDIENTE** | No implementar todavía |
| P10 | Portal – Notificaciones | Notificaciones post-registro (email/WhatsApp) | **PENDIENTE** | — |
| P11 | Producción – Centro de Etiquetas | Centro de etiquetas (ya completado en PROGRESO) | COMPLETADO | `PROGRESO.md` |
| P12 | Producción – Verificación mercadería | Verificación digital de mercadería | COMPLETADO | `PROGRESO.md` |
| P13 | Producción – Dashboard | Dashboard operativo | PENDIENTE | — |
| P14 | Producción – Reportes | Reportes esenciales | PENDIENTE | — |
| P15 | Producción – Caja | Caja y sesiones | PENDIENTE | — |
| P16 | Producción – Inventario | Inventario por sucursal | PENDIENTE | — |
| P17 | Producción – Compras | Compras e importaciones | PENDIENTE | — |
| P18 | Producción – Ventas | POS y ventas | PENDIENTE | — |
| P19 | Producción – Clientes | Clientes CRUD | PENDIENTE | — |
| P20 | Producción – Proveedores | Proveedores | PENDIENTE | — |
| P21 | Producción – Fidelización | Fidelización F01–F45 | COMPLETADO | `CRONOGRAMA_FIDELIZACION.md` |
| P22 | Producción – Separación Platform/Tenant | Separación Platform Admin / Tenant Admin (`platform:admin --create`, `LoginController`, `BranchController`, `sidebar`, `ManagePlatformAdmin.php`) – identidades separadas, promoción bloqueada, `is_platform_admin` | **COMPLETADO en a60425f** | `a60425f feat: completar onboarding de empresa y separacion platform tenant`, `ManagePlatformAdmin.php`, `LoginController.php`, `PlatformAdminTest` |
| P23 | Producción – Onboarding empresa + sucursales | Onboarding obligatorio de empresa + sucursales + primer administrador (`CompanyProvisioner`, `CompanyController`, `EnsureActiveCompany`, `BranchController`, `CompanyLicenseService`) | **COMPLETADO en a60425f** | `a60425f`, `app/Http/Controllers/CompanyController.php`, `EnsureActiveCompany.php`, `BranchController.php` |
| P24 | Producción – Licencias | Licenciamiento SaaS por empresa | COMPLETADO | `PROGRESO.md` P08L |
| P25 | Producción – Seguridad | Seguridad PostgreSQL / headers / throttles | COMPLETADO (técnico) | `PROGRESO.md` P08S |
| P26 | Producción – Backups | Backups y restore | PENDIENTE | — |
| P27 | Producción – Dominio/TLS | Dominio y TLS | PENDIENTE | — |
| P28 | Producción – Monitoreo | Monitoreo y alertas | PENDIENTE | — |
| P29 | Producción – Migración datos | Migración datos reales MYM | PENDIENTE | — |
| P30 | Producción – Go-live | Go-live y corte | PENDIENTE | — |

---

## Estado y próxima fase

- **P22 – Separación Platform/Tenant: COMPLETADO en a60425f** (`a60425f684e11fd0629a42ac90fe6f25e5d31a35` – `platform:admin --create`, identidades separadas, `LoginController`, `BranchController`, `sidebar`).
- **P23 – Onboarding empresa + sucursales: COMPLETADO en a60425f** (`a60425f684e11fd0629a42ac90fe6f25e5d31a35` – `CompanyProvisioner`, `CompanyController`, `EnsureActiveCompany`, primera sucursal + primer administrador).
- **P01–P03: COMPLETADOS** solo si las nuevas pruebas de conflicto pasan. Evidencia: `LoyaltyPortalSelfRegistrationTest` 10 tests, 46 aserciones, 0 fallos (incl. `test_register_blocks_when_identification_and_phone_match_different_customers` y `test_register_blocks_when_email_matches_different_customer_than_phone` que verifican bloqueo sin crear `customers` ni `loyalty_portal_credentials`).
- **P04: COMPLETADO – evidencia actual** – cliente nuevo queda `is_active=true` y es encontrado por `PosController::searchCustomers` (`pos.customers.search` LIKE sobre `name/identification/phone/mobile/email`), probado en `test_register_creates_new_active_customer_and_credential` y `test_new_customer_appears_in_pos_search` con `pos.acceder` y sesión `active_company_id/active_branch_id`.
- **P05 y siguientes: PENDIENTES.** No implementar P05 (incentivo), ni QR individual/Code128 (P09B), ni lectura POS (P09C), ni PIN/QR temporal (P09D) todavía.
- **P09A–P09D: PENDIENTES.** Definidos como identidad pública única, QR individual + Code 128, lectura desde POS, PIN/QR temporal para autorizaciones sensibles. No se crea QR individual antes de P09B.

---

## Regla para agentes

Antes de cualquier tarea, leer `docs/CRONOGRAMA_PRODUCCION.md` (este archivo) y no saltar bloques sin autorización. El orden P01–P30 es obligatorio.

## Control de cambios

- 2026-08-29: creado como fuente oficial P01–P30, incorpora P09A–P09D, marca P22/P23 en a60425f, registra P01–P04 completados con evidencia de `LoyaltyPortalSelfRegistrationTest` 10/10.
