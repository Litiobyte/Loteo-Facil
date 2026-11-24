<?php

namespace Tests\Feature\Owner;

use App\Domain\Maps\Services\MapPersistenceService;
use App\Filament\Owner\Resources\Lotes\LoteResource;
use App\Filament\Owner\Resources\Lotes\Pages\ListMisLotes;
use App\Filament\Owner\Resources\Lotes\Pages\ViewLote;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\MapGeometry;
use App\Models\MapImport;
use App\Models\Propietario;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MisLotesResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('propietario', 'web');
        Filament::setCurrentPanel(Filament::getPanel('owner'));
    }

    public function test_summary_includes_all_unique_active_lots_not_search_or_page_results(): void
    {
        [$user, $owner] = $this->createOwner();
        [, $other] = $this->createOwner();
        $lots = Lote::factory()->count(12)->create(['metros_cuadrados' => 5000]);
        foreach ($lots as $lot) {
            $this->assign($lot, $owner);
        }
        $this->assign($lots->first(), $other, 'unassigned');
        $inactive = Lote::factory()->create(['metros_cuadrados' => 90000]);
        $this->assign($inactive, $owner, 'unassigned');
        $foreign = Lote::factory()->create(['metros_cuadrados' => 80000]);
        $this->assign($foreign, $other);
        $this->actingAs($user);

        $page = Livewire::test(ListMisLotes::class)
            ->set('tableRecordsPerPage', 5)
            ->searchTable($lots->first()->codigo)
            ->assertCanSeeTableRecords([$lots->first()])
            ->assertCanNotSeeTableRecords([$inactive, $foreign])
            ->assertSee('12 lotes · 6,00 ha totales');

        $this->assertSame(['count' => 12, 'hectares' => 6.0], $page->instance()->getOwnershipSummary());
    }

    public function test_empty_summary_for_owner_with_zero_lots_and_user_without_owner(): void
    {
        [$user] = $this->createOwner();
        $this->actingAs($user);
        Livewire::test(ListMisLotes::class)->assertSee('0 lotes · 0,00 ha totales')->assertCountTableRecords(0);

        $userWithoutOwner = User::factory()->create();
        $userWithoutOwner->assignRole('propietario');
        $this->actingAs($userWithoutOwner);
        Livewire::test(ListMisLotes::class)->assertSee('0 lotes · 0,00 ha totales')->assertCountTableRecords(0);
    }

    public function test_switching_list_and_independent_cards_preserves_search_sort_and_pagination(): void
    {
        [$user, $owner] = $this->createOwner();
        $lots = Lote::factory()->count(12)->sequence(fn ($sequence): array => ['codigo' => 'OWN-'.$sequence->index])->create();
        foreach ($lots as $lot) {
            $this->assign($lot, $owner);
        }
        $this->actingAs($user);
        $page = Livewire::test(ListMisLotes::class)
            ->assertSet('showCards', false)
            ->set('tableRecordsPerPage', 5)
            ->searchTable('OWN-')
            ->sortTable('codigo', 'desc')
            ->call('gotoPage', 2)
            ->callAction('toggleLayout')
            ->assertSet('showCards', true)
            ->assertSet('tableSearch', 'OWN-')
            ->assertSet('tableSort', 'codigo:desc')
            ->assertSet('paginators.page', 2)
            ->assertSee('Ver listado')
            ->assertSee('data-owner-lot-cards', false)
            ->assertDontSee('<table', false)
            ->assertSee('repeat(4, minmax(0, 1fr))', false);
        $expected = $page->instance()->getTableRecords()->modelKeys();
        $page->call('gotoPage', 3)->assertSet('tableSearch', 'OWN-')->assertSet('tableSort', 'codigo:desc');
        $this->assertNotSame($expected, $page->instance()->getTableRecords()->modelKeys());
        $page->call('gotoPage', 2);

        $page->callAction('toggleLayout')->assertSet('showCards', false)->assertSet('paginators.page', 2);
        $this->assertNull($page->instance()->getTable()->getContentGrid());
    }

    public function test_cards_are_scoped_and_have_an_empty_state(): void
    {
        [$user, $owner] = $this->createOwner();
        [, $other] = $this->createOwner();
        $foreign = Lote::factory()->create(['codigo' => 'FOREIGN-CARD']);
        $inactive = Lote::factory()->create(['codigo' => 'INACTIVE-CARD']);
        $this->assign($foreign, $other);
        $this->assign($inactive, $owner, 'unassigned');
        $this->actingAs($user);
        $page = Livewire::test(ListMisLotes::class)->callAction('toggleLayout')
            ->assertSee('No se encontraron lotes.')
            ->assertDontSee('FOREIGN-CARD')->assertDontSee('INACTIVE-CARD')
            ->assertDontSee('<table', false);
        $own = Lote::factory()->create(['codigo' => 'OWN-CARD']);
        $this->assign($own, $owner);
        $page->call('$refresh')->assertSee('OWN-CARD')->assertSee('Ubicación pendiente')
            ->assertDontSee('FOREIGN-CARD')->assertDontSee('INACTIVE-CARD');
    }

    public function test_card_maps_only_include_own_public_confirmed_published_geometry(): void
    {
        [$user, $owner] = $this->createOwner();
        [, $other] = $this->createOwner();
        $stage = Etapa::query()->create(['numero' => 1, 'nombre' => 'Etapa tarjetas']);
        $lot = Lote::factory()->create(['etapa_id' => $stage->id]);
        $foreign = Lote::factory()->create(['etapa_id' => $stage->id]);
        $this->assign($lot, $owner);
        $this->assign($foreign, $other);
        $service = app(MapPersistenceService::class);
        $service->ensureDefaultLayers($stage->id);
        $import = MapImport::factory()->create(['etapa_id' => $stage->id]);
        $geometry = MapGeometry::factory()->create([
            'map_import_id' => $import->id,
            'map_layer_id' => $service->layer($stage->id, 'lotes')->id,
            'lote_id' => $lot->id,
            'is_confirmed' => true,
            'properties' => ['secret' => 'NEVER_SERIALIZE'],
        ]);
        MapGeometry::factory()->create([
            'map_import_id' => $import->id,
            'map_layer_id' => $geometry->map_layer_id,
            'lote_id' => $foreign->id,
            'is_confirmed' => true,
        ]);
        $this->actingAs($user);
        $page = Livewire::test(ListMisLotes::class)->callAction('toggleLayout')->assertSee('Ubicación pendiente');
        $service->publish($import);
        $page->call('$refresh')->assertSee('owner-lot-mini-'.$lot->id, false)
            ->assertDontSee('owner-lot-mini-'.$foreign->id, false)->assertDontSee('NEVER_SERIALIZE');
        $features = $page->instance()->getCardFeatures($page->instance()->getTableRecords()->getCollection());
        $this->assertSame([$lot->id], $features->keys()->all());
        $this->assertSame($geometry->geometry, $features[$lot->id]['geometry']);
        $geometry->update(['is_confirmed' => false]);
        $page->call('$refresh')->assertSee('Ubicación pendiente')->assertDontSee('owner-lot-mini-', false);
        $geometry->update(['is_confirmed' => true, 'map_layer_id' => $service->layer($stage->id, 'infraestructura')->id]);
        $page->call('$refresh')->assertSee('Ubicación pendiente')->assertDontSee('owner-lot-mini-', false);
    }

    public function test_owner_detail_is_navigable_read_only_and_does_not_serialize_private_fields(): void
    {
        [$user, $owner] = $this->createOwner();
        [, $other] = $this->createOwner();
        $lot = Lote::factory()->create(['notas' => 'Nota del lote propio', 'valor_lote' => 987654321]);
        $this->assign($lot, $owner);
        $this->assign($lot, $other, 'unassigned');
        $this->actingAs($user);

        $url = LoteResource::getUrl('view', ['record' => $lot], panel: 'owner');
        Livewire::test(ListMisLotes::class)->assertSee($url, false);
        $this->get($url)->assertOk()
            ->assertSeeInOrder(['Información general del terreno', 'Ficha del lote', 'Ubicación en el mapa'])
            ->assertSee('Solo lectura')
            ->assertSee('Ubicación pendiente')
            ->assertSee('lote-detail-grid', false)
            ->assertDontSee('Guardar cambios')
            ->assertSee('Nota del lote propio')
            ->assertDontSee('987654321')
            ->assertDontSee($other->user->email);

        Livewire::test(ViewLote::class, ['record' => $lot->id])->assertSet('data', []);
        $this->assertFalse(LoteResource::canEdit($lot));
        $this->assertFalse(LoteResource::canDelete($lot));
        $this->assertFalse(method_exists(ViewLote::class, 'save'));
    }

    public function test_manipulated_detail_url_rejects_other_and_inactive_lots(): void
    {
        [$user, $owner] = $this->createOwner();
        [, $other] = $this->createOwner();
        $foreign = Lote::factory()->create();
        $inactive = Lote::factory()->create();
        $this->assign($foreign, $other);
        $this->assign($inactive, $owner, 'unassigned');
        $this->actingAs($user);

        foreach ([$foreign, $inactive] as $lot) {
            $this->get(LoteResource::getUrl('view', ['record' => $lot], panel: 'owner'))->assertNotFound();
        }
    }

    public function test_detail_rechecks_active_ownership_on_livewire_requests(): void
    {
        [$user, $owner] = $this->createOwner();
        $lot = Lote::factory()->create();
        $this->assign($lot, $owner);
        $this->actingAs($user);
        $page = Livewire::test(ViewLote::class, ['record' => $lot->id])->assertOk();

        $lot->propietarios()->updateExistingPivot($owner->id, ['status' => 'unassigned']);

        $page->call('$refresh')->assertForbidden();
    }

    public function test_detail_map_only_shows_confirmed_published_public_geometry(): void
    {
        [$user, $owner] = $this->createOwner();
        $stage = Etapa::query()->create(['numero' => 1, 'nombre' => 'Etapa del propietario']);
        $lot = Lote::factory()->create(['etapa_id' => $stage->id]);
        $this->assign($lot, $owner);
        $service = app(MapPersistenceService::class);
        $service->ensureDefaultLayers($stage->id);
        $import = MapImport::factory()->create(['etapa_id' => $stage->id]);
        $geometry = MapGeometry::factory()->create([
            'map_import_id' => $import->id,
            'map_layer_id' => $service->layer($stage->id, 'lotes')->id,
            'lote_id' => $lot->id,
            'is_confirmed' => true,
            'properties' => ['name' => 'PUBLIC_SHAPE', 'secret' => 'PRIVATE_GEOMETRY_PROPERTY'],
        ]);
        $privateLayer = $service->layer($stage->id, 'infraestructura');
        $privateLayer->update(['name' => 'PRIVATE_LAYER']);
        MapGeometry::factory()->create([
            'map_import_id' => $import->id,
            'map_layer_id' => $privateLayer->id,
            'properties' => ['name' => 'PRIVATE_SHAPE'],
        ]);
        $this->actingAs($user);
        $url = LoteResource::getUrl('view', ['record' => $lot], panel: 'owner');

        $this->get($url)->assertOk()->assertSee('Ubicación pendiente')->assertDontSee('PUBLIC_SHAPE');
        $service->publish($import);
        $this->get($url)->assertOk()->assertSee('PUBLIC_SHAPE')
            ->assertSee('new maplibregl.FullscreenControl', false)
            ->assertSee("false,\n    true)", false)
            ->assertDontSee('PRIVATE_LAYER')->assertDontSee('PRIVATE_SHAPE')
            ->assertDontSee('PRIVATE_GEOMETRY_PROPERTY');

        $geometry->update(['is_confirmed' => false]);
        $this->get($url)->assertOk()->assertSee('Ubicación pendiente')->assertDontSee('PUBLIC_SHAPE');
        $geometry->update(['is_confirmed' => true, 'map_layer_id' => $privateLayer->id]);
        $this->get($url)->assertOk()->assertSee('Ubicación pendiente')->assertDontSee('PRIVATE_SHAPE');
    }

    /** @return array{User, Propietario} */
    private function createOwner(): array
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');

        return [$user, Propietario::factory()->create(['user_id' => $user->id])];
    }

    private function assign(Lote $lot, Propietario $owner, string $status = 'active'): void
    {
        $lot->propietarios()->attach($owner->id, ['status' => $status, 'assigned_at' => now()]);
    }
}
