<!DOCTYPE html>
<html lang="es">

<head>

<meta charset="UTF-8">

<title>
Compra {{ $purchase->number }}
</title>


<style>

body {
    font-family: DejaVu Sans, sans-serif;
    color: #1f2937;
    font-size: 12px;
}


.header {
    width: 100%;
    border-bottom: 2px solid #b1922d;
    padding-bottom: 15px;
}


.logo {
    width: 90px;
    height: auto;
}


.company-name {
    font-size: 22px;
    font-weight: bold;
    color: #b1922d;
}


.company-data {
    font-size: 11px;
    color: #475569;
}


.title {

    margin-top: 15px;
    font-size: 18px;
    font-weight: bold;

}


.info-table {

    width: 100%;
    margin-top: 20px;
    border-collapse: collapse;

}


.info-box {

    border: 1px solid #d1d5db;
    padding: 10px;

}


.label {

    font-size: 10px;
    color: #64748b;
    text-transform: uppercase;

}


.value {

    font-weight: bold;
    margin-top: 5px;

}


.products {

    width:100%;
    border-collapse: collapse;
    margin-top:25px;

}


.products th {

    background:#f1f5f9;
    border-bottom:1px solid #cbd5e1;
    padding:8px;

}


.products td {

    border-bottom:1px solid #e2e8f0;
    padding:8px;

}


.right {

    text-align:right;

}


.total {

    margin-top:20px;
    width:40%;
    margin-left:auto;

}


.total td {

    padding:6px;

}


.total-final {

    font-size:15px;
    font-weight:bold;
    border-top:2px solid #b1922d;

}


.footer {

    margin-top:40px;
    text-align:center;
    font-size:10px;
    color:#64748b;

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
src="file://{{ public_path('storage/'.$company->logo) }}"
class="logo">

@endif


</td>


<td width="75%">


<div class="company-name">

{{ $company->trade_name }}

</div>


<div class="company-data">

{{ $company->legal_name }}

<br>

Cédula: {{ $company->identification_number }}

<br>

Tel: {{ $company->phone }}

<br>

{{ $company->email }}

</div>


</td>


</tr>

</table>


</div>


<div class="title">

Detalle de Compra

</div>

<table class="info-table">

<tr>


<td width="33%">


<div class="info-box">


<div class="label">
Número de compra
</div>


<div class="value">

{{ $purchase->number }}

</div>


</div>


</td>



<td width="33%">


<div class="info-box">


<div class="label">
Fecha
</div>


<div class="value">

{{ $purchase->purchase_date?->format('d/m/Y') }}

</div>


</div>


</td>



<td width="33%">


<div class="info-box">


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


<div class="info-box">


<div class="label">
Proveedor
</div>


<div class="value">

{{ $purchase->supplier?->commercial_name ?: $purchase->supplier?->name }}

</div>


</div>


</td>



<td width="50%">


<div class="info-box">


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


<div class="info-box">


<div class="label">
Forma de pago
</div>


<div class="value">

{{ $purchase->payment_type === 'credit' ? 'Crédito' : 'Contado' }}

</div>


</div>


</td>


<td width="50%">


<div class="info-box">


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

<table class="products">

<thead>

<tr>

<th>
Producto
</th>


<th>
Código
</th>


<th class="right">
Cantidad
</th>


<th class="right">
Costo Unitario
</th>


<th class="right">
Total
</th>

</tr>

</thead>


<tbody>


@foreach($purchase->items as $item)


<tr>


<td>

{{ $item->product?->name ?? 'Producto eliminado' }}

</td>


<td>

{{ $item->product?->internal_code ?? '—' }}

</td>


<td class="right">

{{ number_format($item->quantity,2,',','.') }}

</td>


<td class="right">

₡{{ number_format($item->unit_cost,0,',','.') }}

</td>


<td class="right">

₡{{ number_format($item->total,0,',','.') }}

</td>


</tr>


@endforeach


</tbody>


</table>

<div style="height:20px;"></div>


<table class="total">


<tr>

<td>
Subtotal
</td>


<td class="right">

₡{{ number_format($purchase->subtotal,0,',','.') }}

</td>

</tr>



<tr>

<td>
Impuesto
</td>


<td class="right">

₡{{ number_format($purchase->tax,0,',','.') }}

</td>

</tr>



<tr class="total-final">

<td>
TOTAL COMPRA
</td>


<td class="right">

₡{{ number_format($purchase->total,0,',','.') }}

</td>

</tr>


</table>



<div class="footer">


Documento generado por MVS Commerce


<br>


Sistema de gestión empresarial multiempresa


</div>



</body>

</html>
