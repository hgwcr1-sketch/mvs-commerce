<span class="cobros-money">{{ ($currency ?? null) === 'CRC' ? '₡' : (isset($currency) ? $currency.' ' : '') }}{{ \App\Support\Cobros\Money::format((string) $amount) }}</span>
