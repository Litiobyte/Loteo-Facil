<?php

namespace Tests\Feature;

use App\Domain\Maps\Services\MapPersistenceService;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\MapGeometry;
use App\Models\MapImport;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LoteMapFeatureTest extends TestCase
{
    use RefreshDatabase;

    private MapPersistenceService $service;

    private Etapa $etapa;

    private Lote $lote;

    private MapImport $import;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MapPersistenceService::class);
        $this->etapa = Etapa::query()->create(['numero' => 1, 'nombre' => 'Prueba']);
        $this->lote = Lote::factory()->create(['codigo' => '6-2', 'etapa_id' => $this->etapa->id]);
        $this->import = MapImport::factory()->create(['etapa_id' => $this->etapa->id]);

        $this->service->ensureDefaultLayers($this->etapa->id);
        $this->service->publish($this->import);
    }

    private function addGeometry(array $attributes = []): MapGeometry
    {
        $layerId = $this->service->layer($this->etapa->id, $attributes['layer_slug'] ?? 'lotes')->id;

        unset($attributes['layer_slug']);

        return MapGeometry::factory()->create($attributes + [
            'map_import_id' => $this->import->id,
            'map_layer_id' => $layerId,
            'lote_id' => $this->lote->id,
            'is_confirmed' => true,
            'normalized_identifier' => '6-2',
        ]);
    }

    public function test_returns_feature_for_confirmed_association(): void
    {
        $this->addGeometry();

        $result = $this->service->loteFeature($this->lote->id, true);

        $this->assertSame('ok', $result['status']);
        $this->assertSame($this->etapa->id, $result['etapa_id']);
        $this->assertSame($this->lote->id, $result['feature']['properties']['lote_id']);
    }

    public function test_owner_view_requires_confirmation_and_public_layer(): void
    {
        $this->addGeometry(['is_confirmed' => false]);
        $this->assertSame('sin_asociacion', $this->service->loteFeature($this->lote->id, false)['status']);
        $this->assertSame('ok', $this->service->loteFeature($this->lote->id, true)['status']);

        $infra = $this->service->layer($this->etapa->id, 'infraestructura');
        MapGeometry::factory()->create([
            'map_import_id' => $this->import->id,
            'map_layer_id' => $infra->id,
            'lote_id' => $this->lote->id,
            'is_confirmed' => true,
        ]);

        $this->assertSame('sin_asociacion', $this->service->loteFeature($this->lote->id, false)['status']);
    }

    public function test_reports_duplicated_geometries(): void
    {
        $this->addGeometry();
        $this->addGeometry();

        $this->assertSame('duplicado', $this->service->loteFeature($this->lote->id, true)['status']);
    }

    public function test_reports_missing_map_and_unassociated_lotes(): void
    {
        $orphanLote = Lote::factory()->create(['codigo' => '9-9', 'etapa_id' => $this->etapa->id]);
        $this->assertSame('sin_asociacion', $this->service->loteFeature($orphanLote->id, true)['status']);

        $otherEtapa = Etapa::query()->create(['numero' => 2, 'nombre' => 'Sin mapa']);
        $loteSinMapa = Lote::factory()->create(['codigo' => '1-1', 'etapa_id' => $otherEtapa->id]);
        $this->assertSame('sin_mapa', $this->service->loteFeature($loteSinMapa->id, true)['status']);
    }

    public function test_owner_geojson_marks_only_active_ownerships(): void
    {
        $user = User::factory()->create();
        $role = Role::findOrCreate('propietario', 'web');
        $user->assignRole($role);
        $propietario = Propietario::factory()->create(['user_id' => $user->id]);

        $geometry = $this->addGeometry();
        $this->lote->propietarios()->attach($propietario->id, ['status' => 'active', 'assigned_at' => now()]);

        $this->actingAs($user);
        $collection = $this->service->publishedGeoJson($this->etapa->id, false);

        $feature = collect($collection['features'])->first(fn ($f) => $f['id'] === $geometry->id);
        $this->assertTrue($feature['properties']['is_own']);

        // Asignacion inactiva no es propia
        $this->lote->propietarios()->updateExistingPivot($propietario->id, ['status' => 'ended']);
        $collection = $this->service->publishedGeoJson($this->etapa->id, false);
        $feature = collect($collection['features'])->first(fn ($f) => $f['id'] === $geometry->id);
        $this->assertFalse($feature['properties']['is_own']);
    }
}
