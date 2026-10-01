<?php

namespace App\Services\Fiscal;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Validation\ValidationException;

class FiscalPreflightService
{
    /** Local validation only; ordinary POS tickets do not require fiscal data. */
    public function validate(string $documentType, Company $company, ?Customer $customer, array $lines): void
    {
        if (! in_array($documentType, [Sale::DOCUMENT_ELECTRONIC_INVOICE, Sale::DOCUMENT_ELECTRONIC_TICKET], true)) {
            return;
        }

        $errors = [];
        if (blank($company->identification_number) || blank($company->legal_name)) {
            $errors['document_type'] = 'Complete la identificación y el nombre legal del emisor antes de emitir FE/TE.';
        }
        if ($documentType === Sale::DOCUMENT_ELECTRONIC_INVOICE
            && ($customer === null || blank($customer->identification_type) || blank($customer->identification))) {
            $errors['customer_id'] = 'La factura electrónica requiere un receptor con identificación fiscal.';
        }

        foreach ($lines as $index => $line) {
            $product = $line['product'];
            if ($line['fiscalProfile'] === null) {
                $errors["items.{$index}.fiscal_profile_id"] = "Confirme el perfil fiscal de {$product->name} antes de emitir FE/TE.";
            }
            if (! preg_match('/^\d{13}$/', (string) $product->cabys_code)) {
                $errors["items.{$index}.cabys_code"] = "Complete el CABYS de {$product->name} antes de emitir FE/TE.";
            }
            if (blank($product->unit?->abbreviation)) {
                $errors["items.{$index}.unit_code"] = "Complete la unidad de {$product->name} antes de emitir FE/TE.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
