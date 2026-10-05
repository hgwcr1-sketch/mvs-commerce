# Precios y costos: separador decimal (2026-10-05)

- `NormalizeMonetaryInputs` normaliza coma decimal antes de validación HTTP, únicamente en los campos explícitos de precios/costos, incluidos ítems anidados. No cambia cantidades, códigos, permisos ni esquema DECIMAL.
- Productos y variantes comparten `_form`; compras, creación de producto desde compras y costos de proveedores (también desde pedidos) usan entradas de texto con teclado decimal, patrón decimal y `step="0.01"`. El texto evita depender del soporte de coma del navegador para `type="number"`.
- Se muestran al menos dos decimales; los costos existentes con fracciones de centavo conservan su precisión al editar, sin redondeo silencioso. La serialización de compras conserva strings monetarios. Los precios A/B/C también se validan al editar.
- Validación focal: `MonetaryInputsTest` (2 tests, 136 aserciones), `node tests/js/monetary-input.mjs`, `npm.cmd run build`. Valores: `370,87`, `370.87`, `0.01`, `100.50`, `500`; además precisión de cuatro decimales, inválidos, nulos y aislamiento de campos.
- Sin validación visual en navegador real, cambios CABYS ni despliegue.
