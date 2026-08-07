<?php

namespace Tests\Feature\Imports;

use App\Domain\Imports\Parsers\ExcelParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Tests\Feature\Imports\Concerns\CreatesTempImportFiles;
use Tests\TestCase;

class ExcelParserTest extends TestCase
{
    use CreatesTempImportFiles;
    use RefreshDatabase;

    public function test_parses_xlsx_headers_and_rows(): void
    {
        $path = $this->tempXlsx([
            ['Codigo', 'Estado', 'Metros Cuadrados'],
            ['L-001', 'disponible', 5000],
            ['L-002', 'reservado', 7500],
        ]);

        $parsed = app(ExcelParser::class)->parse($path);

        $this->assertSame(['codigo', 'estado', 'metros_cuadrados'], $parsed->headers);
        $this->assertCount(2, $parsed->rows);
        $this->assertSame(2, $parsed->rows[0]['row_number']);
        $this->assertSame('L-001', $parsed->rows[0]['data']['codigo']);
        $this->assertSame(5000, $parsed->rows[0]['data']['metros_cuadrados']);
        $this->assertSame('reservado', $parsed->rows[1]['data']['estado']);
    }

    public function test_skips_empty_rows_and_normalizes_headers(): void
    {
        $path = $this->tempXlsx([
            ['Código', 'Estado'],
            [],
            ['L-001', 'disponible'],
            ['', ''],
        ]);

        $parsed = app(ExcelParser::class)->parse($path);

        $this->assertSame(['codigo', 'estado'], $parsed->headers);
        $this->assertCount(1, $parsed->rows);
        $this->assertSame(3, $parsed->rows[0]['row_number']);
    }

    public function test_leaves_excel_date_serials_as_numbers(): void
    {
        $serial = ExcelDate::dateTimeToExcel(Carbon::parse('2026-07-15'));

        $path = $this->tempXlsx([
            ['rut_propietario', 'fecha'],
            ['11111111-1', $serial],
        ]);

        $parsed = app(ExcelParser::class)->parse($path);

        $this->assertCount(1, $parsed->rows);
        $this->assertIsFloat($parsed->rows[0]['data']['fecha']);
    }
}
