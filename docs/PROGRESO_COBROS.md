# MVS CobrOS — Registro de avance

## Fase 1A — Completada: fundación aislada del módulo

Rama `feature/cobros-v1` en el worktree `mvs-commerce-cobros`.

### Archivos creados

- `app/Http/Controllers/Cobros/CobrosDashboardController.php` — panel inicial.
- `app/Http/Middleware/EnsureCompanyModule.php` — middleware reutilizable de
  módulo contratado por empresa (403 si no está activo).
- `resources/views/cobros/layout.blade.php` — layout propio de CobrOS,
  independiente del de Commerce, con enlace de retorno a la plataforma.
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
  "MVS CobrOS" (solo con permiso + módulo activo).
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