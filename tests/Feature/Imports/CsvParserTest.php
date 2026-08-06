<?php

namespace Tests\Feature\Imports;

use App\Domain\Imports\Parsers\CsvParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Imports\Concerns\CreatesTempImportFiles;
use Tests\TestCase;

class CsvParserTest extends TestCase
{
    use CreatesTempImportFiles;
    use RefreshDatabase;

    public function test_parses_semicolon_csv_and_normalizes_headers(): void
    {
        $path = $this->tempCsv("Codigo;Estado;Metros Cuadrados;Notas\nL-001;disponible;5000;Sector A\n");

        $parsed = app(CsvParser::class)->parse($path);

        $this->assertSame(['codigo', 'estado', 'metros_cuadrados', 'notas'], $parsed->headers);
        $this->assertCount(1, $parsed->rows);
        $this->assertSame(2, $parsed->rows[0]['row_number']);
        $this->assertSame('L-001', $parsed->rows[0]['data']['codigo']);
        $this->assertSame('disponible', $parsed->rows[0]['data']['estado']);
        $this->assertSame('5000', $parsed->rows[0]['data']['metros_cuadrados']);
    }

    public function test_detects_comma_delimiter(): void
    {
        $path = $this->tempCsv("codigo,estado\nL-001,disponible\n");

        $parsed = app(CsvParser::class)->parse($path);

        $this->assertCount(1, $parsed->rows);
        $this->assertSame('L-001', $parsed->rows[0]['data']['codigo']);
        $this->assertSame('disponible', $parsed->rows[0]['data']['estado']);
    }

    public function test_strips_utf8_bom(): void
    {
        $path = $this->tempCsv("\xEF\xBB\xBFcodigo;estado\nL-001;disponible\n");

        $parsed = app(CsvParser::class)->parse($path);

        $this->assertSame(['codigo', 'estado'], $parsed->headers);
        $this->assertCount(1, $parsed->rows);
    }

    public function test_handles_quoted_fields_with_embedded_delimiters(): void
    {
        $path = $this->tempCsv("codigo;notas\nL-001;\"Nota con ; punto y , coma\"\n");

        $parsed = app(CsvParser::class)->parse($path);

        $this->assertCount(1, $parsed->rows);
        $this->assertSame('Nota con ; punto y , coma', $parsed->rows[0]['data']['notas']);
    }

    public function test_skips_blank_lines_but_keeps_original_row_numbers(): void
    {
        $path = $this->tempCsv("codigo;estado\n\nL-001;disponible\n\n\nL-002;reservado\n");

        $parsed = app(CsvParser::class)->parse($path);

        $this->assertCount(2, $parsed->rows);
        $this->assertSame(3, $parsed->rows[0]['row_number']);
        $this->assertSame(6, $parsed->rows[1]['row_number']);
    }

    public function test_normalizes_accented_headers(): void
    {
        $path = $this->tempCsv("Código;Estado\nL-001;disponible\n");

        $parsed = app(CsvParser::class)->parse($path);

        $this->assertSame(['codigo', 'estado'], $parsed->headers);
    }

    public function test_converts_empty_cells_to_null(): void
    {
        $path = $this->tempCsv("codigo;estado;notas\nL-001;disponible;\n");

        $parsed = app(CsvParser::class)->parse($path);

        $this->assertCount(1, $parsed->rows);
        $this->assertNull($parsed->rows[0]['data']['notas']);
    }

    public function test_ignores_fully_empty_rows(): void
    {
        $path = $this->tempCsv("codigo;estado\nL-001;\n");

        $parsed = app(CsvParser::class)->parse($path);

        $this->assertCount(1, $parsed->rows);
        $this->assertNull($parsed->rows[0]['data']['estado']);
    }
}
