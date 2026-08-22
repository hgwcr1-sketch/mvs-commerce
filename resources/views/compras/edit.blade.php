@extends('layouts.app')

@section('title', 'Editar Compra')

@section('description', 'Modificar una compra registrada.')

@section('content')

<script>
window.purchaseEdit = {
    supplier: @json($purchase->supplier),
    date: "{{ $purchase->purchase_date->format('Y-m-d') }}",
    payment: "{{ $purchase->payment_type }}",
    notes: @json($purchase->notes),
    items: @json($purchase->items)
};
</script>
<div
    x-data="purchaseForm()"
    class="space-y-6">

    {{-- VOLVER --}}
    <div class="flex justify-end">
        <a
            href="{{ route('compras.index') }}"
            class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
            Volver
        </a>
    </div>

    {{-- PROVEEDOR --}}
    <x-card>

        <x-slot:header>
            <h3 class="text-lg font-semibold">
                Proveedor
            </h3>
        </x-slot:header>

        <div class="space-y-3">

            <div class="relative">

                <label class="mb-1 block text-sm font-medium text-slate-700">
                    Buscar proveedor
                </label>

                <input
                    type="text"
                    x-model="supplierSearch"
                    @input.debounce.300ms="searchSuppliers()"
                    @focus="supplierOpen = supplierResults.length > 0"
                    placeholder="Nombre, identificación, teléfono o correo..."
                    autocomplete="off"
                    class="w-full rounded-lg border border-slate-300 px-4 py-2 focus:border-amber-500 focus:ring-amber-500">

                <div
                    x-show="supplierOpen"
                    @click.outside="supplierOpen = false"
                    x-cloak
                    class="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">

                    <template
    x-for="supplier in supplierResults"
    :key="supplier.id">

    <button
        type="button"
        @click="selectSupplier(supplier)"
        class="block w-full border-b border-slate-100 px-4 py-3 text-left hover:bg-amber-50">

        <div
            class="font-semibold text-slate-900"
            x-text="supplier.commercial_name || supplier.name">
        </div>

        <div
            class="mt-1 text-sm text-slate-500"
            x-text="supplier.identification || 'Sin identificación'">
        </div>

    </button>

</template>
                    <div
                        x-show="supplierSearch.length > 0 && supplierResults.length === 0 && !supplierLoading"
                        class="p-4">

                        <p class="mb-3 text-sm text-slate-600">
                            No encontramos proveedores con esta búsqueda.
                        </p>

                        <button
    type="button"
    @click="supplierModalOpen = true"
    class="inline-flex rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">
    + Crear proveedor
</button>

                    </div>

                </div>

            </div>

            <div
                x-show="selectedSupplier"
                x-cloak
                class="rounded-lg border border-amber-200 bg-amber-50 p-4">

                <div class="flex items-start justify-between gap-4">

                    <div>
                        <p class="text-xs font-semibold uppercase text-slate-500">
                            Proveedor seleccionado
                        </p>

                        <p
                            class="mt-1 font-semibold text-slate-900"
                            x-text="selectedSupplier?.commercial_name || selectedSupplier?.name">
                        </p>

                        <p
                            class="text-sm text-slate-600"
                            x-text="selectedSupplier?.identification || 'Sin identificación'">
                        </p>
                    </div>

                    <button
                        type="button"
                        @click="clearSupplier()"
                        class="text-sm font-medium text-slate-600 hover:text-slate-900">
                        Cambiar
                    </button>

                </div>

            </div>

        </div>

    </x-card>

    {{-- DATOS DE LA COMPRA --}}
<x-card>

    <x-slot:header>
        <div>
            <h3 class="text-lg font-semibold">
                Datos de la compra
            </h3>

            <p class="mt-1 text-sm text-slate-500">
                Información del documento del proveedor.
            </p>
        </div>
    </x-slot:header>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">

        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">
                Fecha de compra *
            </label>

            <input
                type="date"
                x-model="purchaseDate"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-amber-500 focus:ring-amber-500">
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">
                Nº factura del proveedor
            </label>

            <input
                type="text"
                x-model="supplierInvoiceNumber"
                placeholder="Ej: FAC-00125"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-amber-500 focus:ring-amber-500">
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">
                Tipo de pago *
            </label>

            <select
                x-model="paymentType"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-amber-500 focus:ring-amber-500">

                <option value="cash">
                    Contado
                </option>

                <option value="credit">
                    Crédito
                </option>

            </select>
        </div>

        <div x-show="paymentType === 'credit'" x-cloak>
            <label class="mb-1 block text-sm font-medium text-slate-700">
                Fecha de vencimiento *
            </label>

            <input
                type="date"
                x-model="dueDate"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-amber-500 focus:ring-amber-500">
        </div>

        <div class="md:col-span-2">
            <label class="mb-1 block text-sm font-medium text-slate-700">
                Observaciones
            </label>

            <textarea
                x-model="purchaseNotes"
                rows="3"
                placeholder="Observaciones adicionales de la compra..."
                class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-amber-500 focus:ring-amber-500"></textarea>
        </div>

    </div>

</x-card>

    {{-- PRODUCTOS --}}
    <x-card>

        <x-slot:header>
            <div>
                <h3 class="text-lg font-semibold">
                    Productos
                </h3>

                <p class="mt-1 text-sm text-slate-500">
                    Busque por nombre, código interno, código de barras o marca.
                </p>
            </div>
        </x-slot:header>

        <div class="space-y-4">

            <div class="relative">

                <label class="mb-1 block text-sm font-medium text-slate-700">
                    Buscar o escanear producto
                </label>

                <input
                    type="text"
                    x-model="productSearch"
                    @input.debounce.250ms="searchProducts()"
                    @keydown.enter.prevent="searchProducts()"
                    @focus="productOpen = productResults.length > 0"
                    placeholder="Escanee código o escriba nombre, código o marca..."
                    autocomplete="off"
                    class="w-full rounded-lg border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-amber-500">

                <div
                    x-show="productOpen"
                    @click.outside="productOpen = false"
                    x-cloak
                    class="absolute z-30 mt-1 max-h-96 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">

                    <template
                        x-for="product in productResults"
                        :key="product.id">

                        <button
                            type="button"
                            @click="addProduct(product)"
                            class="block w-full border-b border-slate-100 px-4 py-3 text-left hover:bg-amber-50">

                            <div class="flex items-start justify-between gap-4">

                                <div>
                                    <div
                                        class="font-semibold text-slate-800"
                                        x-text="product.name">
                                    </div>

                                    <div class="mt-1 text-sm text-slate-500">
                                        <span x-text="product.internal_code"></span>

                                        <span x-show="product.brand">
                                            ·
                                            <span x-text="product.brand"></span>
                                        </span>

                                        <span x-show="product.category">
                                            ·
                                            <span x-text="product.category"></span>
                                        </span>
                                    </div>

                                    <div class="mt-1 text-xs text-slate-500">
                                        Código:
                                        <span x-text="product.barcode || 'Sin código principal'"></span>
                                    </div>
                                </div>

                                <div class="shrink-0 text-right text-sm">

                                    <div>
                                        Stock:
                                        <strong x-text="formatNumber(product.stock)"></strong>
                                    </div>

                                    <div class="text-slate-500">
                                        Costo:
                                        <span x-text="money(product.cost)"></span>
                                    </div>

                                    <div class="text-slate-500">
                                        Precio:
                                        <span x-text="money(product.sale_price)"></span>
                                    </div>

                                </div>

                            </div>

                        </button>

                    </template>

                    <div
                        x-show="productSearch.length > 0 && productResults.length === 0 && !productLoading"
                        class="p-4">

                        <p class="font-medium text-slate-800">
                            Producto no encontrado
                        </p>

                        <p class="mt-1 text-sm text-slate-500">
                            Antes de crear uno nuevo, compruebe que no exista con otro nombre o código.
                        </p>

                        <div class="mt-3 flex flex-wrap gap-2">

    <button
        type="button"
        @click="productOpen = false"
        class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100">
        Buscar producto existente
    </button>

    <button
        type="button"
        @click="productModalOpen = true; productOpen = false"
        class="rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">
        + Crear producto
    </button>

</div>

                    </div>

                </div>

            </div>

            {{-- DETALLE --}}
            <div
                x-show="items.length === 0"
                class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">
                Todavía no hay productos agregados a esta compra.
            </div>

            <div
                x-show="items.length > 0"
                x-cloak
                class="overflow-x-auto">

                <table class="min-w-full divide-y divide-slate-200">

                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase text-slate-600">
                                Producto
                            </th>

                            <th class="px-3 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                                Stock
                            </th>

                            <th class="px-3 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                                Cantidad
                            </th>

                            <th class="px-3 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                                Costo
                            </th>

                            <th class="px-3 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                                Precio actual
                            </th>

                            <th class="px-3 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                                Nuevo precio
                            </th>

                            <th class="px-3 py-3 text-right text-xs font-semibold uppercase text-slate-600">
                                Total
                            </th>

                            <th class="px-3 py-3"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100 bg-white">

                        <template
                            x-for="(item, index) in items"
                            :key="item.id">

                            <tr>

                                <td class="px-3 py-3">
                                    <div
                                        class="font-medium text-slate-800"
                                        x-text="item.name">
                                    </div>

                                    <div
                                        class="text-xs text-slate-500"
                                        x-text="item.internal_code">
                                    </div>
                                </td>

                                <td
                                    class="px-3 py-3 text-right text-sm"
                                    x-text="formatNumber(item.stock)">
                                </td>

                                <td class="px-3 py-3">
                                    <input
                                        type="number"
                                        :min="(item.allows_decimals ?? item.product?.unit?.allows_decimals) ? 0.0001 : 1"
                                        :step="(item.allows_decimals ?? item.product?.unit?.allows_decimals) ? 0.0001 : 1"
                                        x-model.number="item.quantity"
                                        class="w-24 rounded-lg border border-slate-300 px-2 py-2 text-right">
                                </td>

                                <td class="px-3 py-3">
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        x-model.number="item.unit_cost"
                                        class="w-28 rounded-lg border border-slate-300 px-2 py-2 text-right">
                                </td>

                                <td
                                    class="px-3 py-3 text-right text-sm"
                                    x-text="money(item.sale_price)">
                                </td>

                                <td class="px-3 py-3">
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        x-model.number="item.new_sale_price"
                                        placeholder="Opcional"
                                        class="w-28 rounded-lg border border-slate-300 px-2 py-2 text-right">
                                </td>

                                <td
                                    class="px-3 py-3 text-right font-semibold"
                                    x-text="money(lineTotal(item))">
                                </td>

                                <td class="px-3 py-3 text-right">
                                    <button
                                        type="button"
                                        @click="removeProduct(index)"
                                        class="text-sm font-medium text-red-600 hover:text-red-800">
                                        Quitar
                                    </button>
                                </td>

                            </tr>

                        </template>

                    </tbody>

                </table>

            </div>

        </div>

    </x-card>

    {{-- RESUMEN --}}
    <x-card>

        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">

            <div class="text-sm text-slate-500">
                <span x-text="items.length"></span>
                producto(s) agregado(s)
            </div>

            <div class="text-right">

                <p class="text-sm text-slate-500">
                    Total preliminar
                </p>

                <p
                    class="text-2xl font-bold text-slate-900"
                    x-text="money(grandTotal())">
                </p>

            </div>

        </div>

<div class="mt-6 flex justify-end">

    <button
        type="button"
        @click="savePurchase()"
        :disabled="purchaseSaving"
        class="rounded-lg bg-amber-500 px-6 py-3 font-semibold text-white hover:bg-amber-600 disabled:cursor-not-allowed disabled:opacity-50">

        <span x-show="!purchaseSaving">
            Guardar compra
        </span>

        <span x-show="purchaseSaving">
            Guardando...
        </span>

    </button>

<div class="mt-6 flex justify-end">

</div>

</div>

    </x-card>

{{-- MODAL CREAR PROVEEDOR --}}
<div
    x-show="supplierModalOpen"
    x-cloak
    @keydown.escape.window="supplierModalOpen = false"
    class="fixed inset-0 z-50 flex items-center justify-center p-4">

    {{-- Fondo --}}
    <div
        class="absolute inset-0 bg-black/50"
        @click="supplierModalOpen = false">
    </div>

    {{-- Ventana --}}
    <div
        @click.stop
        class="relative z-10 w-full max-w-3xl rounded-xl bg-white shadow-2xl">

        {{-- Encabezado --}}
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">

            <div>
                <h3 class="text-lg font-semibold text-slate-900">
                    Crear proveedor
                </h3>

                <p class="mt-1 text-sm text-slate-500">
                    Registre el proveedor sin salir de la compra.
                </p>
            </div>

            <button
                type="button"
                @click="supplierModalOpen = false"
                class="rounded-lg px-3 py-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700">
                ✕
            </button>

        </div>

        {{-- Contenido --}}
        <div class="max-h-[70vh] overflow-y-auto p-6">

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Nombre / Razón Social *
                    </label>

                    <input
                        type="text"
                        x-model="newSupplier.name"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-amber-500 focus:ring-amber-500"
                        placeholder="Nombre del proveedor">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Nombre comercial
                    </label>

                    <input
                        type="text"
                        x-model="newSupplier.commercial_name"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                        placeholder="Opcional">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Identificación
                    </label>

                    <input
                        type="text"
                        x-model="newSupplier.identification"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                        placeholder="Cédula o identificación">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Teléfono
                    </label>

                    <input
                        type="text"
                        x-model="newSupplier.phone"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                        placeholder="Teléfono">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Celular
                    </label>

                    <input
                        type="text"
                        x-model="newSupplier.mobile"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                        placeholder="Celular">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Correo electrónico
                    </label>

                    <input
                        type="email"
                        x-model="newSupplier.email"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                        placeholder="correo@ejemplo.com">
                </div>

            </div>

        </div>

        {{-- Pie --}}
        <div class="flex justify-end gap-3 border-t border-slate-200 px-6 py-4">

            <button
                type="button"
                @click="supplierModalOpen = false"
                class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
                Cancelar
            </button>

            <button
                type="button"
                @click="saveSupplier()"
                @click="saveProduct()"
                class="rounded-lg bg-amber-500 px-5 py-2 font-semibold text-white hover:bg-amber-600">
                Guardar proveedor
            </button>

        </div>

    </div>

</div>

{{-- MODAL CREAR PRODUCTO --}}
<div
    x-show="productModalOpen"
    x-cloak
    @keydown.escape.window="productModalOpen = false"
    class="fixed inset-0 z-50 flex items-center justify-center p-4">

    {{-- Fondo --}}
    <div
        class="absolute inset-0 bg-black/50"
        @click="productModalOpen = false">
    </div>

    {{-- Ventana --}}
    <div
        @click.stop
        class="relative z-10 w-full max-w-4xl rounded-xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-900">
                    Crear producto
                </h3>

                <p class="mt-1 text-sm text-slate-500">
                    Registre el producto sin salir de la compra.
                </p>
            </div>

            <button
                type="button"
                @click="productModalOpen = false"
                class="rounded-lg px-3 py-2 text-slate-500 hover:bg-slate-100">
                ✕
            </button>
        </div>

        <div class="max-h-[70vh] overflow-y-auto p-6">

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Nombre *
                    </label>

                    <input
                        type="text"
                        x-model="newProduct.name"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Código interno *
                    </label>

                    <input
                        type="text"
                        x-model="newProduct.internal_code"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Código de barras
                    </label>

                    <input
                        type="text"
                        x-model="newProduct.barcode"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Categoría *
                    </label>

                    <select
                        x-model="newProduct.category_id"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2">

                        <option value="">Seleccione</option>

                        @foreach($categories as $category)
                            <option value="{{ $category->id }}">
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Marca
                    </label>

                    <select
                        x-model="newProduct.brand_id"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2">

                        <option value="">Sin marca</option>

                        @foreach($brands as $brand)
                            <option value="{{ $brand->id }}">
                                {{ $brand->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Unidad *
                    </label>

                    <select
                        x-model="newProduct.unit_id"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2">

                        <option value="">Seleccione</option>

                        @foreach($units as $unit)
                            <option value="{{ $unit->id }}">
                                {{ $unit->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Costo *
                    </label>

                    <input
                        type="number"
                        min="0"
                        step="1"
                        x-model.number="newProduct.cost"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Precio de venta *
                    </label>

                    <input
                        type="number"
                        min="0"
                        step="1"
                        x-model.number="newProduct.sale_price"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        Impuesto %
                    </label>

                    <select
                        x-model.number="newProduct.tax_rate"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        <option value="0">0%</option>
                        <option value="1">1%</option>
                        <option value="2">2%</option>
                        <option value="4">4%</option>
                        <option value="13">13%</option>
                    </select>
                </div>

            </div>

        </div>

        <div class="flex justify-end gap-3 border-t border-slate-200 px-6 py-4">

            <button
                type="button"
                @click="productModalOpen = false"
                class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
                Cancelar
            </button>

            <button
    type="button"
    @click="saveProduct()"
    class="rounded-lg bg-amber-500 px-5 py-2 font-semibold text-white hover:bg-amber-600">
    Guardar producto
</button>

        </div>

    </div>

</div>

</div>

{{-- CIERRE DEL COMPONENTE purchaseForm --}}
</div>

@endsection
