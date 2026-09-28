<?php

namespace App\Services\Cabys;

use App\Models\CabysCatalogEntry;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCabysAssignment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Asignación de CABYS a productos POR EMPRESA, y sincronización del impuesto
 * oficial (MF04 + T2 adaptados a producción).
 *
 * Tres reglas gobiernan todo el servicio:
 *
 *  1. NADA se escribe sin confirmación humana. `suggest()` deja la asignación
 *     `pending`; `confirm()` es el único que la vuelve definitiva.
 *  2. NINGUNA escritura ocurre en el camino de la venta. `codeFor()` es una
 *     lectura de la asignación ya confirmada, sin red y sin validación
 *     bloqueante. El POS copia el valor; no lo decide.
 *  3. AISLAMIENTO por empresa: toda lectura y escritura filtra por
 *     `company_id`. El catálogo es global, la asignación no.
 *
 * El impuesto jamás se inventa: sin tarifa oficial interpretable el producto
 * no se toca y nunca se asume 13%.
 */
class ProductCabysService
{
    /** El impuesto vigente lo definió una persona en el formulario. */
    public const TAX_SOURCE_MANUAL = 'manual';

    /** El impuesto vigente proviene de un CABYS confirmado. */
    public const TAX_SOURCE_CABYS = 'cabys_confirmed';

    public function __construct(
        private readonly CabysCatalogResolver $resolver,
        private readonly LocalCabysCatalog $catalog,
    ) {}

    /**
     * Código CONFIRMADO de un producto para una venta. Una asignación
     * `pending` devuelve null: una propuesta sin confirmar no es un código
     * válido.
     */
    public function codeFor(Company $company, Product $product): ?string
    {
        $assignment = ProductCabysAssignment::query()
            ->where('company_id', $company->getKey())
            ->where('product_id', $product->getKey())
            ->where('status', ProductCabysAssignment::STATUS_CONFIRMED)
            ->first();

        return $assignment?->code;
    }

    public function assignmentFor(Company $company, Product $product): ?ProductCabysAssignment
    {
        return ProductCabysAssignment::query()
            ->where('company_id', $company->getKey())
            ->where('product_id', $product->getKey())
            ->first();
    }

    /**
     * Estado de la asignación para la UI de productos.
     *
     * @return array<string, mixed>|null
     */
    public function stateFor(Company $company, Product $product): ?array
    {
        $assignment = $this->assignmentFor($company, $product);

        if ($assignment === null) {
            return null;
        }

        $official = $this->officialRateFor($company, $product);
        $version = $this->resolver->versionById((int) ($assignment->fiscal_catalog_version_id ?? 0));
        $description = null;

        if ($version !== null) {
            $description = $this->resolver->entry($assignment->code, (int) $version->id)?->description;
        }

        return [
            'code' => $assignment->code,
            'status' => $assignment->status,
            'source' => $assignment->source,
            'description' => $description,
            'previous_code' => $assignment->previous_code,
            'confirmed_at' => $assignment->confirmed_at?->toDateTimeString(),
            'official_raw' => $official['official_raw'],
            'official_pct' => $official['official_pct'],
            'catalog_version' => $version?->source_version,
        ];
    }

    /**
     * Propone un código SIN confirmar.
     *
     * @param  list<array{code: string, description?: string|null}>  $candidates
     */
    public function suggest(
        Company $company,
        Product $product,
        string $code,
        array $candidates = [],
        float $confidence = 0.0,
        string $source = ProductCabysAssignment::SOURCE_MANUAL,
    ): ProductCabysAssignment {
        return DB::transaction(function () use ($company, $product, $code, $candidates, $confidence, $source) {
            $current = $this->assignmentFor($company, $product);

            if ($current !== null && $current->isConfirmed() && $current->code === $code) {
                return $current;
            }

            $version = $this->resolver->activeVersion();

            return ProductCabysAssignment::updateOrCreate(
                [
                    'company_id' => $company->getKey(),
                    'product_id' => $product->getKey(),
                ],
                [
                    'code' => $code,
                    'fiscal_catalog_version_id' => $version?->id,
                    'source' => $source,
                    'status' => ProductCabysAssignment::STATUS_PENDING,
                    'confidence' => $confidence > 0 ? $confidence : null,
                    'candidates' => $candidates === [] ? null : $candidates,
                    'previous_code' => $current?->code,
                    'confirmed_by' => null,
                    'confirmed_at' => null,
                ]
            );
        });
    }

    /**
     * Confirma la asignación: una persona válida el código contra el catálogo
     * vigente. Es el único camino que produce un código utilizable en venta.
     *
     * @return array{assignment: ProductCabysAssignment, proposal: array<string, mixed>}
     */
    public function confirmWithProposal(Company $company, Product $product, int $userId): array
    {
        $assignment = $this->assignmentFor($company, $product);

        if ($assignment === null) {
            throw new RuntimeException('El producto no tiene una propuesta CABYS que confirmar');
        }

        $version = $this->resolver->activeVersion();

        if ($version === null) {
            throw new RuntimeException('No hay catálogo CABYS activo: no se puede confirmar un código sin fuente');
        }

        $entry = $this->resolver->entry($assignment->code, (int) $version->id);

        if ($entry === null) {
            /** Un código que no existe en el catálogo vigente NO se confirma. */
            throw new RuntimeException(
                "El código {$assignment->code} no existe en la versión {$version->source_version} del catálogo CABYS"
            );
        }

        $assignment->status = ProductCabysAssignment::STATUS_CONFIRMED;
        $assignment->fiscal_catalog_version_id = $version->id;
        $assignment->confirmed_by = $userId;
        $assignment->confirmed_at = now();
        $assignment->save();

        $this->syncProductColumn($company, $product, $assignment->code);

        /**
         * El CABYS confirmado informa el impuesto del producto. La asignación
         * NUNCA vuelve a `pending` por una diferencia de tarifa: la decisión
         * que vuelve al usuario es la FISCAL y viaja en la propuesta.
         */
        $proposal = $this->proposeTaxRate($company, $product, $entry);

        return ['assignment' => $assignment->fresh(), 'proposal' => $proposal];
    }

    /**
     * Traduce la tarifa oficial del CABYS al impuesto del producto.
     *
     * Reglas, en este orden:
     *
     *  1. Sin tarifa oficial interpretable NO se inventa nada: ni 13 ni 0.
     *  2. Si el impuesto vigente ya coincide con la oficial, solo se registra
     *     la evidencia y su origen pasa a ser el catálogo.
     *  3. Si el producto no tiene tarifa, o la que tiene ya venía de un CABYS
     *     confirmado, la tarifa oficial se aplica.
     *  4. Si el usuario definió la tarifa A MANO y es distinta, no se
     *     sobrescribe: la oficial queda registrada, el producto conserva la
     *     suya y la diferencia queda explícita (discrepancia).
     *
     * @return array{status: string, official_raw: ?string, official_pct: ?float, previous_tax_rate: ?float, product_tax_rate: float, message: string}
     */
    public function proposeTaxRate(Company $company, Product $product, CabysCatalogEntry $entry): array
    {
        $officialRaw = $entry->tax_rate_raw;
        $officialPct = $entry->taxRatePercent();
        $previous = $product->tax_rate;
        $previousPct = $previous === null ? null : (float) $previous;

        if ($officialPct === null) {
            return $this->taxProposal(
                'no_rate',
                $entry,
                $officialPct,
                $previousPct,
                $previousPct ?? 0.0,
                'El CABYS no declara una tarifa interpretable: el impuesto del producto no se modificó.',
            );
        }

        $fromCabys = (string) ($product->tax_rate_source ?? self::TAX_SOURCE_MANUAL) === self::TAX_SOURCE_CABYS;

        if ($previousPct !== null && abs($previousPct - $officialPct) < 0.0001) {
            $this->writeProductTax($company, $product, [
                'tax_rate_source' => self::TAX_SOURCE_CABYS,
                'tax_rate_official_pct' => $officialPct,
                'tax_rate_official_raw' => $officialRaw,
            ]);

            return $this->taxProposal(
                'matching',
                $entry,
                $officialPct,
                $previousPct,
                $officialPct,
                'El impuesto del producto ya coincide con la tarifa oficial del CABYS ('.$officialRaw.').',
            );
        }

        if ($previousPct === null || $fromCabys) {
            $this->writeProductTax($company, $product, [
                'tax_rate' => $officialPct,
                'tax_rate_source' => self::TAX_SOURCE_CABYS,
                'tax_rate_official_pct' => $officialPct,
                'tax_rate_official_raw' => $officialRaw,
            ]);

            return $this->taxProposal(
                'applied',
                $entry,
                $officialPct,
                $previousPct,
                $officialPct,
                'Impuesto del producto actualizado a la tarifa oficial del CABYS ('.$officialRaw.').',
            );
        }

        $this->writeProductTax($company, $product, [
            'tax_rate_official_pct' => $officialPct,
            'tax_rate_official_raw' => $officialRaw,
        ]);

        return $this->taxProposal(
            'discrepancy',
            $entry,
            $officialPct,
            $previousPct,
            $previousPct,
            'El CABYS declara '.$officialRaw.' y el producto tenía '.$previousPct.'% definido a mano: '
                .'el impuesto NO cambió y el código quedó confirmado. Ajuste el impuesto si el código es correcto.',
        );
    }

    /**
     * Marca el impuesto vigente como definido A MANO cuando el usuario cambia
     * el valor en el formulario. Sin esto, una tarifa manual posterior sería
     * sobrescrita por el siguiente CABYS como si viniera del catálogo.
     */
    public function markManualTaxRate(Company $company, Product $product): void
    {
        $this->writeProductTax($company, $product, [
            'tax_rate_source' => self::TAX_SOURCE_MANUAL,
        ]);
    }

    /**
     * Tarifa oficial del código ya ASIGNADO, para mostrarla sin reconfirmar.
     * No escribe nada.
     *
     * @return array{status: string, official_raw: ?string, official_pct: ?float}
     */
    public function officialRateFor(Company $company, Product $product): array
    {
        $assignment = $this->assignmentFor($company, $product);

        if ($assignment === null) {
            return ['status' => 'none', 'official_raw' => null, 'official_pct' => null];
        }

        $version = $this->resolver->versionById((int) ($assignment->fiscal_catalog_version_id ?? 0));

        if ($version === null) {
            return ['status' => 'none', 'official_raw' => null, 'official_pct' => null];
        }

        $entry = $this->resolver->entry($assignment->code, (int) $version->id);

        if ($entry === null) {
            return ['status' => 'none', 'official_raw' => null, 'official_pct' => null];
        }

        $officialPct = $entry->taxRatePercent();

        return [
            'status' => $officialPct === null ? 'no_rate' : 'official',
            'official_raw' => $entry->tax_rate_raw,
            'official_pct' => $officialPct,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeProductTax(Company $company, Product $product, array $attributes): void
    {
        Product::query()
            ->where('company_id', $company->getKey())
            ->whereKey($product->getKey())
            ->update($attributes);
    }

    /**
     * Espeja el código confirmado en la columna heredada `products.cabys_code`,
     * que es la que leen los snapshots de venta, cotización y compra. Así el
     * POS no cambia: sigue leyendo una columna, pero su valor solo puede
     * haber pasado por una confirmación humana.
     */
    private function syncProductColumn(Company $company, ?Product $product, string $code): void
    {
        if ($product === null) {
            return;
        }

        Product::query()
            ->where('company_id', $company->getKey())
            ->whereKey($product->getKey())
            ->update(['cabys_code' => $code]);
    }

    /**
     * Forma única de la respuesta de impuesto: la UI lee los mismos campos en
     * los cuatro casos y `product_tax_rate` es SIEMPRE el valor vigente.
     *
     * @return array{status: string, official_raw: ?string, official_pct: ?float, previous_tax_rate: ?float, product_tax_rate: float, message: string}
     */
    private function taxProposal(
        string $status,
        CabysCatalogEntry $entry,
        ?float $officialPct,
        ?float $previousPct,
        float $productTaxRate,
        string $message,
    ): array {
        return [
            'status' => $status,
            'official_raw' => $entry->tax_rate_raw,
            'official_pct' => $officialPct,
            'previous_tax_rate' => $previousPct,
            'product_tax_rate' => $productTaxRate,
            'message' => $message,
        ];
    }
}
