# Matriz de comprobantes fiscales — MVS Commerce

Fuente: código real de la rama `feature/factura-electronica`. No afirmar soporte que no exista.

| Tipo | Nombre | Estado real |
|------|--------|-------------|
| `ticket` | Tiquete interno | Implementado. Nunca fiscal, nunca consume, nunca se emite. |
| TE04 | Tiquete Electrónico | **E2E accepted** en Sandbox (Sale 6 / ElectronicDocument 1). |
| FE01 | Factura Electrónica | **E2E accepted** en Sandbox (Sale 7 / ElectronicDocument 2). |
| NC03 | Nota de Crédito | **LOCAL implementado, SANDBOX PENDIENTE.** DTO neutral + referencia congelada + builder + `FiscalManager::authorizeAdjustment/emit` + mapper neutral (payload local) + ledger/cuota/idempotencia probados con fake provider (`FiscalAdjustmentTest` 17/17). `FacturaencrProvider::emitAdjustment` retorna `adjustment_endpoint_pending` SIN HTTP, sin documento y sin consumo. Falta verificable: endpoint/payload 03 de FacturaEnCR. |
| ND02 | Nota de Débito | **LOCAL implementado, SANDBOX PENDIENTE.** Espejo de NC03 con `debitNote` tipo 02: e2e local + cuota/overage + retry + aislamiento + observabilidad (`source_type/source_id/original_document_id`) probados con fake provider. `FacturaencrProvider` bloquea 02 igual que 03. Falta verificable: endpoint/payload 02 de FacturaEnCR. |
| Otros | — | El proveedor `facturaencr` solo resuelve `01`/`04` contra HTTP real; `03`/`02` retornan `adjustment_endpoint_pending` sin HTTP. No se afirma soporte adicional. |

Sandbox NC03/ND02 = **PENDIENTE** mientras endpoint/payload no estén verificados contra FacturaEnCR. Sin módulo comercial de Nota de Crédito/Débito en esta rama: el origen es neutral (`return`/`debit` + snapshots congelados).

Notas:

- Consumo auditable (`fiscal_consumptions`) y gate por licencia aplican a todo tipo consumible presente o futuro.
- Existe la rama `feature/notas-credito`, fuera del alcance de esta matriz; nada de ella se copió ni se da por implementado aquí.
