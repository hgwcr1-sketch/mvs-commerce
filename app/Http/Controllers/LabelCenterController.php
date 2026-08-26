<?php

namespace App\Http\Controllers;

use App\Models\BranchLabelSetting;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseVerification;
use App\Services\Labels\Code128Barcode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LabelCenterController extends Controller
{
    public const TEMPLATES = [
        'name_price' => 'Nombre + precio',
        'name_price_barcode' => 'Nombre + precio + código de barras',
        'barcode_large' => 'Código de barras grande',
        'price_large' => 'Precio grande',
        'sku' => 'Código / SKU',
        'custom_simple' => 'Plantilla configurable simple',
    ];

    public const SIZES = ['32x19' => '32 × 19 mm', '40x25' => '40 × 25 mm', '50x30' => '50 × 30 mm', '60x40' => '60 × 40 mm'];

    public function index(Request $request)
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');
        $query = Product::query()->where('company_id', $companyId)->with(['category:id,name', 'brand:id,name', 'barcodes' => fn ($query) => $query->where('is_active', true)]);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('internal_code', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")
                ->orWhereHas('barcodes', fn ($barcode) => $barcode->where('is_active', true)->where('barcode', 'like', "%{$search}%")));
        }
        foreach (['category_id', 'brand_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->integer($filter));
            }
        }
        if ($request->filled('prints_label')) {
            $query->where('prints_label', $request->boolean('prints_label'));
        }

        $products = $query->orderBy('name')->paginate(24)->withQueryString();
        $categories = ProductCategory::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $brands = Brand::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $setting = BranchLabelSetting::firstOrCreate(['company_id' => $companyId, 'branch_id' => $branchId], ['print_destinations' => ['administrator']]);

        return view('labels.index', ['products' => $products, 'categories' => $categories, 'brands' => $brands, 'setting' => $setting, 'templates' => self::TEMPLATES, 'sizes' => self::SIZES]);
    }

    public function updateProduct(Request $request, Product $product)
    {
        abort_unless((int) $product->company_id === (int) session('active_company_id'), 404);
        $product->update(['prints_label' => $request->boolean('prints_label')]);
        return back()->with('success', 'Preferencia de etiqueta actualizada.');
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'print_destinations' => ['required', 'array', 'min:1'],
            'print_destinations.*' => ['required', Rule::in(['cashier', 'administrator'])],
            'default_template' => ['required', Rule::in(array_keys(self::TEMPLATES))],
            'default_size' => ['required', Rule::in(array_keys(self::SIZES))],
            'custom_heading' => ['nullable', 'string', 'max:80'],
        ]);
        BranchLabelSetting::updateOrCreate(['company_id' => session('active_company_id'), 'branch_id' => session('active_branch_id')], $data);
        return back()->with('success', 'Configuración de la sucursal actualizada.');
    }

    public function preview(Request $request, Code128Barcode $barcode)
    {
        $data = $request->validate([
            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*' => ['required', 'integer', 'distinct'],
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'integer', 'min:1', 'max:500'],
            'template' => ['required', Rule::in(array_keys(self::TEMPLATES))],
            'size' => ['required', Rule::in(array_keys(self::SIZES))],
        ]);
        $products = Product::query()->where('company_id', session('active_company_id'))->whereIn('id', $data['products'])->with(['barcodes' => fn ($q) => $q->where('is_active', true)->orderByDesc('is_primary')])->get()->keyBy('id');
        abort_unless($products->count() === count($data['products']), 422);
        $labels = collect($data['products'])->flatMap(function ($id) use ($data, $products, $barcode) {
            $product = $products[$id];
            $quantity = (int) ($data['quantities'][$id] ?? 1);
            $code = $product->barcode ?: $product->barcodes->first()?->barcode;
            return collect(range(1, $quantity))->map(fn () => ['product' => $product, 'barcode' => $code, 'barcode_svg' => $barcode->svg($code)]);
        });
        $setting = BranchLabelSetting::where('company_id', session('active_company_id'))->where('branch_id', session('active_branch_id'))->first();
        return view('labels.preview', ['labels' => $labels, 'template' => $data['template'], 'size' => $data['size'], 'setting' => $setting]);
    }

    public function fromVerification(Request $request, PurchaseVerification $purchaseVerification, Code128Barcode $barcode)
    {
        abort_unless((int) $purchaseVerification->company_id === (int) session('active_company_id') && (int) $purchaseVerification->branch_id === (int) session('active_branch_id'), 404);
        abort_unless(in_array($purchaseVerification->status, ['conform', 'closed'], true), 422);
        $items = $purchaseVerification->items()->where('received_quantity', '>', 0)->whereHas('product', fn ($query) => $query->where('company_id', session('active_company_id'))->where('prints_label', true))->with(['product.barcodes' => fn ($query) => $query->where('is_active', true)->orderByDesc('is_primary')])->get();
        if ($items->isEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['labels' => 'La recepción no contiene productos marcados para imprimir etiqueta.']);
        }
        $setting = BranchLabelSetting::firstOrCreate(['company_id' => session('active_company_id'), 'branch_id' => session('active_branch_id')], ['print_destinations' => ['administrator']]);
        $labels = $items->flatMap(function ($item) use ($barcode) {
            $code = $item->product->barcode ?: $item->product->barcodes->first()?->barcode;
            return collect(range(1, max(1, (int) floor((float) $item->received_quantity))))->map(fn () => ['product' => $item->product, 'barcode' => $code, 'barcode_svg' => $barcode->svg($code)]);
        });
        return view('labels.preview', ['labels' => $labels, 'template' => $setting->default_template, 'size' => $setting->default_size, 'setting' => $setting]);
    }
}
