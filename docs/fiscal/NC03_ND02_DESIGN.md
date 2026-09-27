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

## 2. Reutilización / faltantes

Reutilizar: snapshots congelados, `FiscalTaxService`, `ElectronicDocument`,
`FiscalManager`, gate/consumo, `SaleReturn` como futuro origen (no duplicarlo).
Falta: DTO neutral de documento, referencia inmutable al original, mapper NC/ND,
endpoint de proveedor para `03`/`02`, job/Gate de disparo NC/ND.

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
