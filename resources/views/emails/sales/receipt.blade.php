<h1>Comprobante {{ $sale->sale_number }}</h1>
<p>Gracias por su compra en {{ $sale->company->trade_name }}.</p>
<p>Adjuntamos su comprobante en formato PDF.</p>
<p>Total: <strong>₡{{ number_format((float) $sale->total, 0, ',', '.') }}</strong></p>
