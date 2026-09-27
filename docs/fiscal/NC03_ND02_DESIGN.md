# NC03 / ND02 — Diseño fiscal provider-neutral

Rama `feature/factura-electronica`. Cero HTTP. Sin copiar `feature/notas-credito`.

## 1. Qué existe realmente

- `Sale` + `SaleItem` (+ `SaleItemTax`, `fiscal_snapshot` por línea): impuestos
  congelados por línea; el mapper jamás lee el producto actual.
- `SaleReturn` + `SaleReturnItem` (dominio comercial de devoluciones, otra
  área): futuro origen comercial de NC, no fiscal.
- Sin `CreditNote`, sin `AccountReceivableAdjustment`, sin modelo de ND.
- `ElectronicDocument`: `document_type` string; unique `(company, sale, type)`;
  `sale_id` nullable; `idempotency_key` única; ciclo `pending/queued/…/accepted/rejected`.
- `FiscalManager` (fachada única) → `FiscalProviderInterface` (`emit`,
  `fetchStatus`, `providerCode`; el contrato ya nombra "notas de crédito").
- `FacturaencrProvider` + `FacturaencrEmissionService` + `FacturaencrInvoiceMapper`:
  solo `01`/`04`; `endpointFor` lanza excepción con otro tipo. Payload
  verificado: `emisorLegalId/tipoDocumento/condicionVenta/currency/exchangeRate/receptor/detalle/medioPago?`.
- Sin endpoint ni payload NC/ND documentados en el repo.
- `FiscalConsumptionService`: tipos consumibles `01/04/03/02`; ledger inmutable
  por `electronic_document_id`; gate por licencia antes del POST.
- `FiscalTaxService`: `snapshotForSaleItem/validateSnapshot/serializeSnapshot`
  con `documentType` como parámetro (ya acepta `02`/`03` en validaciones).

## 2. Reutilización / faltantes (actualizado)

Reutilizado: snapshots congelados, `FiscalTaxService`, `ElectronicDocument`,
`FiscalManager`, gate/consumo, `SaleReturn` como futuro origen (no duplicarlo).
Implementado local: DTO neutral (`FiscalDocument` 01/04/03/02 + `Line` + `Reference`),
`FiscalAdjustmentBuilder::creditNote/debitNote`, `FiscalManager::authorizeAdjustment`,
mapper neutral `FacturaencrAdjustmentMapper` (payload local), ledger `03/02`,
observabilidad (`source_type/source_id/original_document_id` + relaciones).
Adapter FacturaEnCR verificado contra docs oficiales (ver §8): endpoints
`documents/nota-credito` y `documents/nota-debito`, `referencia[]` oficial.
Falta: job/Gate de disparo comercial NC/ND (sin módulo comercial en esta rama)
y primera emisión real a sandbox.

## 3. Límite dominio comercial ↔ fiscal

El dominio comercial (ventas, devoluciones, futuras NC/ND comerciales) decide
QUÉ corregir; la capa fiscal decide CÓMO representarlo ante Hacienda con datos
congelados. La referencia fiscal nunca relee `Sale/Product/Customer` mutable:
solo snapshots + `ElectronicDocument` original.

## 4. Flujos

- NC03: origen comercial (futura CreditNote o fixture neutral) → `FiscalDocument`
  tipo `03` + `FiscalDocumentReference` al documento `01/04` aceptado →
  validación → `FiscalManager::emit` → adapter → `ElectronicDocument(03)` →
  consumo `03`.
- ND02: idéntico con tipo `02`.

## 5. Idempotencia / consumo / estados

Identidad estable: `md5(company-source-type)` + unique
`(company, sale, type)` + fila única de consumo por documento. Retry/polling =
0 unidades. Solo `accepted/provider-processed` consume; `error` de transporte
no consume; `rejected` conserva historial y cuenta conservadoramente. Gate de
licencia siempre antes del POST.

## 6. Provider-neutral mapping

`FiscalDocument` (neutral) → cada adapter traduce a su payload. FacturaEnCR
nunca es dominio interno: solo su adapter conoce endpoints y llaves.

## 7. Estado local vs sandbox (2026-09-27)

- NC03 LOCAL: e2e fake (1 documento 03 + 1 consumo included), retry sin duplicar,
  cuota/overage/deshabilitado pre-provider, referencia congelada. Verde.
- ND02 LOCAL: espejo de NC03 con tipo 02 (e2e + cuota + retry + aislamiento +
  observabilidad). Verde.
- Referencias negativas: original inexistente, otra company, original no emitido,
  clave distinta, motivo vacío, modificador inválido, tipo no soportado, snapshot
  congelado. Verdes.
- Casos fiscales locales: IVA 13/4/2/1, exento explícito, exoneración parcial,
  multi-tax, descuento, múltiples líneas, BCMath, CABYS inválido, tasa legada
  ambigua bloqueada (0 jamás se infiere como exento). Verdes.
- Provider FacturaEnCR 03/02: `endpoint()` lanza `endpoint pendiente`;
  `emitAdjustment` retorna `adjustment_endpoint_pending` SIN HTTP, sin documento,
  sin consumo. Información externa faltante: ruta/método de emisión 03/02,
  payload exacto (incluida `informacionReferencia`) y credenciales/ambiente del
  proveedor. Sandbox NC03/ND02 = PENDIENTE hasta verificarlo.

## 8. Adapter verificado contra docs oficiales (2026-09-27, sin emisión real)

Fuente: https://facturaencr.com/docs (API v2, Hacienda v4.4). Base local
`config/facturaencr.base_url` = misma base documentada.

- NC03 → `POST documents/nota-credito`; ND02 → `POST documents/nota-debito`.
- `referencia[]` obligatoria: tipoDocumento (catálogo Nota 10 Anexo v4.4) /
  numero (clave de 50 si electrónico, largo validado localmente para no quemar
  consecutivos con -80) / fechaEmision ISO -06:00 (issuedAt o created_at del
  original, nunca inventada) / codigo (obligatorio en NC/ND: 01 anula, 02
  corrige monto, catálogo Nota 9; la API rellena codigo/razon si faltan, aquí
  siempre se envían) / razon (opcional).
- `receptor` opcional (identificación atada); `detalle` igual que factura;
  totales los calcula la plataforma y no se envían; `medioPago` solo en REP.
- `condicionVenta` se deriva de la venta original (cash→01/credit→02, mapeo ya
  verificado en FE/TE); `plazoCredito` no se envía por no estar documentado
  para NC/ND.
- Hallazgo corregido: el mapper usaba `informacionReferencia` (nodo XML de
  Hacienda) en vez del `referencia[]` del API. Ahora es `referencia[]`.
- Cobertura con HTTP falso: emisión 03/02 aceptada (documento + 1 consumo +
  retry sin duplicar), error 400 sin consumo, referencia inválida sin HTTP.
  CERO POST real, CERO sandbox. Sandbox NC03/ND02 sigue PENDIENTE (primera
  emisión real con EMISORPRUEBA, sin tocar Sale6/Sale7 ni producción).
