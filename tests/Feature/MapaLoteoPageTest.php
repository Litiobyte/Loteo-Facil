<?php

namespace Tests\Feature;

use App\Domain\Maps\Services\MapPersistenceService;
use App\Filament\Admin\Pages\MapaLoteo;
use App\Models\Etapa;
use App\Models\MapGeometry;
use App\Models\MapImport;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MapaLoteoPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_map_page_renders_with_its_blade_view(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Etapa::query()->create(['numero' => 1, 'nombre' => 'Prueba']);

        $this->actingAs($admin)
            ->get('/admin/mapa-loteo')
            ->assertOk()
            ->assertSee('Importar mapa')
            ->assertSee('Historial de importaciones')
            ->assertSee('aria-label="Gestión del mapa"', false)
            ->assertSee('aria-controls="map-panel-review"', false)
            ->assertSee('aria-controls="map-panel-layers"', false)
            ->assertSee('aria-controls="map-panel-history"', false)
            ->assertSee('Panel del mapa con desplazamiento independiente')
            ->assertSee('Importa un archivo o selecciona una versión del historial');
    }

    public function test_review_panel_renders_associations_warnings_and_filter_empty_state(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $etapa = Etapa::query()->create(['numero' => 1, 'nombre' => 'Prueba']);
        $service = app(MapPersistenceService::class);
        $service->ensureDefaultLayers($etapa->id);
        $import = MapImport::factory()->create(['etapa_id' => $etapa->id]);
        $geometry = MapGeometry::factory()->create([
            'map_import_id' => $import->id,
            'map_layer_id' => $service->layer($etapa->id, 'lotes')->id,
            'properties' => ['name' => 'Elemento de prueba', 'warnings' => ['Revisar identificación del lote']],
            'association_status' => 'ambiguous',
        ]);

        Livewire::test(MapaLoteo::class)
            ->set('importId', $import->id)
            ->assertSee('Elemento de prueba')
            ->assertSee('Revisar identificación del lote')
            ->assertSee('Ambigua')
            ->assertSee('wire:model="decisions.'.$geometry->id.'.layer_id"', false)
            ->assertSee('wire:model="decisions.'.$geometry->id.'.lote_id"', false)
            ->assertSee('wire:model="decisions.'.$geometry->id.'.confirm"', false)
            ->assertSee('Guardar revisión')
            ->assertSee('Publicar mapa')
            ->call('highlightFeature', $geometry->id)
            ->assertDispatched('map-focus', id: $geometry->id)
            ->assertSee('map-row-selected')
            ->call('selectFeature', $geometry->id)
            ->assertSee('Elemento seleccionado')
            ->set('statusFilter', 'exact')
            ->assertSee('No hay elementos para este filtro.');
    }

    public function test_review_validation_errors_remain_visible_outside_the_tabs(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $etapa = Etapa::query()->create(['numero' => 1, 'nombre' => 'Prueba']);
        $service = app(MapPersistenceService::class);
        $service->ensureDefaultLayers($etapa->id);
        $import = MapImport::factory()->create(['etapa_id' => $etapa->id]);
        $geometry = MapGeometry::factory()->create([
            'map_import_id' => $import->id,
            'map_layer_id' => $service->layer($etapa->id, 'lotes')->id,
        ]);

        Livewire::test(MapaLoteo::class)
            ->set('importId', $import->id)
            ->set('decisions.'.$geometry->id.'.lote_id', 999999)
            ->call('applyReview')
            ->assertHasErrors(['decisions.'.$geometry->id.'.lote_id'])
            ->assertSee('Revisa las asociaciones y capas seleccionadas antes de guardar.')
            ->assertSee('role="alert"', false);
    }
}
