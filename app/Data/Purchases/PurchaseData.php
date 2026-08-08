<?php

namespace App\Data\Purchases;

final readonly class PurchaseData
{
    /**
     * @param list<PurchaseLineData> $lines
     */
    public function __construct(
        public int $company_id,
        public int $branch_id,
        public ?int $supplier_id,
        public ?int $user_id,
        public ?string $purchase_date,
        public string $payment_type,
        public ?string $supplier_invoice_number = null,
        public ?string $due_date = null,
        public ?string $notes = null,
        public array $lines = [],
    ) {
    }
}
