Comprobante {{ $sale->sale_number }}

Gracias por su compra en {{ $sale->company->trade_name }}.
Adjuntamos su comprobante en formato PDF.
Total: ₡{{ number_format((float) $sale->total, 0, ',', '.') }}
