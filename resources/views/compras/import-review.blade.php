@extends('layouts.app')

@section('title', 'Revisión de importación')

@section('content')

<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">

    <div>
        <h2 class="text-xl font-semibold text-slate-800">
            Revisión de importación
        </h2>

        <p class="text-sm text-slate-500">
            Revise los datos antes de registrar la compra.
        </p>
    </div>


    <a
        href="{{ route('compras.index') }}"
        class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
        Volver
    </a>

</div>


<x-card>

    @if($validation['supplier_summary']['multiple'] ?? false)
        <div class="mb-6 rounded-lg border border-red-300 bg-red-50 p-4 text-red-800">
            <div class="font-semibold">El archivo contiene proveedores diferentes</div>
            <div class="mt-1 text-sm">
                {{ collect($validation['supplier_summary']['names'])
                    ->map(fn ($name) => $name ?? '(vacío)')
                    ->implode(', ') }}
            </div>
            <p class="mt-2 text-sm">
                Debe separar las compras en archivos distintos antes de confirmar.
            </p>
        </div>
    @endif

    {{-- PROVEEDOR --}}

    <h3 class="mb-4 font-semibold text-slate-700">
        Proveedor
    </h3>


    @if(isset($validation['supplier']))

    @if($validation['supplier']['found'])

        <div id="proveedorEstado"
             class="rounded-lg border border-green-200 bg-green-50 p-3 text-green-800">

            ✅ {{ $validation['supplier']['name'] }}

        </div>

    @else

        <div id="proveedorEstado"
             class="rounded-lg border border-red-200 bg-red-50 p-3 text-red-800">

            ⚠️ Proveedor no encontrado:
            {{ $validation['supplier']['name'] }}

            <button
                type="button"
                onclick="abrirProveedorModal()"
                class="ml-3 rounded-lg bg-red-600 px-3 py-1 text-white">
                Crear proveedor
            </button>

        </div>

    @endif

@endif


    {{-- PRODUCTOS ENCONTRADOS --}}

    <h3 class="mt-8 mb-4 font-semibold text-slate-700">
        Productos encontrados
    </h3>


    <div class="overflow-x-auto">

        <table class="min-w-full">

            <thead class="bg-slate-50">

                <tr>

                    <th class="px-4 py-3 text-left">
                        Producto
                    </th>

                    <th class="px-4 py-3 text-center">
                        Cantidad
                    </th>

                    <th class="px-4 py-3 text-right">
                        Costo
                    </th>

                </tr>

            </thead>


            <tbody>

            @foreach($validation['found'] as $item)

                <tr class="border-b">

                    <td class="px-4 py-3">
                        {{ $item['product'] }}
                    </td>

                    <td class="px-4 py-3 text-center">
                        {{ $item['quantity'] }}
                    </td>

                    <td class="px-4 py-3 text-right">
                        ₡{{ number_format($item['cost'],0,',','.') }}
                    </td>

                </tr>

            @endforeach


            </tbody>

        </table>

    </div>



    {{-- PRODUCTOS PENDIENTES --}}


    @if(count($validation['missing']))

<div class="mt-8">

    <h3 class="mb-4 font-semibold text-red-700">
        Productos pendientes de revisión
    </h3>


    @foreach($validation['missing'] as $item)

    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">


        <div class="grid gap-2">

            <div>
                <strong>Código:</strong>
                {{ $item['code'] }}
            </div>


            <div>
                <strong>Producto:</strong>
                {{ $item['name'] }}
            </div>


            <div>
                <strong>Cantidad:</strong>
                {{ $item['quantity'] }}
            </div>


            <div>
                <strong>Costo:</strong>
                ₡{{ number_format($item['cost'],0,',','.') }}
            </div>


        </div>



        @if(count($item['possible_matches']))

            <div class="mt-4 rounded-lg border bg-white p-3">

                <h4 class="mb-3 font-semibold text-slate-700">
                    Posibles coincidencias
                </h4>


                @foreach($item['possible_matches'] as $match)

                    <div class="mb-2 flex items-center justify-between rounded border p-3">


                        <div>

                            <div class="font-semibold">
                                {{ $match->name }}
                            </div>

                            <div class="text-sm text-slate-500">

                                Código:
                                {{ $match->internal_code }}

                                |
                                Barra:
                                {{ $match->barcode }}

                            </div>

                        </div>


                        <form method="POST" action="{{ route('compras.import.product.store') }}">
                            @csrf
                            <input type="hidden" name="row_key" value="{{ $item['_row_key'] }}">
                            <input type="hidden" name="existing_product_id" value="{{ $match->id }}">
                            <button class="rounded-lg bg-emerald-600 px-3 py-2 text-sm text-white">
                                Usar este producto
                            </button>
                        </form>


                    </div>


                @endforeach


            </div>

        @else

            <div class="mt-4">

                <button
onclick="crearProducto(
'{{ $item['_row_key'] }}'
)"
class="rounded-lg bg-red-600 px-4 py-2 text-white">

Crear producto nuevo

</button>

            </div>

        @endif


    </div>


    @endforeach


</div>

@endif


    <div class="mt-6 flex justify-end">

        <form method="POST" action="{{ route('compras.import.confirm') }}">

    @csrf

    <button
        @disabled($validation['supplier_summary']['multiple'] ?? false)
        class="rounded-lg bg-amber-500 px-5 py-2 font-semibold text-white">
        Confirmar compra
    </button>

</form>

    </div>


</x-card>

<script>

function crearProveedorImportacion()
{
    alert('Aquí crearemos el proveedor');
}

</script>

<div id="proveedorModal"
     class="fixed inset-0 hidden items-center justify-center bg-black/50">

    <div class="w-full max-w-md rounded-lg bg-white p-6">

        <h3 class="mb-4 text-lg font-semibold">
            Crear proveedor
        </h3>


        <input
    id="supplier_name"
    value="{{ $validation['supplier']['name'] ?? '' }}"
    class="mb-3 w-full rounded-lg border px-3 py-2"
    placeholder="Nombre proveedor">


        <input
            id="supplier_identification"
            class="mb-3 w-full rounded-lg border px-3 py-2"
            placeholder="Identificación">


        <input
            id="supplier_phone"
            class="mb-3 w-full rounded-lg border px-3 py-2"
            placeholder="Teléfono">


        <div class="flex justify-end gap-2">

            <button
                onclick="cerrarProveedorModal()"
                class="rounded-lg border px-4 py-2">
                Cancelar
            </button>


            <button
                onclick="guardarProveedor()"
                class="rounded-lg bg-amber-500 px-4 py-2 text-white">
                Guardar
            </button>

        </div>


    </div>

</div>


<script>

function abrirProveedorModal()
{
    document
        .getElementById('proveedorModal')
        .classList.remove('hidden');
}


function cerrarProveedorModal()
{
    document
        .getElementById('proveedorModal')
        .classList.add('hidden');
}


function guardarProveedor()
{
    fetch("{{ route('proveedores.store') }}", {

        method: "POST",

        headers: {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-CSRF-TOKEN": "{{ csrf_token() }}"
        },

        body: JSON.stringify({

            name: document.getElementById('supplier_name').value,

            identification: document.getElementById('supplier_identification').value,

            phone: document.getElementById('supplier_phone').value,

            supplier_type: 'company',

            is_active: true

        })

    })
    .then(response => response.json())

    .then(data => {

        console.log(data);

        fetch("{{ route('compras.import.supplier.created') }}", {

    method: "POST",

    headers: {
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": "{{ csrf_token() }}"
    },

    body: JSON.stringify({
        id: data.id,
        name: data.name
    })

});

document.getElementById('proveedorEstado').className =
"rounded-lg border border-green-200 bg-green-50 p-3 text-green-800";


cerrarProveedorModal();
location.reload();
    })

    .catch(error => {

        console.error(error);

        alert('Error creando proveedor');

    });
}

</script>

<script>

function crearProducto(rowKey)
{

window.location.href =
"/compras/importacion/producto-nuevo?row_key="
+ encodeURIComponent(rowKey);

}

</script>

@endsection
