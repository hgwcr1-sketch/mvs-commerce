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
                        ₡{{ number_format($item['cost'],2,',','.') }}
                    </td>

                </tr>

            @endforeach


            </tbody>

        </table>

    </div>



    {{-- PRODUCTOS PENDIENTES --}}


    @if(count($validation['missing']))

        <div class="mt-8">

            <h3 class="font-semibold text-red-700">
                Productos pendientes
            </h3>


            @foreach($validation['missing'] as $item)

                <div class="mt-3 rounded-lg border p-3">

                    {{ $item['name'] ?? $item['code'] }}

                </div>

            @endforeach


        </div>

    @endif



    <div class="mt-6 flex justify-end">

        <form method="POST" action="{{ route('compras.import.confirm') }}">

    @csrf

    <button
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

@endsection