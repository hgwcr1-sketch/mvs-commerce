# Portal Fiscal MVS — Facturación Electrónica por empresa

MVS Commerce es la interfaz fiscal del cliente. FacturaEnCR es el proveedor
técnico actual, NO el dominio ni la UI del producto. El futuro
`MvsFiscalProvider` se registra en `config/fiscal.php` (`fiscal.providers`)
sin rehacer portal, POS ni manager.

## Arquitectura

Empresa → Portal Fiscal MVS → `FiscalManager` → proveedor → Hacienda.

- `FiscalManager::providerForCompany()` resuelve el proveedor por empresa
  (código guardado en su configuración, validado contra el registro;
  fallback seguro al default global).
- `CompanyFiscalConfigService::contextFor()` entrega credenciales + ambiente
  por empresa (secretos cifrados, jamás plaintext).
- `FacturaencrProvider` opera con el contexto de la empresa emisora; los
  documentos históricos conservan su `provider` original.
- Contratos para el futuro proveedor: `FiscalProviderInterface` (emitir) +
  `FiscalConnectionVerifiable` (verificar sin emitir, opcional).

## Configuración por empresa (NO global)

Tabla `company_fiscal_configs` (única por empresa): proveedor, ambiente
(`sandbox`/`production`), llave/secreto cifrados (`encrypted`, nunca se
muestran completos ni se guardan en logs), documento predeterminado,
emisión automática, aviso por correo, última verificación y último error
sanitizado. `.env` conserva solo defaults técnicos. Campo vacío al editar =
conserva el secreto existente; rotar invalida la verificación anterior.
Sandbox y producción nunca se mezclan ni se copian solos.

## Portal (rutas `fiscal.*`, responsive móvil primero)

- `fiscal.index`: estado (Conectado/Incompleto/Requiere atención/Módulo no
  habilitado), ambiente (Pruebas/Producción), identidad fiscal, última
  comprobación, consumo del período con cuota, documentos recientes
  (FE/TE/NC/ND) y diagnóstico (conexión, licencia, configuración, última
  comunicación). Sin errores técnicos crudos.
- `fiscal.history`: historial completo paginado con filtro por tipo. El
  historial fiscal nunca se borra; los rejected conservan sus intentos.
- Asistente `fiscal.setup` (5 pasos): datos fiscales → conexión →
  verificación (SIN emitir, sin consumir) → preferencias → confirmación.

## Licencia y preferencias

Panel Maestro es la autoridad comercial (`fiscal_enabled`, cuota,
excedentes, precio); el tenant solo consulta. Con `fiscal_enabled=false`
el portal lo explica y el POS sigue bloqueando FE/TE. Preferencias
provider-neutrales: documento predeterminado (01/04), emisión automática
(gobierna el dispatcher POS con fallback al default global) y aviso por
correo. El tiquete interno jamás sale a Hacienda ni consume.

## Seguridad

Permisos `fiscal.ver` / `fiscal.editar`, CSRF, aislamiento por empresa
activa, secretos cifrados y enmascarados, verificación sin emisión y
producción/sandbox diferenciados.
