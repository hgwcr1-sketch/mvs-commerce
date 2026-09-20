<span class="cobros-money">{{ isset($currency) ? $currency.' ' : '' }}{{ \App\Support\Cobros\Money::format((string) $amount) }}</span>
