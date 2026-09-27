# Migración de proveedor fiscal (FacturaEnCR → MVS Fiscal)

Guía de diseño para mover la emisión entre proveedores sin perder
historial, series ni idempotencia. El usuario final conserva el mismo
Portal/POS; los históricos conservan su provider original.

## Principios

- Las series (`fiscal_series`) no tienen columna provider: sobreviven al
  cambio sin resets. La numeración la asigna el proveedor activo; MVS
  solo observa, sugiere e importa controladamente.
- La idempotency es por intento y documento; un cambio de proveedor no
  reescribe claves existentes.
- El consumo (`fiscal_consumptions`) es por documento aceptado para
  proceso, independiente del proveedor.
- La custodia (`fiscal_document_custody`) guarda payload + respuesta por
  documento: la evidencia viaja con el historial, no con el proveedor.

## Procedimiento

1. Registrar el nuevo proveedor en `config/fiscal.php` (`fiscal.providers`)
   implementando `FiscalProviderInterface` (+ `FiscalConnectionVerifiable`).
2. En el portal (Cambio de proveedor), elegir destino y revisar la lista:
   - sin documentos en vuelo (`pending/queued/signing/sent/polling`);
   - series reconciliadas (último documento de cada serie con veredicto);
   - destino registrado y verificado SIN emitir;
   - mismo ambiente o migración consciente sandbox→producción.
3. Actualizar la conexión de la empresa al nuevo proveedor y verificar.
4. Emitir un documento de prueba en sandbox y conciliar serie/consumo.
5. Auditoría en `fiscal.provider.switch_check` (log sanitizado).

## Rollback

Volver al proveedor anterior repite el procedimiento inverso; como nada
se borra ni se resetea (series, documentos, consumos, custodia), el
rollback es solo configuración + verificación.

## Webhooks: bloqueador documentado

La documentación oficial de FacturaEnCR confirma eventos
(`document.accepted/rejected/queued`, HMAC-SHA256 y reintentos) pero no
detalla cabeceras de firma, secreto ni esquema exacto del evento. Sin esa
especificación NO se implementa el receptor. Contrato neutral ya creado
(`FiscalWebhookEvent` + `FiscalWebhookHandler`): cuando el proveedor
documente el formato, el adapter traduce y el procesamiento común
(idempotencia por `eventId`, resolución tenant/documento, accepted/
rejected finales sin doble consumo, logs sanitizados) ya está definido.
Mientras tanto, el polling es el mecanismo oficial y probado.

## Aprendizajes aplicables a MVS Fiscal

- Totales e impuestos los calcula el emisor desde líneas congeladas;
  el receptor válido mínimo es identificación atada + nombre.
- Contado exige medio de pago derivado de pagos reales (-496 observado).
- La referencia exige clave de 50 + código válido + fecha ISO -06:00.
- `rejected` no se corrige: nuevo intento con nueva identidad.
- Clave/consecutivo los asigna el emisor autorizado; el consecutivo
  observado alimenta series sin que MVS numere todavía.
- Archive payload + respuesta por documento desde el día uno.
