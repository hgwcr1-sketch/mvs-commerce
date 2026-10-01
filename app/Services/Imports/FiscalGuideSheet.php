<?php

namespace App\Services\Imports;

use App\Services\Fiscal\FiscalTaxService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class FiscalGuideSheet
{
    public const TITLE = 'GUIA_IMPUESTOS';

    public const WARNING = 'Esta guía es informativa. Los ejemplos no sustituyen la clasificación fiscal correspondiente al bien o servicio. Verifique CABYS y tratamiento tributario antes de facturar.';

    private const TREATMENTS = [
        'zero_rate' => 'IVA a tasa 0%',
        'reduced_rate' => 'Tasa reducida de IVA (0.5%, 1%, 2%, 4%)',
        'transitional' => 'Tasa transitoria, válida solo en notas de crédito/débito (02/03)',
        'taxable' => 'Gravado con IVA calculable',
        'exempt' => 'Exento de IVA',
        'not_subject' => 'No sujeto',
        'additional_tax' => 'Impuesto adicional (selectivo de consumo, combustibles, bebidas, tabaco, cemento, otros)',
    ];

    public function attach(Spreadsheet $spreadsheet, FiscalTaxService $fiscalTaxService): void
    {
        $activeIndex = $spreadsheet->getActiveSheetIndex();
        $guide = $spreadsheet->createSheet();
        $guide->setTitle(self::TITLE);

        $profiles = $fiscalTaxService->activeCatalog();
        $version = $profiles->first()?->catalogVersion;

        $rows = [
            ['Guía de impuestos y perfiles fiscales — MVS Commerce'],
            [self::WARNING],
            [],
            ['Catálogo fiscal activo', $version !== null ? trim($version->source.' · '.$version->source_version) : 'Sin catálogo fiscal activo'],
            [],
            ['Columnas fiscales de la plantilla'],
            ['codigo_impuesto', 'Código de impuesto del documento (ej.: 01 = IVA). Complementa la tasa.'],
            ['codigo_tarifa', 'Código de tarifa del IVA (ej.: 02 = 1%, 03 = 2%, 04 = 4%, 08 = 13%, 10 = exento, 11 = no sujeto). Requiere codigo_impuesto.'],
            ['perfil_fiscal', 'ID entero del perfil fiscal activo; tiene prioridad sobre los códigos y la tasa.'],
            ['impuesto', 'Solo 1, 2, 4 o 13 como tasa inequívoca. 0/8/vacío jamás se infieren.'],
            ['precedencia', 'perfil_fiscal > codigo_impuesto + codigo_tarifa > tasa inequívoca (1/2/4/13) > perfil fiscal del producto.'],
            [],
            ['Códigos y tarifas vigentes'],
            ['Código impuesto', 'Código tarifa', 'Nombre', 'Tratamiento', 'Tarifa', 'Tipos de documento'],
        ];

        foreach ($profiles as $profile) {
            $rows[] = [
                $profile->tax_code,
                $profile->tax_rate_code,
                $profile->name,
                $profile->treatment,
                $profile->rate !== null ? $this->rate((float) $profile->rate) : '',
                implode(', ', (array) $profile->document_types),
            ];
        }

        $rows[] = [];
        $rows[] = ['Significado de los tratamientos'];
        $rows[] = ['Tratamiento', 'Significado'];
        foreach (self::TREATMENTS as $treatment => $meaning) {
            $rows[] = [$treatment, $meaning];
        }

        $rows[] = [];
        $rows[] = ['Ejemplos'];
        $rows[] = ['IVA 13%', 'codigo_impuesto = 01 y codigo_tarifa = 08'];
        $rows[] = ['IVA exento', 'codigo_impuesto = 01 y codigo_tarifa = 10'];
        $rows[] = ['IVA no sujeto', 'codigo_impuesto = 01 y codigo_tarifa = 11'];
        $rows[] = ['Selectivo de consumo', 'codigo_impuesto = 02 (no usar tasa de IVA)'];

        $guide->fromArray($rows, null, 'A1');
        $guide->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $guide->getStyle('A2')->getFont()->setBold(true)->getColor()->setARGB('FF9A3412');
        $guide->getStyle('A2:A2')->getAlignment()->setWrapText(true);
        foreach ([6, 13, 14] as $headerRow) {
            $guide->getStyle('A'.$headerRow.':F'.$headerRow)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $guide->getStyle('A'.$headerRow.':F'.$headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E3A5F');
        }
        $guide->getColumnDimension('A')->setWidth(34);
        $guide->getColumnDimension('B')->setWidth(64);
        foreach (['C', 'D', 'E', 'F'] as $column) {
            $guide->getColumnDimension($column)->setWidth(24);
        }
        $guide->getStyle('A1:F'.count($rows))->getAlignment()->setWrapText(true)->setVertical('top');
        $spreadsheet->setActiveSheetIndex($activeIndex);
    }

    private function rate(float $rate): string
    {
        $formatted = rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
