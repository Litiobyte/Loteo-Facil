<?php

namespace Tests\Feature\Imports;

use App\Domain\Imports\Parsers\ExcelParser;
use App\Domain\Imports\Services\CollectionImportService;
use App\Domain\Imports\Services\PropietarioImportService;
use App\Domain\Imports\Support\ImportTemplateOptions;
use App\Domain\Imports\Support\TemplateGenerator;
use App\Models\Comuna;
use App\Models\Etapa;
use App\Models\Propietario;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class TemplateGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_template_streams_headers_and_example_row(): void
    {
        $response = TemplateGenerator::csv(['codigo', 'estado'], ['L-001', 'disponible'], 'plantilla-lotes');

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        $this->assertStringContainsString('codigo;estado', $content);
        $this->assertStringContainsString('L-001;disponible', $content);
        $this->assertSame('attachment; filename=plantilla-lotes.csv', $response->headers->get('Content-Disposition'));
    }

    public function test_xlsx_template_streams_valid_xlsx_zip(): void
    {
        $response = TemplateGenerator::xlsx(['codigo', 'estado'], ['L-001', 'disponible'], 'plantilla-lotes');

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        $this->assertStringStartsWith('PK', $content);
        $this->assertSame('attachment; filename=plantilla-lotes.xlsx', $response->headers->get('Content-Disposition'));
    }

    public function test_lotes_xlsx_template_includes_estado_and_etapa_dropdowns(): void
    {
        foreach (range(0, 3) as $numero) {
            Etapa::factory()->create(['numero' => $numero, 'nombre' => "Etapa {$numero}"]);
        }

        $spreadsheet = $this->generateSpreadsheet(
            ['codigo', 'estado', 'metros_cuadrados', 'etapa', 'valor_lote', 'notas'],
            ['L-001', 'disponible', '5000', 'Etapa 1', '25000000', 'Lote en esquina'],
            'plantilla-lotes',
            app(ImportTemplateOptions::class)->lotes(),
        );

        $dataSheet = $spreadsheet->getSheet(0);

        $estado = $dataSheet->getDataValidation('B2');
        $this->assertSame(DataValidation::TYPE_LIST, $estado->getType());
        $this->assertSame('"disponible,reservado,vendido"', $estado->getFormula1());
        $this->assertTrue($estado->getAllowBlank());
        $this->assertTrue($estado->getShowDropDown());

        $etapa = $dataSheet->getDataValidation('D2');
        $this->assertSame(DataValidation::TYPE_LIST, $etapa->getType());
        $this->assertSame('Opciones!$A$2:$A$5', $etapa->getFormula1());

        $optionsSheet = $spreadsheet->getSheetByName('Opciones');
        $this->assertNotNull($optionsSheet);
        $this->assertSame('Etapas', $optionsSheet->getCell('A1')->getValue());
        $this->assertSame('Etapa 0', $optionsSheet->getCell('A2')->getValue());
        $this->assertSame('Etapa 3', $optionsSheet->getCell('A5')->getValue());
    }

    public function test_propietarios_xlsx_template_includes_region_and_estado_civil_dropdowns(): void
    {
        $metropolitana = Region::create(['nombre' => 'Metropolitana de Santiago']);
        Comuna::create(['region_id' => $metropolitana->id, 'nombre' => 'Santiago']);
        Comuna::create(['region_id' => $metropolitana->id, 'nombre' => 'Providencia']);
        $valparaiso = Region::create(['nombre' => 'Valparaiso']);
        Comuna::create(['region_id' => $valparaiso->id, 'nombre' => 'Vina del Mar']);

        $spreadsheet = $this->generateSpreadsheet(
            PropietarioImportService::HEADERS,
            ['11111111-1', 'Juan', 'Perez', 'juan.perez@correo.cl', null, null, 'Metropolitana de Santiago', 'Santiago', null, null, 'Casado'],
            'plantilla-propietarios',
            app(ImportTemplateOptions::class)->propietarios(),
        );

        $dataSheet = $spreadsheet->getSheet(0);

        $region = $dataSheet->getDataValidation('G2');
        $this->assertSame(DataValidation::TYPE_LIST, $region->getType());
        $this->assertSame('Opciones!$A$2:$A$3', $region->getFormula1());

        $comuna = $dataSheet->getDataValidation('H2');
        $this->assertSame(DataValidation::TYPE_NONE, $comuna->getType());

        $estadoCivil = $dataSheet->getDataValidation('K2');
        $this->assertSame('"Soltero,Casado,Divorciado,Viudo,Union civil"', $estadoCivil->getFormula1());

        $optionsSheet = $spreadsheet->getSheetByName('Opciones');
        $this->assertSame('Regiones', $optionsSheet->getCell('A1')->getValue());
        $this->assertSame('Metropolitana de Santiago', $optionsSheet->getCell('A2')->getValue());
        $this->assertSame('Valparaiso', $optionsSheet->getCell('A3')->getValue());
        $this->assertSame('Metropolitana de Santiago', $optionsSheet->getCell('B1')->getValue());
        $this->assertSame('Providencia', $optionsSheet->getCell('B2')->getValue());
        $this->assertSame('Santiago', $optionsSheet->getCell('B3')->getValue());
        $this->assertSame('Valparaiso', $optionsSheet->getCell('C1')->getValue());
        $this->assertSame('Vina del Mar', $optionsSheet->getCell('C2')->getValue());
    }

    public function test_recaudaciones_xlsx_template_includes_metodo_and_rut_dropdowns(): void
    {
        Propietario::factory()->create(['rut' => '11111111-1']);

        $spreadsheet = $this->generateSpreadsheet(
            CollectionImportService::HEADERS,
            ['11111111-1', '50000', '2026-08-01', 'transferencia', null, null],
            'plantilla-recaudaciones',
            app(ImportTemplateOptions::class)->recaudaciones(),
        );

        $dataSheet = $spreadsheet->getSheet(0);

        $metodo = $dataSheet->getDataValidation('D2');
        $this->assertSame('"efectivo,transferencia,cheque,otro"', $metodo->getFormula1());

        $rut = $dataSheet->getDataValidation('A2');
        $this->assertSame('Opciones!$A$2:$A$2', $rut->getFormula1());

        $optionsSheet = $spreadsheet->getSheetByName('Opciones');
        $this->assertSame('RUT Propietarios', $optionsSheet->getCell('A1')->getValue());
        $this->assertSame('11111111-1', $optionsSheet->getCell('A2')->getValue());
    }

    public function test_recaudaciones_xlsx_template_skips_rut_dropdown_without_propietarios(): void
    {
        $spreadsheet = $this->generateSpreadsheet(
            CollectionImportService::HEADERS,
            ['11111111-1', '50000', '2026-08-01', 'transferencia', null, null],
            'plantilla-recaudaciones',
            app(ImportTemplateOptions::class)->recaudaciones(),
        );

        $this->assertSame(DataValidation::TYPE_NONE, $spreadsheet->getSheet(0)->getDataValidation('A2')->getType());
    }

    public function test_xlsx_template_with_hidden_options_sheet_is_still_parsed_by_excel_parser(): void
    {
        foreach (range(0, 1) as $numero) {
            Etapa::factory()->create(['numero' => $numero, 'nombre' => "Etapa {$numero}"]);
        }

        $spreadsheet = $this->generateSpreadsheet(
            ['codigo', 'estado', 'metros_cuadrados', 'etapa', 'valor_lote', 'notas'],
            ['L-001', 'disponible', '5000', 'Etapa 1', '25000000', 'Lote en esquina'],
            'plantilla-lotes',
            app(ImportTemplateOptions::class)->lotes(),
        );

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'plantilla-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        try {
            $parsed = app(ExcelParser::class)->parse($path);

            $this->assertSame(['codigo', 'estado', 'metros_cuadrados', 'etapa', 'valor_lote', 'notas'], $parsed->headers);
            $this->assertCount(1, $parsed->rows);
            $this->assertSame('L-001', $parsed->rows[0]['data']['codigo']);
            $this->assertSame('Etapa 1', $parsed->rows[0]['data']['etapa']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string|int|float|null>  $exampleRow
     * @param  array<string, mixed>  $options
     */
    private function generateSpreadsheet(array $headers, array $exampleRow, string $filename, array $options): Spreadsheet
    {
        $response = TemplateGenerator::xlsx($headers, $exampleRow, $filename, $options);

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'plantilla-'.uniqid().'.xlsx';
        file_put_contents($path, $content);

        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }
}
