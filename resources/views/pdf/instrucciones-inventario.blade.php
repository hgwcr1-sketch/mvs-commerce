<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">

    <title>Instrucciones Importación Inventario</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #333;
        }

        h1 {
            text-align: center;
            color: #B1922D;
        }

        h2 {
            color: #B1922D;
            margin-top: 20px;
        }

        .box {
            border: 1px solid #ddd;
            padding: 10px;
            margin-top: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        table, th, td {
            border: 1px solid #ccc;
        }

        th, td {
            padding: 8px;
        }

        th {
            background-color: #eee;
        }

    </style>

</head>

<body>

<h1>
MVS Commerce
</h1>

<h2>
Instrucciones de Importación de Inventario
</h2>


<div class="box">

<p>
Este documento explica el proceso para importar inventario mediante archivo Excel.
</p>

</div>


<h2>
Campos obligatorios
</h2>

<ul>
<li>codigo*</li>
<li>nombre*</li>
<li>cantidad*</li>
</ul>


<h2>
Campos disponibles en la plantilla
</h2>


<table>

<tr>
<th>Campo</th>
<th>Descripción</th>
</tr>

<tr>
<td>codigo</td>
<td>Código interno del producto o código de barras.</td>
</tr>

<tr>
<td>nombre</td>
<td>Nombre del producto.</td>
</tr>

<tr>
<td>cantidad</td>
<td>Cantidad que será agregada o retirada.</td>
</tr>

<tr>
<td>categoria</td>
<td>Categoría del producto.</td>
</tr>

<tr>
<td>marca</td>
<td>Marca del producto.</td>
</tr>

<tr>
<td>costo</td>
<td>Costo del producto.</td>
</tr>

<tr>
<td>precio_venta</td>
<td>Precio de venta.</td>
</tr>

<tr>
<td>minimo / maximo</td>
<td>Niveles de control de inventario.</td>
</tr>

</table>


<h2>
Proceso de importación
</h2>

<ol>
<li>Descargar la plantilla Excel.</li>
<li>Completar los datos.</li>
<li>Seleccionar sucursal.</li>
<li>Cargar el archivo.</li>
<li>Revisar la vista previa.</li>
<li>Confirmar importación.</li>
</ol>


<h2>
Importante
</h2>

<p>
Si el producto no existe, MVS Commerce permite crearlo durante la importación.
</p>


</body>
</html>