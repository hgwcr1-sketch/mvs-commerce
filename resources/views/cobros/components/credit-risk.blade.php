{{-- 11B (corrección visual) — silueta COMPLETA de persona (cabeza, cuerpo, brazos, piernas) rellena según riesgo: verde=al día, amarillo=1 vencida, rojo=2+. Sin círculo/fondo/borde/punto. Solo lectura, reutiliza 11A con memoización por request. --}}
@props(['level' => null, 'customer' => null, 'company' => null, 'label' => null, 'size' => 'md'])
@php
    if ($level === null && $customer !== null && $company !== null) {
        static $riskCache = [];
        $key = ((int) $company->getKey()).':'.((int) $customer->getKey());
        if (! array_key_exists($key, $riskCache)) {
            $riskCache[$key] = app(\App\Services\Cobros\CreditRiskService::class)->classify($company, $customer)['level'];
        }
        $level = $riskCache[$key];
    }
    $level = in_array($level, ['verde', 'amarillo', 'rojo'], true) ? $level : 'verde';
    $labels = ['verde' => 'Al día', 'amarillo' => '1 cuota vencida', 'rojo' => '2 o más vencidas'];
    $text = $label ?? $labels[$level];
    $stroke = ['verde' => '#00B84A', 'amarillo' => '#EAB308', 'rojo' => '#DC2626'][$level];
    $svgClass = 'h-6 w-6'; // 24px: clase ya presente en el CSS compilado (evita depender de rebuild de Vite)
@endphp
<span class="inline-flex min-w-0 items-center gap-1.5" role="img" aria-label="Estado de crédito: {{ $text }}" title="Estado de crédito: {{ $text }}">
    {{-- Icono outline tipo usuario: cabeza circular + hombros curvos, trazo 2px, color entero del riesgo. --}}
    <svg class="{{ $svgClass }} shrink-0" viewBox="0 0 24 24" fill="none" stroke="{{ $stroke }}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="8" r="4"/>
        <path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>
    </svg>
    <span class="sr-only">{{ $text }}</span>
</span>
