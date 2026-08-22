@csrf

{{-- =========================
    INFORMACIÓN GENERAL
========================= --}}

<x-card>

    <x-slot:header>

        <h3 class="text-lg font-semibold">
            Información General
        </h3>

    </x-slot:header>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <x-input
            name="name"
            label="Nombre del Producto"
            :value="$product->name ?? ''"
            required />

        <x-input
            name="internal_code"
            label="Código Interno"
            :value="$product->internal_code ?? ''"
            required />

        <x-input
            name="barcode"
            label="Código de Barras"
            :value="$product->barcode ?? ''" />

        <x-input
            name="cabys_code"
            label="Código CABYS"
            :value="$product->cabys_code ?? ''" />

        <x-select
            name="product_type"
            label="Tipo">

            <option value="product" @selected(old('product_type',$product->product_type ?? '')=='product')>Producto</option>
            <option value="service" @selected(old('product_type',$product->product_type ?? '')=='service')>Servicio</option>
            <option value="combo" @selected(old('product_type',$product->product_type ?? '')=='combo')>Combo</option>

        </x-select>

        <x-select
            name="category_id"
            label="Categoría">

            <option value="">Seleccione...</option>

            @foreach($categories as $category)

                <option
                    value="{{ $category->id }}"
                    @selected(old('category_id',$product->category_id ?? '')==$category->id)>

                    {{ $category->name }}

                </option>

            @endforeach

        </x-select>

        <x-select
            name="brand_id"
            label="Marca">

            <option value="">Seleccione...</option>

            @foreach($brands as $brand)

                <option
                    value="{{ $brand->id }}"
                    @selected(old('brand_id',$product->brand_id ?? '')==$brand->id)>

                    {{ $brand->name }}

                </option>

            @endforeach

        </x-select>

        <x-select
            name="unit_id"
            label="Unidad">

            <option value="">Seleccione...</option>

            @foreach($units as $unit)

                <option
                    value="{{ $unit->id }}"
                    data-allows-decimals="{{ $unit->allows_decimals ? '1' : '0' }}"
                    @selected(old('unit_id',$product->unit_id ?? '')==$unit->id)>

                    {{ $unit->name }}

                </option>

            @endforeach

        </x-select>

    </div>

</x-card>


{{-- =========================
    PRECIOS
========================= --}}

<x-card class="mt-6">

    <x-slot:header>
        <h3 class="text-lg font-semibold">
            Precios
        </h3>
    </x-slot:header>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

        <x-input
            type="number"
            step="1"
            name="cost"
            label="Costo"
            :value="old('cost', $product->cost ?? 0)" />

        <x-input
            type="number"
            step="1"
            name="sale_price"
            label="Precio Venta"
            :value="old('sale_price', $product->sale_price ?? 0)" />

        <x-input
            type="number"
            step="1"
            name="wholesale_price"
            label="Precio Mayorista"
            :value="old('wholesale_price', $product->wholesale_price ?? '')" />

        <x-input
            type="number"
            step="1"
            name="special_price"
            label="Precio Oferta"
            :value="old('special_price', $product->special_price ?? '')" />

        <x-input
            type="number"
            step="1"
            name="price_a"
            label="Precio A"
            :value="old('price_a', $product->price_a ?? '')" />

        <x-input
            type="number"
            step="1"
            name="price_b"
            label="Precio B"
            :value="old('price_b', $product->price_b ?? '')" />

        <x-input
            type="number"
            step="1"
            name="price_c"
            label="Precio C"
            :value="old('price_c', $product->price_c ?? '')" />

        <x-select
            name="tax_rate"
            label="Impuesto *">

            <option value="">Seleccione...</option>

            <option value="0"
                @selected(old('tax_rate', $product->tax_rate ?? '') == '0')>
                Exento (0%)
            </option>

            <option value="1"
                @selected(old('tax_rate', $product->tax_rate ?? '') == '1')>
                IVA 1%
            </option>

            <option value="2"
                @selected(old('tax_rate', $product->tax_rate ?? '') == '2')>
                IVA 2%
            </option>

            <option value="4"
                @selected(old('tax_rate', $product->tax_rate ?? '') == '4')>
                IVA 4%
            </option>

            <option value="8"
                @selected(old('tax_rate', $product->tax_rate ?? '') == '8')>
                IVA 8%
            </option>

            <option value="13"
                @selected(old('tax_rate', $product->tax_rate ?? '13') == '13')>
                IVA 13%
            </option>

        </x-select>

    </div>

</x-card>

{{-- =========================
    INVENTARIO
========================= --}}

<x-card class="mt-6">

    <x-slot:header>

        <h3 class="text-lg font-semibold">
            Inventario
        </h3>

    </x-slot:header>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

    @if(isset($product))

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">
                Stock Actual
            </label>

            <input
                type="number"
                step="{{ $product->unit?->allows_decimals ? '0.0001' : '1' }}"
                value="{{ $product->branch_stock ?? 0 }}"
                readonly
                class="form-input w-full bg-slate-100 cursor-not-allowed">

            <p class="mt-1 text-xs text-slate-500">
                Las existencias se modifican desde Movimientos de Inventario.
            </p>
        </div>

    @else

        <x-input
            type="number"
            step="{{ old('unit_id', $product->unit_id ?? null) && $units->firstWhere('id', (int) old('unit_id', $product->unit_id ?? 0))?->allows_decimals ? '0.0001' : '1' }}"
            name="stock"
            label="Stock Inicial"
            :value="old('stock', 0)" />

    @endif

    <x-input
        type="number"
        step="{{ old('unit_id', $product->unit_id ?? null) && $units->firstWhere('id', (int) old('unit_id', $product->unit_id ?? 0))?->allows_decimals ? '0.0001' : '1' }}"
        name="minimum_stock"
        label="Stock Mínimo"
        :value="old(
            'minimum_stock',
            isset($product)
                ? ($product->branch_minimum_stock ?? '')
                : 0
        )" />

    <x-input
        type="number"
        step="{{ old('unit_id', $product->unit_id ?? null) && $units->firstWhere('id', (int) old('unit_id', $product->unit_id ?? 0))?->allows_decimals ? '0.0001' : '1' }}"
        name="maximum_stock"
        label="Stock Máximo"
        :value="old(
            'maximum_stock',
            isset($product)
                ? ($product->branch_maximum_stock ?? '')
                : 0
        )" />

</div>

</x-card>

@once
<script>
document.addEventListener('DOMContentLoaded', () => {
    const unit = document.querySelector('[name="unit_id"]');
    if (!unit) return;
    const quantities = ['stock', 'minimum_stock', 'maximum_stock']
        .map(name => document.querySelector(`[name="${name}"]`))
        .filter(Boolean);
    const syncQuantityStep = () => {
        const fractional = unit.selectedOptions[0]?.dataset.allowsDecimals === '1';
        quantities.forEach(input => {
            input.min = '0';
            input.step = fractional ? '0.0001' : '1';
        });
    };
    unit.addEventListener('change', syncQuantityStep);
    syncQuantityStep();
});
</script>
@endonce


{{-- =========================
    DESCRIPCIONES
========================= --}}

<x-card class="mt-6">

    <x-slot:header>

        <h3 class="text-lg font-semibold">
            Descripciones
        </h3>

    </x-slot:header>

    <div class="space-y-6">

        <x-textarea
            name="short_description"
            label="Descripción corta"
            rows="3"
            :value="$product->short_description ?? ''" />

        <x-textarea
            name="description"
            label="Descripción"
            rows="6"
            :value="$product->description ?? ''" />

    </div>

</x-card>


{{-- =========================
    CONFIGURACIÓN
========================= --}}

<x-card class="mt-6">

    <x-slot:header>

        <h3 class="text-lg font-semibold">
            Configuración
        </h3>

    </x-slot:header>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        <x-checkbox
            name="track_inventory"
            label="Controlar inventario"
            :checked="old('track_inventory',$product->track_inventory ?? true)" />

        <x-checkbox
            name="allow_negative_stock"
            label="Permitir stock negativo"
            :checked="old('allow_negative_stock',$product->allow_negative_stock ?? false)" />

        <x-checkbox
            name="is_active"
            label="Producto activo"
            :checked="old('is_active',$product->is_active ?? true)" />

    </div>

</x-card>


{{-- =========================
    IMAGEN
========================= --}}

<x-card class="mt-6">

    <x-slot:header>

        <h3 class="text-lg font-semibold">
            Imagen del Producto
        </h3>

    </x-slot:header>

    <input
        type="file"
        name="image"
        class="form-input">

</x-card>
