<?php

namespace App\Domain\Imports\Support;

use App\Domain\Imports\DataTransferObjects\ParsedFile;
use App\Domain\Imports\Parsers\CsvParser;
use App\Domain\Imports\Parsers\ExcelParser;
use DomainException;

final class ImportFileReader
{
    public function __construct(
        private readonly CsvParser $csvParser,
        private readonly ExcelParser $excelParser,
    ) {}

    public function read(string $path): ParsedFile
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => $this->csvParser->parse($path),
            'xlsx', 'xls' => $this->excelParser->parse($path),
            default => throw new DomainException('Formato de archivo no soportado. Usa archivos CSV, XLSX o XLS.'),
        };
    }
}
