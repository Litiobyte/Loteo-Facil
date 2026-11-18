<?php

namespace App\Domain\Imports\Services;

use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

class TabularFileReader
{
    public const MAX_ROWS = 2000;

    /** @return array{headers: array<int, string>, rows: array<int, array<int, string>>} */
    public function read(string $path, string $extension): array
    {
        if (! in_array($extension, ['csv', 'xlsx'], true) || filesize($path) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Use CSV o XLSX de hasta 5 MB.']);
        }

        if ($extension === 'xlsx') {
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) {
                throw ValidationException::withMessages(['file' => 'XLSX inválido.']);
            }
            try {
                $size = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $size += $zip->statIndex($i)['size'];
                }
                if ($size > 32 * 1024 * 1024 || $zip->numFiles > 1000) {
                    throw ValidationException::withMessages(['file' => 'El XLSX descomprimido supera el límite permitido.']);
                }
            } finally {
                $zip->close();
            }
            $options = new Options;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $reader = new Reader($options);
        } else {
            $options = new \OpenSpout\Reader\CSV\Options;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $handle = fopen($path, 'r');
            $line = fgets($handle) ?: '';
            fclose($handle);
            $options->FIELD_DELIMITER = count(str_getcsv($line, ';', '"', '')) > count(str_getcsv($line, ',', '"', '')) ? ';' : ',';
            $reader = new \OpenSpout\Reader\CSV\Reader($options);
        }

        $headers = [];
        $rows = [];
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $number => $row) {
                    if ($number > self::MAX_ROWS + 1 || $row->getNumCells() > 64) {
                        throw ValidationException::withMessages(['file' => 'Máximo 2000 filas y 64 columnas. Divida archivos mayores.']);
                    }
                    $values = array_map(function (mixed $value): string {
                        if (! is_scalar($value) && $value !== null) {
                            throw ValidationException::withMessages(['file' => 'Use texto o números, no fechas ni objetos.']);
                        }
                        $text = trim((string) $value);
                        if (! mb_check_encoding($text, 'UTF-8') || strlen($text) > 10000) {
                            throw ValidationException::withMessages(['file' => 'Use UTF-8 y celdas de hasta 10000 bytes.']);
                        }

                        return $text;
                    }, $row->toArray());
                    if ($number === 1) {
                        $headers = array_map(fn (string $v): string => ltrim($v, "\xEF\xBB\xBF"), $values);
                        if (in_array('', $headers, true) || count(array_unique($headers)) !== count($headers)) {
                            throw ValidationException::withMessages(['file' => 'Los encabezados deben ser únicos y no vacíos.']);
                        }
                    } elseif (array_filter($values, fn (string $v): bool => $v !== '') !== []) {
                        $rows[$number] = $values;
                    }
                }
                break;
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo. Compruebe el formato.']);
        } finally {
            $reader->close();
        }
        if ($headers === [] || $rows === []) {
            throw ValidationException::withMessages(['file' => 'El archivo debe incluir encabezados y datos.']);
        }

        return compact('headers', 'rows');
    }
}
