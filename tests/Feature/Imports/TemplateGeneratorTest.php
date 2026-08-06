<?php

namespace Tests\Feature\Imports;

use App\Domain\Imports\Support\TemplateGenerator;
use Tests\TestCase;

class TemplateGeneratorTest extends TestCase
{
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
}
