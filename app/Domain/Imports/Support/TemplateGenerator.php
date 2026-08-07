<?php

namespace App\Domain\Imports\Support;

use DomainException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TemplateGenerator
{
    /**
     * @param  list<string>  $headers
     * @param  list<string|int|float|null>  $exampleRow
     */
    public static function csv(array $headers, array $exampleRow, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $exampleRow): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, $headers, ';');
            fputcsv($handle, $exampleRow, ';');
            fclose($handle);
        }, $filename.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string|int|float|null>  $exampleRow
     * @param  array{
     *     region_columns?: list<array{region: string, comunas: list<string>}>,
     *     option_columns?: list<array{label: string, values: list<string>}>,
     *     dropdowns?: list<array{
     *         column: int,
     *         type: 'literal'|'region'|'ref',
     *         values?: list<string>,
     *         option?: string,
     *     }>,
     * }  $options
     */
    public static function xlsx(array $headers, array $exampleRow, string $filename, array $options = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $exampleRow, $options): void {
            $spreadsheet = new Spreadsheet;
            $dataSheet = $spreadsheet->getActiveSheet();
            $dataSheet->fromArray([$headers, $exampleRow], null, 'A1');

            $regionColumns = $options['region_columns'] ?? [];
            $optionColumns = $options['option_columns'] ?? [];

            if ($regionColumns !== [] || $optionColumns !== []) {
                $optionsSheet = $spreadsheet->createSheet();
                $optionsSheet->setTitle('Opciones');
                $optionsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

                $regionCount = count($regionColumns);

                $optionsSheet->setCellValue('A1', 'Regiones');

                foreach ($regionColumns as $index => $region) {
                    $letter = Coordinate::stringFromColumnIndex($index + 2);
                    $optionsSheet->setCellValue('A'.($index + 2), $region['region']);
                    $optionsSheet->setCellValue($letter.'1', $region['region']);
                    $optionsSheet->fromArray(array_map(fn (string $comuna): array => [$comuna], $region['comunas']), null, $letter.'2');
                }

                foreach ($optionColumns as $index => $option) {
                    $letter = Coordinate::stringFromColumnIndex($regionCount + $index + 1);
                    $optionsSheet->setCellValue($letter.'1', $option['label']);
                    $optionsSheet->fromArray(array_map(fn (string $value): array => [$value], $option['values']), null, $letter.'2');
                }

                foreach ($options['dropdowns'] ?? [] as $dropdown) {
                    $dataLetter = Coordinate::stringFromColumnIndex($dropdown['column'] + 1);

                    $validation = new DataValidation;
                    $validation->setType(DataValidation::TYPE_LIST);
                    $validation->setFormula1(self::dropdownFormula($dropdown, $regionColumns, $optionColumns, $regionCount));
                    $validation->setAllowBlank(true);
                    $validation->setShowDropDown(true);
                    $validation->setShowInputMessage(true);
                    $validation->setPromptTitle('Selecciona una opcion');
                    $validation->setPrompt('Elige un valor de la lista desplegable.');
                    $validation->setShowErrorMessage(true);
                    $validation->setErrorStyle(DataValidation::STYLE_STOP);
                    $validation->setErrorTitle('Valor no permitido');
                    $validation->setError('El valor no esta en la lista de opciones.');

                    $dataSheet->setDataValidation($dataLetter.'2:'.$dataLetter.'1000', $validation);
                }

                $spreadsheet->setActiveSheetIndex(0);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array{
     *     column: int,
     *     type: 'literal'|'region'|'ref',
     *     values?: list<string>,
     *     option?: string,
     * }  $dropdown
     * @param  list<array{region: string, comunas: list<string>}>  $regionColumns
     * @param  list<array{label: string, values: list<string>}>  $optionColumns
     */
    private static function dropdownFormula(array $dropdown, array $regionColumns, array $optionColumns, int $regionCount): string
    {
        return match ($dropdown['type']) {
            'literal' => '"'.implode(',', $dropdown['values'] ?? []).'"',
            'region' => 'Opciones!$A$2:$A$'.($regionCount + 1),
            'ref' => self::optionRange($optionColumns, $regionCount, $dropdown['option'] ?? null),
            default => throw new DomainException('Tipo de dropdown no soportado.'),
        };
    }

    /**
     * @param  list<array{label: string, values: list<string>}>  $optionColumns
     */
    private static function optionRange(array $optionColumns, int $regionCount, ?string $label): string
    {
        foreach ($optionColumns as $index => $option) {
            if ($option['label'] === $label) {
                $letter = Coordinate::stringFromColumnIndex($regionCount + $index + 1);

                return 'Opciones!$'.$letter.'$2:$'.$letter.'$'.(count($option['values']) + 1);
            }
        }

        throw new DomainException('La columna de opciones "'.$label.'" no existe.');
    }
}
