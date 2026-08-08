<?php

namespace App\Services\Purchases;

use App\Models\Company;
use App\Models\CompanyPurchaseSetting;

class CompanyPurchaseSettingsResolver
{
    /**
     * Obtiene la configuración de compras de la empresa.
     *
     * Si todavía no existe, crea el registro con las políticas seguras
     * por defecto: sin categoría/unidad configuradas y con proveedor
     * obligatorio antes de confirmar una compra.
     */
    public function forCompany(Company $company): CompanyPurchaseSetting
    {
        return CompanyPurchaseSetting::firstOrCreate(
            ['company_id' => $company->id],
            ['supplier_assignment_required' => true],
        );
    }

    /**
     * Prioriza la categoría recibida del origen; si no existe, usa
     * la categoría predeterminada configurada para la empresa.
     */
    public function resolveCategoryId(
        Company $company,
        ?int $sourceCategoryId,
    ): ?int {
        return $sourceCategoryId
            ?? $this->forCompany($company)->default_product_category_id;
    }

    /**
     * Prioriza la unidad recibida del origen; si no existe, usa
     * la unidad predeterminada configurada para la empresa.
     */
    public function resolveUnitId(
        Company $company,
        ?int $sourceUnitId,
    ): ?int {
        return $sourceUnitId
            ?? $this->forCompany($company)->default_unit_id;
    }

    /**
     * Indica si una compra no puede confirmarse hasta asignar proveedor.
     */
    public function requiresSupplierAssignment(Company $company): bool
    {
        return $this->forCompany($company)->supplier_assignment_required;
    }
}
