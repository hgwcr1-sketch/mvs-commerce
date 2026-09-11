<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vista previa de etiquetas</title>
<style>
:root{--label-width:{{ explode('x',$size)[0] }}mm;--label-height:{{ explode('x',$size)[1] }}mm}
*{box-sizing:border-box}
body{margin:0;background:#e2e8f0;font-family:Arial,sans-serif;color:#0f172a}
.toolbar{position:sticky;top:0;z-index:2;display:flex;gap:12px;align-items:center;justify-content:space-between;padding:12px;background:#fff;border-bottom:1px solid #cbd5e1}
.toolbar button{min-height:44px;border:0;border-radius:10px;background:#D4AF37;color:#000;padding:0 18px;font-weight:700}
.sheet{display:grid;grid-template-columns:repeat(auto-fill,var(--label-width));gap:3mm;justify-content:center;padding:5mm}
.label{width:var(--label-width);height:var(--label-height);overflow:hidden;background:#fff;border:1px dashed #94a3b8;padding:2mm;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
.name{max-width:100%;font-size:10pt;font-weight:700;line-height:1.1}
.price{font-size:15pt;font-weight:900}
.barcode{width:100%;margin-top:1mm}
.label-barcode{display:block;width:100%;height:9mm}
.code{font-size:7pt;letter-spacing:.08em}
.barcode_large .label-barcode{height:14mm}
.price_large .price{font-size:24pt}
.sku .code{font-size:16pt;font-weight:800}
.meta{font-size:12px;color:#475569}

/* Thermal strip on screen */
.sheet.thermal-strip{display:flex;flex-direction:column;align-items:center;gap:0;border:2px dashed #94a3b8;border-radius:8px;padding:4mm;background:#f8fafc;overflow-x:auto}
.sheet.thermal-strip .label{border:none;border-bottom:1px dashed #cbd5e1;border-radius:0;flex-shrink:0}
.sheet.thermal-strip .label:last-child{border-bottom:none}

@media(max-width:767px){.toolbar{align-items:flex-start;flex-direction:column}.sheet{justify-content:start;overflow-x:auto}}

/* A4 print */
@media print{
    @page{size:auto;margin:5mm}
    body{background:#fff}
    .toolbar{display:none}
    .sheet{padding:0;gap:0;justify-content:start}
    .label{border:0;break-inside:avoid}
}

/* Thermal print */
@media print and (--thermal){
    @page{size:var(--label-width) var(--label-height);margin:0}
    body{background:#fff}
    .toolbar{display:none}
    .sheet{display:block;padding:0;gap:0}
    .label{border:0;width:var(--label-width);height:var(--label-height);page-break-after:always;break-after:page}
    .label:last-child{page-break-after:auto;break-after:auto}
}
</style></head><body>
<div class="toolbar"><div><strong>{{ $labels->count() }} etiquetas · {{ explode('x',$size)[0] }} × {{ explode('x',$size)[1] }} mm</strong>@if(($printMode ?? 'a4') === 'thermal')<span class="ml-2 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-[#B1922D]">Térmica</span>@endif<div class="meta">Destino: {{ collect($setting?->print_destinations ?? [])->map(fn($d)=>$d==='cashier'?'Cajero':'Administrador')->join(' y ') ?: 'Sin configurar' }}</div></div><button onclick="window.print()">Imprimir</button></div>
<main class="sheet{{ ($printMode ?? 'a4') === 'thermal' ? ' thermal-strip' : '' }}" data-responsive="360 768 1280">@foreach($labels as $label)<article class="label {{ $template }}">
@if(in_array($template,['name_price','name_price_barcode','custom_simple']))<div class="name">{{ $template==='custom_simple' && $setting?->custom_heading ? $setting->custom_heading.' · ' : '' }}{{ $label['product']->name }}</div>@endif
@if(in_array($template,['name_price','name_price_barcode','price_large','custom_simple']))<div class="price">₡{{ number_format($label['product']->sale_price,2,',','.') }}</div>@endif
@if(in_array($template,['name_price_barcode','barcode_large']) && $label['barcode_svg'])<div class="barcode">{!! $label['barcode_svg'] !!}<div class="code">{{ $label['barcode'] }}</div></div>@elseif($template==='sku')<div class="code">{{ $label['product']->internal_code }}</div>@elseif(in_array($template,['name_price_barcode','barcode_large']))<div class="code">Sin código de barras</div>@endif
</article>@endforeach</main>
@if(($printMode ?? 'a4') === 'thermal')
<script>
(function(){
    var w = {{ (int) explode('x', $size)[0] }};
    var h = {{ (int) explode('x', $size)[1] }};
    var style = document.createElement('style');
    style.textContent = '@media print{@page{size:' + w + 'mm ' + h + 'mm;margin:0}.sheet{display:block;padding:0;gap:0}.label{border:0;width:' + w + 'mm;height:' + h + 'mm;page-break-after:always;break-after:page}.label:last-child{page-break-after:auto;break-after:auto}}';
    document.head.appendChild(style);
})();
</script>
@endif</body></html>
