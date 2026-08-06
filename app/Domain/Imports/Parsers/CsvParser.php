<?php

namespace App\Domain\Imports\Parsers;

use App\Domain\Imports\Contracts\FileParserInterface;
use App\Domain\Imports\DataTransferObjects\ParsedFile;
use App\Domain\Imports\Support\StringNormalizer;

final class CsvParser implements FileParserInterface
{
    public function parse(string $path): ParsedFile
    {
        $content = (string) file_get_contents($path);

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el archivo CSV.');
        }

        fwrite($handle, $content);
        rewind($handle);

        $delimiter = $this->detectDelimiter($content);

        $headers = [];
        $rows = [];
        $lineNumber = 0;

        while (($line = fgetcsv($handle, null, $delimiter)) !== false) {
            $lineNumber++;

            if ($this->isBlankLine($line)) {
                continue;
            }

            if ($headers === []) {
                $headers = $this->normalizeHeaders($line);

                continue;
            }

            $data = $this->mapRow($line, $headers);

            if ($this->isEmptyRow($data)) {
                continue;
            }

            $rows[] = [
                'row_number' => $lineNumber,
                'data' => $data,
            ];
        }

        fclose($handle);

        return new ParsedFile($headers, $rows);
    }

    /**
     * @param  list<string>  $line
     */
    private function normalizeHeaders(array $line): array
    {
        return array_values(array_map(
            fn (?string $cell): string => StringNormalizer::header($cell),
            $line,
        ));
    }

    /**
     * @param  list<string>  $line
     * @param  list<string>  $headers
     * @return array<string, string|null>
     */
    private function mapRow(array $line, array $headers): array
    {
        $data = [];

        foreach ($headers as $index => $header) {
            $value = trim((string) ($line[$index] ?? ''));

            $data[$header] = $value === '' ? null : $value;
        }

        return $data;
    }

    /**
     * @param  list<string>  $line
     */
    private function isBlankLine(array $line): bool
    {
        if (count($line) === 1 && ($line[0] === null || trim((string) $line[0]) === '')) {
            return true;
        }

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

    private function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\r\n");

        if (! is_string($firstLine)) {
            return ';';
        }

        $semicolons = substr_count($firstLine, ';');
        $commas = substr_count($firstLine, ',');

        return $semicolons >= $commas ? ';' : ',';
    }
}
