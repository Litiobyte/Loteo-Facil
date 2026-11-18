<?php

namespace Tests\Feature;

use App\Domain\Maps\Services\MapPersistenceService;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\MapGeometry;
use App\Models\MapImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MapReviewTest extends TestCase
{
    use RefreshDatabase;

    private MapImport $import;

    private MapGeometry $geometry;

    private MapPersistenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MapPersistenceService::class);
        $etapa = Etapa::query()->create(['numero' => 1, 'nombre' => 'Prueba']);
        $this->import = MapImport::factory()->create(['etapa_id' => $etapa->id]);
        $this->service->ensureDefaultLayers($etapa->id);
        $lote = Lote::factory()->create(['etapa_id' => $etapa->id]);
        $this->geometry = MapGeometry::factory()->create([
            'map_import_id' => $this->import->id,
            'map_layer_id' => $this->service->layer($etapa->id, 'lotes')->id,
            'lote_id' => $lote->id,
            'association_status' => 'exact',
            'match_confidence' => 100,
            'is_confirmed' => true,
        ]);
    }

    public function test_empty_select_value_clears_association_without_foreign_key_error(): void
    {
        $this->service->applyReview($this->import, [
            $this->geometry->id => [
                'lote_id' => '',
                'layer_id' => (string) $this->geometry->map_layer_id,
                'confirm' => false,
            ],
        ]);

        $this->geometry->refresh();
        $this->assertNull($this->geometry->lote_id);
        $this->assertSame('none', $this->geometry->association_status);
        $this->assertNull($this->geometry->match_confidence);
        $this->assertFalse($this->geometry->is_confirmed);
    }

    public function test_null_is_allowed_and_valid_string_id_can_be_assigned(): void
    {
        $this->service->applyReview($this->import, [
            $this->geometry->id => ['lote_id' => null, 'confirm' => false],
        ]);
        $this->assertNull($this->geometry->fresh()->lote_id);

        $lote = Lote::factory()->create(['etapa_id' => $this->import->etapa_id]);
        $this->service->applyReview($this->import, [
            $this->geometry->id => ['lote_id' => (string) $lote->id, 'confirm' => true],
        ]);
        $this->assertSame($lote->id, $this->geometry->fresh()->lote_id);
        $this->assertSame('manual', $this->geometry->fresh()->association_method);
    }

    public function test_invalid_lote_is_validation_error_and_does_not_partially_save(): void
    {
        $other = MapGeometry::factory()->create([
            'map_import_id' => $this->import->id,
            'map_layer_id' => $this->geometry->map_layer_id,
        ]);
        $originalLote = $this->geometry->lote_id;

        try {
            $this->service->applyReview($this->import, [
                $this->geometry->id => ['lote_id' => '', 'confirm' => false],
                $other->id => ['lote_id' => 999999, 'confirm' => true],
            ]);
            $this->fail('Se esperaba un error de validación.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decisions.'.$other->id.'.lote_id', $exception->errors());
        }

        $this->assertSame($originalLote, $this->geometry->fresh()->lote_id);
        $this->assertTrue($this->geometry->fresh()->is_confirmed);
    }

    public function test_lote_and_layer_from_another_etapa_are_rejected(): void
    {
        $etapa = Etapa::query()->create(['numero' => 2, 'nombre' => 'Otra']);
        $lote = Lote::factory()->create(['etapa_id' => $etapa->id]);
        $this->service->ensureDefaultLayers($etapa->id);

        try {
            $this->service->applyReview($this->import, [
                $this->geometry->id => [
                    'lote_id' => $lote->id,
                    'layer_id' => $this->service->layer($etapa->id, 'lotes')->id,
                    'confirm' => true,
                ],
            ]);
            $this->fail('Se esperaba un error de validación.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decisions.'.$this->geometry->id.'.lote_id', $exception->errors());
            $this->assertArrayHasKey('decisions.'.$this->geometry->id.'.layer_id', $exception->errors());
        }
    }
}
