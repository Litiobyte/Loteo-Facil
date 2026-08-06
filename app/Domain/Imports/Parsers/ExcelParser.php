<?php

namespace App\Domain\Imports\Parsers;

use App\Domain\Imports\Contracts\FileParserInterface;
use App\Domain\Imports\DataTransferObjects\ParsedFile;
use App\Domain\Imports\Support\StringNormalizer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

final class ExcelParser implements FileParserInterface
{
    public function parse(string $path): ParsedFile
    {
        $spreadsheet = IOFactory::load($path);

        try {
            $grid = $spreadsheet->getSheet(0)->toArray(null, true, false, false);

            $headers = [];
            $rows = [];

            foreach ($grid as $index => $cells) {
                if ($this->isBlankLine($cells)) {
                    continue;
                }

                if ($headers === []) {
                    $headers = $this->normalizeHeaders($cells);

                    continue;
                }

                $data = $this->mapRow($cells, $headers);

                if ($this->isEmptyRow($data)) {
                    continue;
                }

                $rows[] = [
                    'row_number' => $index + 1,
                    'data' => $data,
                ];
            }

            return new ParsedFile($headers, $rows);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param  list<mixed>  $line
     * @return list<string>
     */
    private function normalizeHeaders(array $line): array
    {
        return array_values(array_map(
            fn (mixed $cell): string => StringNormalizer::header(is_string($cell) ? $cell : null),
            $line,
        ));
    }

    /**
     * @param  list<mixed>  $line
     * @param  list<string>  $headers
     * @return array<string, string|int|float|null>
     */
    private function mapRow(array $line, array $headers): array
    {
        $data = [];

        foreach ($headers as $index => $header) {
            $value = $line[$index] ?? null;

            if ($value instanceof RichText) {
                $value = $value->getPlainText();
            }

            if ($value === null) {
                $data[$header] = null;
            } elseif (is_string($value)) {
                $value = trim($value);

                $data[$header] = $value === '' ? null : $value;
            } elseif (is_bool($value)) {
                $data[$header] = $value ? 1 : 0;
            } else {
                $data[$header] = $value;
            }
        }

        return $data;
    }

    /**
     * @param  list<mixed>  $line
     */
    private function isBlankLine(array $line): bool
    {
        foreach ($line as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isEmptyRow(array $data): bool
    {
        foreach ($data as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
