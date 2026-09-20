# MVS CobrOS — Registro de avance

## Fase 1A — Completada: fundación aislada del módulo

Rama `feature/cobros-v1` en el worktree `mvs-commerce-cobros`.

### Archivos creados

- `app/Http/Controllers/Cobros/CobrosDashboardController.php` — panel inicial.
- `app/Http/Middleware/EnsureCompanyModule.php` — middleware reutilizable de
  módulo contratado por empresa (403 si no está activo).
- `resources/views/cobros/layout.blade.php` — layout propio de CobrOS,
  independiente del de Commerce (retorno eliminado en Fase 1A.1).
- `resources/views/cobros/dashboard.blade.php` — panel inicial sin datos
  financieros simulados.
- `tests/Feature/CobrosModuleAccessTest.php` — 12 pruebas, 55 aserciones.
- `docs/PROGRESO_COBROS.md` — este registro.

### Archivos modificados

- `app/Services/Modules/ModuleRegistry.php` — módulo `cobros` registrado
  ("MVS CobrOS", familia `cobros.*`).
- `database/seeders/PermissionSeeder.php` — permisos `cobros.acceder`,
  `cobros.portal_dueno`, `cobros.supervisar`, `cobros.cobrar`.
- `routes/web.php` — grupo de rutas `/cobros` (nombre `cobros.*`) separado.
- `bootstrap/app.php` — alias `company.module` → `EnsureCompanyModule`.
- `resources/views/components/navigation/sidebar.blade.php` — enlace
  "MVS CobrOS" original (eliminado en Fase 1A.1).
- `docs/COBROS_ARQUITECTURA_INICIAL.md` — decisiones técnicas confirmadas.

### Permisos creados (solo fundamentales)

- `cobros.acceder`, `cobros.portal_dueno`, `cobros.supervisar`, `cobros.cobrar`.

### Middleware aplicado a la ruta `cobros.index`

- `auth`
- `active.company` (corrige sesión de empresa ajena a la propia)
- `company.licensed` (licencia empresarial global existente)
- `company.module:cobros` (módulo contratado, 403 si no)
- `permission:cobros.acceder` (403 si no)

Sin `active.branch` a propósito: el portal del dueño opera a nivel de empresa.

### Pruebas

- `CobrosModuleAccessTest`: **12 passed, 55 assertions** (acceso, perfiles,
  activación, aislamiento, navegación).
- Regresión: `RouteosR01Test` 13/13, `CompanyModuleAccessTest` 6/6,
  `CompanyLicenseTest` 4/4, `UserRoleSecurityTest` 8/8, `CompanyOnboardingTest`
  3/3, `CompanyProvisioningTest` 6/6, `CustomerCodeTest` 9/9,
  `CustomerCompanyIsolationTest` 4/4 — **54 passed, 237 aserciones**.
- Fallo preexistente documentado: `OrderPermissionSeederTest` (falla igual en
  el commit base; el seeder ahora asigna permisos solo a roles
  `is_super_admin` desde `7fe7e79`, el test espera el comportamiento antiguo).
  No se corrige en esta fase.

### Riesgos pendientes

- Los 4 permisos `cobros.*` solo existen si se ejecuta el seeder (o se crean
  por pruebas); las empresas reales los recibirán al actualizar el catálogo
  de permisos por el mecanismo normal.
- `OrderPermissionSeederTest` desactualizado respecto al flag
  `is_super_admin` (preexistente, corrección futura aparte).
- PWA y logo final de CobrOS: aún no creados (fase posterior).
## Fase 1A.1 — Separación visual y navegacional (2026-09-20)

Decisión definitiva: CobrOS es una aplicación separada para el usuario,
compartiendo servicios internos del monolito modular.

- Eliminado CobrOS del sidebar compartido de Commerce (escritorio y menú móvil).
- Eliminados el retorno a Commerce y el sufijo Commerce del título de CobrOS.
- RuteOS, arquitectura interna, autenticación, iconos y licenciamiento intactos.
- `company.licensed` no requiere módulos Commerce: verifica que la licencia
  empresarial sea operable. Prueba HTTP con contrato exclusivamente `cobros`,
  licencia activa y `cobros.acceder`: acceso 200, sin sucursal en sesión;
  al suspender la licencia redirige a `license.status`.
- Sin módulo o sin permiso, CobrOS mantiene el rechazo 403.

Validación en SQLite en memoria:
- `CobrosModuleAccessTest`: 13/13, 63 aserciones.
- `CompanyLicenseTest`, `CompanyModuleAccessTest`, `RouteosR01Test` y nueve
  casos de navegación de `ResponsiveNavigationTest` (bottom_bar, sidebar,
  layout_includes_mobile, tenant_shell, pos_mobile, master_panel): 32/32,
  149 aserciones.
- Ejecución con `ResponsiveNavigationTest` completo: 46/47, 220 aserciones.
  Falla `test_tenant_header_uses_company_logo_or_neutral_initial_and_keeps_mvs_brand_separate`
  al esperar `/storage/companies/header-test-*.png`. Renderiza directamente
  `components.header`; test, encabezado y modelo Company están sin cambios
  respecto a HEAD. Se documenta la limitación ajena al alcance, sin corregirla.
- Revisión conceptual 360/768/1280: solo se retiran elementos; se conservan
  breakpoints y contenedores existentes. Sin validación visual en navegador.

Usuario autoriza el commit con la excepción de regresión documentada; sin push ni merge.
