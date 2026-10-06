<?php

namespace App\Services\Fiscal;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Sale;
use App\Support\IdentificationRules;
use Illuminate\Validation\ValidationException;

class FiscalPreflightService
{
    public function __construct(private readonly CabysPreflightGate $cabys) {}

    /** Local validation only; ordinary POS tickets do not require fiscal data. */
    public function validate(string $documentType, Company $company, ?Customer $customer, array $lines): void
    {
        if (! in_array($documentType, [Sale::DOCUMENT_ELECTRONIC_INVOICE, Sale::DOCUMENT_ELECTRONIC_TICKET], true)) {
            return;
        }

        $errors = [];
        if (blank($company->identification_number) || blank($company->legal_name)) {
            $errors['document_type'] = 'Complete la identificación y el nombre legal del emisor antes de emitir FE/TE.';
        } elseif (config('facturaencr.environment', 'sandbox') !== 'sandbox'
            && filled($company->identification_type)
            && ! IdentificationRules::isValidForFiscal($company->identification_type, $company->identification_number)) {
            $errors['document_type'] = 'La identificación del emisor no cumple el formato fiscal: '
                .IdentificationRules::message($company->identification_type, $company->identification_number);
        }
        if ($documentType === Sale::DOCUMENT_ELECTRONIC_INVOICE
            && ($customer === null || blank($customer->identification_type) || blank($customer->identification))) {
            $errors['customer_id'] = 'La factura electrónica requiere un receptor con identificación fiscal.';
        } elseif ($customer !== null
            && filled($customer->identification_type)
            && ! IdentificationRules::isValidForFiscal($customer->identification_type, $customer->identification)) {
            $errors['customer_identification'] = 'La identificación del receptor no cumple el formato fiscal ('
                .IdentificationRules::label($customer->identification_type).'): '
                .IdentificationRules::message($customer->identification_type, $customer->identification);
        }

        foreach ($lines as $index => $line) {
            $product = $line['product'];
            if ($line['fiscalProfile'] === null) {
                $errors["items.{$index}.fiscal_profile_id"] = "Confirme el perfil fiscal de {$product->name} antes de emitir FE/TE.";
            }
            $cabysProblem = $this->cabys->problem($product->cabys_code);
            if ($cabysProblem !== null) {
                $errors["items.{$index}.cabys_code"] = match ($cabysProblem) {
                    CabysPreflightGate::REASON_NOT_IN_CATALOG => sprintf(
                        'El CABYS %s de %s no existe en el catálogo vigente (%s) del BCCR: no se emite ni se corrige automáticamente.',
                        trim((string) $product->cabys_code),
                        $product->name,
                        $this->cabys->activeVersionLabel() ?? 'sin versión activa',
                    ),
                    default => "Complete el CABYS de {$product->name} antes de emitir FE/TE.",
                };
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
