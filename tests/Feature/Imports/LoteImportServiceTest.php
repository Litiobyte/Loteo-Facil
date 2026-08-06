<?php

namespace Tests\Feature\Imports;

use App\Domain\Imports\DataTransferObjects\ParsedFile;
use App\Domain\Imports\Services\LoteImportService;
use App\Models\Etapa;
use App\Models\Lote;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoteImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function file(array $rows): ParsedFile
    {
        $data = [];

        foreach ($rows as $rowNumber => $dataRow) {
            $data[] = ['row_number' => $rowNumber, 'data' => $dataRow];
        }

        return new ParsedFile(LoteImportService::HEADERS, $data);
    }

    public function test_creates_new_lotes(): void
    {
        $etapa = Etapa::query()->create(['numero' => 1, 'nombre' => 'Etapa 1']);

        $file = $this->file([
            2 => ['codigo' => 'L-001', 'estado' => 'disponible', 'metros_cuadrados' => '5000', 'etapa' => 'Etapa 1', 'valor_lote' => '25000000', 'notas' => 'Esquina'],
            3 => ['codigo' => 'L-002', 'estado' => 'reservado', 'metros_cuadrados' => '7500', 'etapa' => null, 'valor_lote' => '30000000', 'notas' => null],
        ]);

        $result = app(LoteImportService::class)->import($file);

        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->errorCount());
        $this->assertDatabaseHas('lotes', [
            'codigo' => 'L-001',
            'estado' => 'disponible',
            'metros_cuadrados' => 5000,
            'etapa_id' => $etapa->id,
            'valor_lote' => 25000000,
        ]);
        $this->assertDatabaseHas('lotes', [
            'codigo' => 'L-002',
            'estado' => 'reservado',
            'metros_cuadrados' => 7500,
            'etapa_id' => null,
        ]);
    }

    public function test_updates_existing_lote_by_codigo(): void
    {
        Lote::query()->create([
            'codigo' => 'L-001',
            'estado' => 'disponible',
            'metros_cuadrados' => 1000,
            'valor_lote' => 1000000,
        ]);

        $file = $this->file([
            2 => ['codigo' => 'L-001', 'estado' => 'vendido', 'metros_cuadrados' => '6000', 'etapa' => null, 'valor_lote' => '50000000', 'notas' => 'Actualizado'],
        ]);

        $result = app(LoteImportService::class)->import($file);

        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->created);
        $this->assertDatabaseCount('lotes', 1);
        $this->assertDatabaseHas('lotes', [
            'codigo' => 'L-001',
            'estado' => 'vendido',
            'metros_cuadrados' => 6000,
            'valor_lote' => 50000000,
            'notas' => 'Actualizado',
        ]);
    }

    public function test_resolves_etapa_by_number(): void
    {
        $etapa = Etapa::query()->create(['numero' => 3, 'nombre' => 'Etapa 3']);

        $file = $this->file([
            2 => ['codigo' => 'L-001', 'estado' => 'disponible', 'metros_cuadrados' => '5000', 'etapa' => '3', 'valor_lote' => null, 'notas' => null],
        ]);

        $result = app(LoteImportService::class)->import($file);

        $this->assertSame(1, $result->created);
        $this->assertDatabaseHas('lotes', ['codigo' => 'L-001', 'etapa_id' => $etapa->id]);
    }

    public function test_unknown_etapa_is_reported_as_row_error(): void
    {
        $file = $this->file([
            2 => ['codigo' => 'L-001', 'estado' => 'disponible', 'metros_cuadrados' => '5000', 'etapa' => 'Etapa inexistente', 'valor_lote' => null, 'notas' => null],
        ]);

        $result = app(LoteImportService::class)->import($file);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->errorCount());
        $this->assertSame(2, $result->errors[0]['row']);
        $this->assertStringContainsString('Etapa inexistente', $result->errors[0]['message']);
    }

    public function test_invalid_estado_is_rejected(): void
    {
        $file = $this->file([
            2 => ['codigo' => 'L-001', 'estado' => 'no-existe', 'metros_cuadrados' => '5000', 'etapa' => null, 'valor_lote' => null, 'notas' => null],
        ]);

        $result = app(LoteImportService::class)->import($file);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->errorCount());
        $this->assertSame('estado', $result->errors[0]['field']);
    }

    public function test_negative_metros_cuadrados_is_rejected(): void
    {
        $file = $this->file([
            2 => ['codigo' => 'L-001', 'estado' => 'disponible', 'metros_cuadrados' => '-10', 'etapa' => null, 'valor_lote' => null, 'notas' => null],
        ]);

        $result = app(LoteImportService::class)->import($file);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->errorCount());
        $this->assertSame('metros_cuadrados', $result->errors[0]['field']);
    }

    public function test_defaults_estado_and_valor_lote_when_empty(): void
    {
        $file = $this->file([
            2 => ['codigo' => 'L-001', 'estado' => null, 'metros_cuadrados' => '5000', 'etapa' => null, 'valor_lote' => null, 'notas' => null],
        ]);

        $result = app(LoteImportService::class)->import($file);

        $this->assertSame(1, $result->created);
        $this->assertDatabaseHas('lotes', ['codigo' => 'L-001', 'estado' => 'disponible', 'valor_lote' => 0]);
    }

    public function test_missing_required_header_throws(): void
    {
        $file = new ParsedFile(['codigo'], [['row_number' => 2, 'data' => ['codigo' => 'L-001']]]);

        $this->expectException(DomainException::class);

        app(LoteImportService::class)->import($file);
    }

    public function test_continues_after_row_errors(): void
    {
        $file = $this->file([
            2 => ['codigo' => 'L-001', 'estado' => 'no-existe', 'metros_cuadrados' => '5000', 'etapa' => null, 'valor_lote' => null, 'notas' => null],
            3 => ['codigo' => 'L-002', 'estado' => 'disponible', 'metros_cuadrados' => '5000', 'etapa' => null, 'valor_lote' => null, 'notas' => null],
        ]);

        $result = app(LoteImportService::class)->import($file);

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->errorCount());
        $this->assertDatabaseHas('lotes', ['codigo' => 'L-002']);
    }
}
