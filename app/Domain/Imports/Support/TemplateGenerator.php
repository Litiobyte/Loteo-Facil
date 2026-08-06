<?php

namespace App\Domain\Imports\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
     */
    public static function xlsx(array $headers, array $exampleRow, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $exampleRow): void {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->fromArray([$headers, $exampleRow], null, 'A1');

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
