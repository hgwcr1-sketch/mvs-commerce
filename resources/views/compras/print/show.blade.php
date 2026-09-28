<!DOCTYPE html>
<html lang="es">

<head>

<meta charset="UTF-8">

<title>
Compra {{ $purchase->number }}
</title>


<style>

@page {
    margin: 25px;
}


body {

    font-family: DejaVu Sans, sans-serif;
    color: #1f2937;
    font-size: 12px;

}


.header {

    width: 100%;
    border-bottom: 3px solid #b1922d;
    padding-bottom: 15px;
    margin-bottom: 20px;

}


.logo {

    width: 90px;
    max-height: 80px;

}


.company-name {

    font-size: 24px;
    font-weight: bold;
    color: #b1922d;

}


.company-info {

    font-size: 11px;
    color: #475569;

}


.document-title {

    margin-top: 15px;
    font-size: 20px;
    font-weight: bold;

}



.info-table {

    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;

}


.card {

    border: 1px solid #cbd5e1;
    padding: 10px;

}


.label {

    font-size: 10px;
    color: #64748b;
    text-transform: uppercase;

}


.value {

    font-weight: bold;
    margin-top: 4px;

}

</style>


</head>


<body>


<div class="header">


<table width="100%">

<tr>


<td width="25%">

@if($company->logo)

<img 
src="{{ asset('storage/'.$company->logo) }}"
class="logo">

@endif


</td>


<td width="75%">


<div class="company-name">

{{ $company->trade_name }}

</div>


<div class="company-info">

{{ $company->legal_name }}

<br>

Cédula:
{{ $company->identification_number ?? '—' }}

<br>

Tel:
{{ $company->phone ?? '—' }}

<br>

{{ $company->email ?? '' }}

</div>


</td>


</tr>

</table>


</div>



<div class="document-title">

Detalle de Compra

</div>

<table class="info-table">

<tr>

<td width="33%">

<div class="card">

<div class="label">
Número de compra
</div>


<div class="value">

{{ $purchase->number }}

</div>

</div>

</td>



<td width="33%">

<div class="card">

<div class="label">
Fecha
</div>


<div class="value">

{{ $purchase->purchase_date?->format('d/m/Y') }}

</div>

</div>

</td>



<td width="33%">

<div class="card">

<div class="label">
Estado
</div>


<div class="value">

{{ $purchase->status === 'posted' ? 'Registrada' : 'Anulada' }}

</div>

</div>

</td>


</tr>

</table>




<table class="info-table">

<tr>


<td width="50%">

<div class="card">


<div class="label">
Proveedor
</div>


<div class="value">

{{ $purchase->supplier?->commercial_name ?: $purchase->supplier?->name }}

</div>


</div>

</td>



<td width="50%">

<div class="card">


<div class="label">
Factura proveedor
</div>


<div class="value">

{{ $purchase->supplier_invoice_number ?: '—' }}

</div>


</div>

</td>


</tr>


</table>




<table class="info-table">

<tr>


<td width="50%">

<div class="card">


<div class="label">
Forma de pago
</div>


<div class="value">

{{ $purchase->payment_type === 'credit' ? 'Crédito' : 'Contado' }}

</div>


</div>

</td>



<td width="50%">

<div class="card">


<div class="label">
Usuario
</div>


<div class="value">

{{ $purchase->user?->name ?? 'Sistema' }}

</div>


</div>

</td>


</tr>

</table>

<table style="width:100%; border-collapse:collapse; margin-top:25px;">


<thead>

<tr style="background:#f1f5f9;">


<th style="border-bottom:1px solid #cbd5e1; padding:10px; text-align:left;">
Producto
</th>


<th style="border-bottom:1px solid #cbd5e1; padding:10px; text-align:left;">
Código
</th>


<th style="border-bottom:1px solid #cbd5e1; padding:10px; text-align:right;">
Cantidad
</th>


<th style="border-bottom:1px solid #cbd5e1; padding:10px; text-align:right;">
Costo
</th>


<th style="border-bottom:1px solid #cbd5e1; padding:10px; text-align:right;">
Total
</th>


</tr>

</thead>



<tbody>


@foreach($purchase->items as $item)


<tr>


<td style="border-bottom:1px solid #e2e8f0; padding:10px;">

{{ $item->product?->name ?? 'Producto eliminado' }}

</td>



<td style="border-bottom:1px solid #e2e8f0; padding:10px;">

{{ $item->product?->internal_code ?? '—' }}

</td>



<td style="border-bottom:1px solid #e2e8f0; padding:10px; text-align:right;">

{{ number_format($item->quantity,2,',','.') }}

</td>



<td style="border-bottom:1px solid #e2e8f0; padding:10px; text-align:right;">

₡{{ number_format($item->unit_cost,0,',','.') }}

</td>



<td style="border-bottom:1px solid #e2e8f0; padding:10px; text-align:right; font-weight:bold;">

₡{{ number_format($item->total,0,',','.') }}

</td>


</tr>


@endforeach


</tbody>


</table>

<div style="margin-top:25px; width:100%;">

<table style="width:40%; margin-left:auto; border-collapse:collapse;">


<tr>

<td style="padding:6px;">
Subtotal
</td>


<td style="padding:6px; text-align:right;">

₡{{ number_format($purchase->subtotal,0,',','.') }}

</td>

</tr>



<tr>

<td style="padding:6px;">
Impuesto
</td>


<td style="padding:6px; text-align:right;">

₡{{ number_format($purchase->tax,0,',','.') }}

</td>

</tr>



<tr>

<td style="padding:8px; border-top:2px solid #b1922d; font-size:15px; font-weight:bold;">
TOTAL COMPRA
</td>


<td style="padding:8px; border-top:2px solid #b1922d; text-align:right; font-size:15px; font-weight:bold;">

₡{{ number_format($purchase->total,0,',','.') }}

</td>

</tr>


</table>

</div>



<div style="margin-top:40px; text-align:center;">

<button
onclick="window.print()"
style="
background:#b1922d;
color:white;
border:none;
padding:10px 25px;
border-radius:8px;
font-weight:bold;
cursor:pointer;
">

Imprimir documento

</button>

</div>



<style>

@media print {

    button {
        display:none !important;
    }


    body {
        background:white;
    }

}


</style>



<div style="margin-top:40px; text-align:center; font-size:10px; color:#64748b;">

Documento generado por MVS Commerce

<br>

Sistema de gestión empresarial multiempresa

</div>



</body>

</html>
