# Matriz de comprobantes fiscales — MVS Commerce

Fuente: código real de la rama `feature/factura-electronica`. No afirmar soporte que no exista.

| Tipo | Nombre | Estado real |
|------|--------|-------------|
| `ticket` | Tiquete interno | Implementado. Nunca fiscal, nunca consume, nunca se emite. |
| TE04 | Tiquete Electrónico | **E2E accepted** en Sandbox (Sale 6 / ElectronicDocument 1). |
| FE01 | Factura Electrónica | **E2E accepted** en Sandbox (Sale 7 / ElectronicDocument 2). |
| NC03 | Nota de Crédito | **NO implementado** en esta rama: sin modelo, mapper, endpoint ni job. Gate y ledger listos (`03` en tipos consumibles, probado sin POST). E2E **BLOCKED**. |
| ND02 | Nota de Débito | **NO implementado**. Gate y ledger listos (`02` en tipos consumibles). |
| Otros | — | El proveedor `facturaencr` solo resuelve `01`/`04`; otro tipo lanza excepción. No se afirma soporte adicional. |

Notas:

- Consumo auditable (`fiscal_consumptions`) y gate por licencia aplican a todo tipo consumible presente o futuro.
- Existe la rama `feature/notas-credito`, fuera del alcance de esta matriz; nada de ella se copió ni se da por implementado aquí.
