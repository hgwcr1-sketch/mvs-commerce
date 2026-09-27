# Matriz de comprobantes fiscales — MVS Commerce

Fuente: código real de la rama `feature/factura-electronica`. No afirmar soporte que no exista.

| Tipo | Nombre | Estado real |
|------|--------|-------------|
| `ticket` | Tiquete interno | Implementado. Nunca fiscal, nunca consume, nunca se emite. |
| TE04 | Tiquete Electrónico | **E2E accepted** en Sandbox (Sale 6 / ElectronicDocument 1). |
| FE01 | Factura Electrónica | **E2E accepted** en Sandbox (Sale 7 / ElectronicDocument 2). |
| NC03 | Nota de Crédito | **ADAPTER corregido (-496), SEGUNDO E2E PENDIENTE.** Primer E2E real (doc3): 1 POST sandbox, `queued` → `rejected` (-496 medioPago + -37 acompañante), consumo +1 INCLUDED preservado, sin reenvío. Fix: `medioPago` derivado de pagos completados de la venta original (crédito exento); contado sin pagos bloquea pre-POST. Cobertura con HTTP falso (`FiscalAdjustmentTest` 25/25). Sin módulo comercial. |
| ND02 | Nota de Débito | **ADAPTER corregido (-496), E2E PENDIENTE.** Espejo de NC03 con derivación de `medioPago` y misma cobertura con HTTP falso. Sin emisión real todavía. |
| Otros | — | El proveedor `facturaencr` solo resuelve `01`/`04` contra HTTP real; `03`/`02` retornan `adjustment_endpoint_pending` sin HTTP. No se afirma soporte adicional. |

Sandbox NC03/ND02 = **PENDIENTE**: adapter implementado contra documentación oficial, sin emisión real a sandbox todavía. Sin módulo comercial de Nota de Crédito/Débito en esta rama: el origen es neutral (`return`/`debit` + snapshots congelados).

Notas:

- Consumo auditable (`fiscal_consumptions`) y gate por licencia aplican a todo tipo consumible presente o futuro.
- Existe la rama `feature/notas-credito`, fuera del alcance de esta matriz; nada de ella se copió ni se da por implementado aquí.
