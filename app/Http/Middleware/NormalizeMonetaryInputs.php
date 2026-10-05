<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeMonetaryInputs
{
    private const FIELDS = [
        'cost', 'current_cost', 'unit_cost', 'sale_price', 'new_sale_price',
        'wholesale_price', 'special_price', 'price_a', 'price_b', 'price_c',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Only request data: never change uploaded files or unrelated numeric fields.
        $input = $request->isJson() ? $request->json() : $request->request;
        $input->replace($this->normalize($input->all()));

        return $next($request);
    }

    private function normalize(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->normalize($value);
            } elseif (in_array($key, self::FIELDS, true) && is_string($value)
                && preg_match('/^-?\d+,\d+$/D', trim($value))) {
                // No floats, rounding, or guessing thousands separators.
                $input[$key] = str_replace(',', '.', trim($value));
            }
        }

        return $input;
    }
}
