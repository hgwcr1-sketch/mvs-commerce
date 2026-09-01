<?php

namespace App\Services\Imports;

use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\Unit;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MigrationTemplateService
{
    private const LAST_INPUT_ROW = 251;

    public function make(string $type, int $companyId): Spreadsheet
    {
        $definition = $this->definition($type, $companyId);
        $spreadsheet = new Spreadsheet;
        $data = $spreadsheet->getActiveSheet();
        $data->setTitle($definition['title']);
        $data->fromArray([$definition['headers']], null, 'A1');
        $data->freezePane('A2');
        $data->setAutoFilter('A1:'.$data->getHighestColumn().'1');
        $data->getStyle('A1:'.$data->getHighestColumn().'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $data->getStyle('A1:'.$data->getHighestColumn().'1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E3A5F');

        foreach ($definition['fields'] as $index => $field) {
            $column = $this->column($index + 1);
            $data->getColumnDimension($column)->setWidth(min(32, max(14, strlen($field['name']) + 3)));
            $format = $field['format_type'] === 'text'
                ? NumberFormat::FORMAT_TEXT
                : ($field['number_format'] ?? NumberFormat::FORMAT_GENERAL);
            $data->getStyle("{$column}2:{$column}".self::LAST_INPUT_ROW)->getNumberFormat()->setFormatCode($format);
        }

        $instructions = $spreadsheet->createSheet();
        $instructions->setTitle('INSTRUCCIONES');
        $instructions->fromArray([['Campo', 'Obligatorio / opcional', 'Formato', 'Valores permitidos', 'Ejemplo']], null, 'A1');
        foreach ($definition['fields'] as $row => $field) {
            $instructions->fromArray([[
                $field['name'], $field['required'] ? 'Obligatorio' : 'Opcional', $field['format'],
                $field['allowed'] ?? 'Texto libre', $field['example'] ?? '',
            ]], null, 'A'.($row + 2));
        }
        $instructions->freezePane('A2');
        $instructions->setAutoFilter('A1:E'.(count($definition['fields']) + 1));
        $instructions->getStyle('A1:E1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $instructions->getStyle('A1:E1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E3A5F');
        foreach (['A' => 32, 'B' => 23, 'C' => 34, 'D' => 70, 'E' => 32] as $column => $width) {
            $instructions->getColumnDimension($column)->setWidth($width);
        }
        $instructions->getStyle('A1:E'.(count($definition['fields']) + 1))->getAlignment()->setWrapText(true)->setVertical('top');

        $catalogs = $spreadsheet->createSheet();
        $catalogs->setTitle('CATALOGOS');
        $catalogs->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $catalogColumn = 1;
        foreach ($definition['lists'] as $fieldName => $values) {
            $values = array_values(array_unique(array_filter($values, fn ($value) => $value !== null && $value !== '')));
            if ($values === []) {
                continue;
            }
            $catalogLetter = $this->column($catalogColumn++);
            $catalogs->setCellValue("{$catalogLetter}1", $fieldName);
            $catalogs->fromArray(array_map(fn ($value) => [$value], $values), null, "{$catalogLetter}2");
            $fieldIndex = array_search($fieldName, array_column($definition['fields'], 'name'), true);
            if ($fieldIndex === false) {
                continue;
            }
            $dataColumn = $this->column($fieldIndex + 1);
            $validation = new DataValidation;
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            $validation->setAllowBlank(! $definition['fields'][$fieldIndex]['required']);
            $validation->setShowErrorMessage(true);
            $validation->setErrorTitle('Valor no permitido');
            $validation->setError('Seleccione un valor de la lista.');
            $validation->setShowDropDown(true);
            $validation->setFormula1("'CATALOGOS'!\${$catalogLetter}\$2:\${$catalogLetter}\$".(count($values) + 1));
            $validation->setSqref("{$dataColumn}2:{$dataColumn}".self::LAST_INPUT_ROW);
            $data->setDataValidation("{$dataColumn}2", $validation);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function definition(string $type, int $companyId): array
    {
        $yesNo = ['Sí', 'No'];
        $definitions = [
            'customers' => ['title' => 'Clientes', 'headers' => CustomerImportService::HEADERS, 'fields' => $this->fields(CustomerImportService::HEADERS, [
                'tipo_cliente' => ['text', 'Texto', 'individual, company', 'individual'],
                'tipo_identificacion' => ['text', 'Texto (conservar ceros)', '01, 02, 03, 04, 05; también se aceptan 1–5 heredados', '01'],
                'identificacion' => ['text', 'Texto (no usar notación científica)', null, '001234567'],
                'nombre' => ['text', 'Texto', null, 'María Rodríguez'], 'nombre_comercial' => ['text', 'Texto', null, 'Comercial Rodríguez'],
                'codigo_pais' => ['text', 'Texto con signo +', null, '+506'], 'telefono' => ['text', 'Texto de 4 a 15 dígitos', null, '22220000'],
                'movil' => ['text', 'Texto de 4 a 15 dígitos', null, '088881111'], 'correo' => ['text', 'Correo electrónico', null, 'cliente@ejemplo.com'],
                'direccion' => ['text', 'Texto', null, 'San José, Costa Rica'], 'limite_credito' => ['number', 'Monto, máximo 2 decimales', null, '150000.00', '#,##0.00'],
                'dias_credito' => ['number', 'Entero no negativo', null, '30', '0'], 'nivel_precio' => ['text', 'Texto', 'normal, wholesale, a, b, c', 'normal'],
                'fecha_nacimiento' => ['text', 'Fecha AAAA-MM-DD', null, '1990-05-10'], 'activo' => ['text', 'Texto', 'Sí, No (compatibles: 1/0, true/false, activo/inactivo)', 'Sí'],
            ]), 'lists' => ['tipo_cliente' => ['individual', 'company'], 'tipo_identificacion' => ['01', '02', '03', '04', '05'], 'nivel_precio' => ['normal', 'wholesale', 'a', 'b', 'c'], 'activo' => $yesNo]],
            'products' => ['title' => 'Productos', 'headers' => ProductImportService::HEADERS, 'fields' => $this->fields(ProductImportService::HEADERS, [
                'codigo_interno' => ['text', 'Texto (conservar ceros)', null, '000123'], 'nombre' => ['text', 'Texto', null, 'Producto ejemplo'],
                'categoria' => ['text', 'Texto; catálogo activo de la empresa', 'Seleccione una categoría activa', 'General'], 'marca' => ['text', 'Texto; catálogo activo de la empresa', 'Seleccione una marca activa o deje vacío', 'Marca ejemplo'],
                'unidad' => ['text', 'Texto; nombre o abreviatura activa', 'Seleccione una unidad activa', 'Unidad'], 'tipo_producto' => ['text', 'Texto', 'product, service, combo', 'product'],
                'codigo_barras_principal' => ['text', 'Texto (no usar notación científica)', null, '0012345678905'], 'codigos_barras_adicionales' => ['text', 'Texto; separar con coma, punto y coma o |', null, '0011111111111;0022222222222'],
                'cabys' => ['text', 'Texto (conservar ceros)', null, '0123456789012'], 'descripcion_corta' => ['text', 'Texto', null, 'Descripción breve'], 'descripcion' => ['text', 'Texto', null, 'Descripción completa'],
                'costo' => ['number', 'Monto no negativo, máximo 4 decimales', null, '1000.0000', '#,##0.0000'], 'precio_venta' => ['number', 'Monto no negativo, máximo 2 decimales; acepta hasta 4 si los adicionales son ceros', null, '1500.0000', '#,##0.0000'],
                'precio_mayorista' => ['number', 'Monto no negativo, máximo 2 decimales; acepta hasta 4 si los adicionales son ceros', null, '1400.0000', '#,##0.0000'], 'precio_especial' => ['number', 'Monto no negativo, máximo 2 decimales; acepta hasta 4 si los adicionales son ceros', null, '1450.0000', '#,##0.0000'],
                'precio_a' => ['number', 'Monto no negativo, máximo 2 decimales; acepta hasta 4 si los adicionales son ceros', null, '1480.0000', '#,##0.0000'], 'precio_b' => ['number', 'Monto no negativo, máximo 2 decimales; acepta hasta 4 si los adicionales son ceros', null, '1470.0000', '#,##0.0000'], 'precio_c' => ['number', 'Monto no negativo, máximo 2 decimales; acepta hasta 4 si los adicionales son ceros', null, '1460.0000', '#,##0.0000'],
                'impuesto' => ['number', 'Porcentaje 0–100, máximo 2 decimales; no es catálogo cerrado', 'Cualquier tasa entre 0 y 100 admitida por el importador', '13.00', '0.00'],
                'controla_inventario' => ['text', 'Texto', 'Sí, No', 'Sí'], 'permite_stock_negativo' => ['text', 'Texto', 'Sí, No', 'No'], 'imprime_etiqueta' => ['text', 'Texto', 'Sí, No', 'No'], 'activo' => ['text', 'Texto', 'Sí, No', 'Sí'],
            ]), 'lists' => ['categoria' => ProductCategory::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->pluck('name')->all(), 'marca' => Brand::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->pluck('name')->all(), 'unidad' => Unit::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->pluck('name')->all(), 'tipo_producto' => ['product', 'service', 'combo'], 'controla_inventario' => $yesNo, 'permite_stock_negativo' => $yesNo, 'imprime_etiqueta' => $yesNo, 'activo' => $yesNo]],
            'sales' => $this->genericDefinition('Ventas históricas', HistoricalSaleImportService::HEADERS, ['numero_documento', 'codigo_sucursal', 'identificacion_cliente', 'codigo_producto', 'codigo_barras'], ['tipo_documento' => ['electronic_invoice', 'electronic_ticket'], 'condicion_venta' => ['cash', 'credit']], ['fecha' => 'Fecha y hora (AAAA-MM-DD HH:MM:SS)']),
            'inventory' => $this->genericDefinition('Inventario P36', InventoryMigrationImportService::HEADERS, ['origen_migracion', 'clave_fila', 'codigo_sucursal', 'codigo_producto', 'codigo_barras', 'referencia'], ['tipo_registro' => ['saldo_inicial', 'movimiento_historico'], 'tipo_movimiento' => ['entrada', 'salida']], ['fecha' => 'Fecha y hora (AAAA-MM-DD HH:MM:SS)']),
            'loyalty' => $this->genericDefinition('Fidelidad P37', LoyaltyMigrationImportService::HEADERS, ['origen_migracion', 'identificacion_cliente', 'codigo_sucursal', 'codigo_producto', 'codigo_barras'], ['tipo_saldo' => ['saldo_inicial', 'movimiento_historico'], 'tipo_movimiento' => ['purchase', 'new_customer', 'birthday', 'return_customer', 'promotion', 'redemption', 'reward', 'return', 'void', 'expiration', 'adjustment'], 'activo' => $yesNo], ['fecha' => 'Fecha y hora (AAAA-MM-DD HH:MM:SS)', 'fecha_ultima_compra' => 'Fecha y hora', 'fecha_ultima_actividad' => 'Fecha y hora']),
        ];

        return $definitions[$type];
    }

    private function genericDefinition(string $title, array $headers, array $textFields, array $lists, array $formats): array
    {
        $numeric = ['subtotal_documento', 'descuento_documento', 'impuesto_documento', 'total_documento', 'numero_linea', 'cantidad', 'precio_unitario', 'descuento_linea', 'tasa_impuesto', 'costo_unitario', 'stock_anterior', 'stock_nuevo', 'stock_minimo', 'stock_maximo', 'puntos', 'saldo_actual', 'total_ganado', 'total_canjeado', 'total_vencido'];
        $overrides = [];
        foreach ($headers as $header) {
            $name = rtrim($header, '*');
            if (in_array($name, $textFields, true)) {
                $overrides[$name] = ['text', 'Texto (conservar ceros y caracteres)', null, 'COD-001'];
            } elseif (isset($formats[$name])) {
                $overrides[$name] = ['text', $formats[$name], null, '2024-01-31 14:30:00'];
            } elseif (in_array($name, $numeric, true)) {
                $overrides[$name] = ['number', 'Número no negativo, máximo 4 decimales', null, '10.0000', '#,##0.0000'];
            } else {
                $overrides[$name] = ['text', 'Texto', null, 'Ejemplo'];
            }
            if (isset($lists[$name])) {
                $overrides[$name][2] = implode(', ', $lists[$name]);
                $overrides[$name][3] = $lists[$name][0];
            }
        }

        return ['title' => $title, 'headers' => $headers, 'fields' => $this->fields($headers, $overrides), 'lists' => $lists];
    }

    private function fields(array $headers, array $overrides): array
    {
        return array_map(function (string $header) use ($overrides): array {
            $name = rtrim($header, '*');
            [$formatType, $format, $allowed, $example, $numberFormat] = array_pad($overrides[$name] ?? ['text', 'Texto', null, 'Ejemplo'], 5, null);

            return compact('name') + ['required' => str_ends_with($header, '*'), 'format_type' => $formatType, 'format' => $format, 'allowed' => $allowed, 'example' => $example, 'number_format' => $numberFormat];
        }, $headers);
    }

    private function column(int $index): string
    {
        $column = '';
        while ($index > 0) {
            $index--;
            $column = chr(65 + ($index % 26)).$column;
            $index = intdiv($index, 26);
        }

        return $column;
    }
}
