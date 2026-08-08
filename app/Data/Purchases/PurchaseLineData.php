<?php

namespace App\Data\Purchases;

final readonly class PurchaseLineData
{
    public function __construct(
        public ?int $product_id = null,
        public ?string $code = null,
        public ?string $name = null,
        public ?string $barcode = null,
        public ?string $cabys = null,
        public ?string $brand = null,
        public ?string $category = null,
        public ?string $unit = null,
        public ?float $quantity = null,
        public ?float $unit_cost = null,
        public ?float $tax_rate = null,
        public ?float $discount_percent = null,
        public ?string $lot_number = null,
        public ?string $expires_at = null,
    ) {
    }
}
