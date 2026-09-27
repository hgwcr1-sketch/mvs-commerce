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

## Fase 2 — Master (2026-09-27)

- Onboarding completo: actividad económica + sucursal/terminal fiscales
  (3/5 dígitos, formato oficial) en el paso de datos; ubicación fiscal =
  campos existentes de Company; certificados/custodia quedan en el
  proveedor (nunca se sube `.p12` a MVS).
- Series (`fiscal_series`, provider-neutral por empresa+ambiente+sucursal+
  terminal+tipo): observa consecutivos reales (parseo oficial 3+5+2+10),
  sugiere el siguiente, importa controladamente (nunca retrocede), reserva
  con lock para concurrencia y NUNCA resetea (tampoco al cambiar proveedor).
  La numeración productiva sigue en el proveedor hasta MVS Fiscal.
- Cambio de proveedor: gate `FiscalProviderSwitchService::canSwitch`
  (sin documentos en vuelo, series reconciliadas, destino registrado y
  verificado, auditoría en log); la ejecución reusa conexión+verificación
  existentes. Sin MvsFiscalProvider real todavía.
- Webhooks: contrato neutral listo (`FiscalWebhookEvent` +
  `FiscalWebhookHandler`); receptor FacturaEnCR NO implementado porque la
  documentación oficial no detalla firma/secreto/esquema (no se inventa).
  El polling sigue como mecanismo principal y probado.
- Custodia (`fiscal_document_custody`, por documento): payload enviado
  inmutable + última respuesta del proveedor; sin XML fabricado.
- Dashboard centro de control: banner de ambiente (PRUEBAS sin valor
  fiscal / PRODUCCIÓN identificable), emisor, actividad, sucursal/terminal,
  conteos aceptados/rechazados/en proceso, consumo, series, diagnóstico
  extendido (licencia, datos, credenciales, proveedor, series, última
  respuesta) y detalle por documento con custodia.
