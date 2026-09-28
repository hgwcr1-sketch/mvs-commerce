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

## Marca MVS: el proveedor no se expone (2026-09-27)

Regla de producto: ninguna vista normal del tenant muestra el proveedor
técnico (ni nombre, ni código, ni endpoints). El portal habla de
"Facturación Electrónica", "Conexión fiscal", "Conectado con Hacienda",
"Estado de Hacienda" y "Credenciales de conexión". La arquitectura
(`provider`, adapters, históricos, auditoría) conserva el proveedor
internamente para FiscalManager, migración futura y soporte autorizado.

## Limpieza tenant (2026-09-27)

- Sin botón "Proveedor" en el Master y sin acceso tenant al cambio de
  proveedor: la URL tenant responde 403 y la pantalla vive en Panel
  Maestro (solo administración interna MVS).
- Series fuera del dashboard: viven en Configuración avanzada con texto
  de uso ("al migrar numeración o cuando soporte MVS lo indique"); sin
  series se muestra estado neutral ("Las series se registrarán al
  emitir"), sin alerta falsa ni Resolver.
- En el Master no se muestra ni siquiera la máscara de credencial: solo
  "Conexión fiscal" (Verificada / Pendiente / Requiere atención). La
  máscara vive únicamente en Configuración → Conexión.

## Rotación segura (2026-09-27)

Editar la conexión NO destruye la vigente: lo nuevo queda pendiente,
se verifica y solo se activa con éxito
(Nueva configuración → Verificar → Confirmar → Activar). Si falla, la
activa sigue facturando, se muestra el error sanitizado y lo pendiente
puede corregirse o cancelarse. Producción exige confirmación explícita.
Desconectar es acción independiente con confirmación y auditoría, sin
borrar historial. Auditoría (`fiscal_config_audits`): usuario, fecha,
empresa, ambiente, tipo de cambio y resultado; jamás secretos.

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

## Master visual MVS (2026-09-27)

- Encabezado "Centro de Facturación Electrónica" con empresa secundaria,
  badge de estado y CTA dorado a la derecha en desktop / full-width en
  móvil: "Completar configuración" (incompleto) o "Administrar
  configuración" + "Actualizar conexión" (configurado). Sin scroll
  necesario en desktop.
- Estados sin contradicción: CONFIGURACIÓN (Incompleta / Pendiente de
  verificar / Verificada) separada de ACTIVIDAD HACIENDA (último aceptado
  / rechazado / en proceso / sin comunicaciones). Nunca se afirma
  conexión vigente por historial solo.
- Consumo como tarjeta: "X de Y utilizados", disponibles, barra dorada y
  desglose FE/TE/NC/ND (verde/rojo solo semántico).
- Recientes con badges Aceptado/Rechazado/En proceso, consecutivo e
  intento visibles según pantalla y enlace "Ver".
- Diagnóstico checklist con enlace "Resolver" al paso exacto del wizard.
- Identidad MVS reutilizada (layout, cards, tipografía y espaciados
  existentes; dorado #D4AF37, negro/blanco, sin índigo ni fuentes nuevas).
