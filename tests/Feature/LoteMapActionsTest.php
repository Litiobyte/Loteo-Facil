<?php

namespace Tests\Feature;

use App\Domain\Maps\Services\MapPersistenceService;
use App\Filament\Owner\Resources\Lotes\LoteResource as OwnerLoteResource;
use App\Filament\Resources\Lotes\Pages\ManageLotes;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\MapGeometry;
use App\Models\MapImport;
use App\Models\Propietario;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LoteMapActionsTest extends TestCase
{
    use RefreshDatabase;

    private MapPersistenceService $service;

    private Etapa $etapa;

    private Lote $lote;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MapPersistenceService::class);
        $this->etapa = Etapa::query()->create(['numero' => 1, 'nombre' => 'Prueba']);
        $this->lote = Lote::factory()->create(['codigo' => '6-2', 'etapa_id' => $this->etapa->id]);
        $import = MapImport::factory()->create(['etapa_id' => $this->etapa->id]);

        $this->service->ensureDefaultLayers($this->etapa->id);
        $this->service->publish($import);

        MapGeometry::factory()->create([
            'map_import_id' => $import->id,
            'map_layer_id' => $this->service->layer($this->etapa->id, 'lotes')->id,
            'lote_id' => $this->lote->id,
            'is_confirmed' => true,
            'normalized_identifier' => '6-2',
        ]);
    }

    public function test_admin_manage_lotes_page_renders(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('/admin/lotes')->assertOk();
    }

    public function test_ver_en_mapa_builds_shared_map_panel(): void
    {
        $page = new ManageLotes;
        $page->verEnMapa($this->lote);

        $this->assertTrue($page->mapVisible);
        $this->assertSame($this->lote->id, $page->selectedLoteId);

        $footer = $page->getFooter();

        $this->assertNotNull($footer);
        $this->assertStringContainsString(
            'lotes-split-'.$this->etapa->id.'-'.$this->lote->id,
            $footer->render(),
        );
    }

    public function test_admin_lote_detail_is_a_page_with_map(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('/admin/lotes/'.$this->lote->id)
            ->assertOk()
            ->assertSee($this->lote->codigo)
            ->assertSee('lote-detail-'.$this->lote->id.'-'.$this->etapa->id)
            ->assertSee('Ver mapa en pantalla completa')
            ->assertSeeInOrder(['Ficha del lote', 'Guardar cambios', 'Ubicación en el mapa'])
            ->assertSee('El contorno naranja destaca el lote');
    }

    public function test_owner_cannot_access_admin_lote_detail(): void
    {
        Role::findOrCreate('propietario', 'web');
        $user = User::factory()->create();
        $user->assignRole('propietario');

        $this->actingAs($user)->get('/admin/lotes/'.$this->lote->id)->assertForbidden();
    }

    public function test_owner_sees_detail_map_of_own_lote(): void
    {
        Role::findOrCreate('propietario', 'web');
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $propietario = Propietario::factory()->create(['user_id' => $user->id]);
        $this->lote->propietarios()->attach($propietario->id, ['status' => 'active', 'assigned_at' => now()]);

        $this->actingAs($user);

        $result = $this->service->loteFeature($this->lote->id, false);

        $this->assertSame('ok', $result['status']);
        $this->assertSame($this->lote->id, $result['feature']['properties']['lote_id']);
        $this->get('/propietario/lotes')->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $response = $this->get(OwnerLoteResource::getUrl('view', ['record' => $this->lote], panel: 'owner'))
            ->assertOk()
            ->assertSee('Información general del terreno')
            ->assertSee('Superficie')
            ->assertSee('Ubicación en el mapa')
            ->assertSee('El contorno naranja destaca el lote')
            ->assertDontSee('Guardar cambios')
            ->assertDontSee('Desasignar')
            ->assertDontSee('Datos financieros');

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<div x-ref="map"'));
        $this->assertStringContainsString('lote-detail-columns', $html);
        $this->assertStringContainsString('@keydown.escape.window.capture', $html);
        $this->assertLessThan(strpos($html, 'Ubicación en el mapa'), strpos($html, 'Notas'));
    }

    public function test_owner_detail_without_geometry_shows_fallback_without_admin_controls(): void
    {
        Role::findOrCreate('propietario', 'web');
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $propietario = Propietario::factory()->create(['user_id' => $user->id]);
        $lote = Lote::factory()->create(['etapa_id' => $this->etapa->id, 'notas' => 'Nota visible al propietario.']);
        $lote->propietarios()->attach($propietario->id, ['status' => 'active', 'assigned_at' => now()]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('owner'));

        $this->get(OwnerLoteResource::getUrl('view', ['record' => $lote], panel: 'owner'))
            ->assertOk()
            ->assertSee('Ubicación pendiente')
            ->assertSee('Este lote todavía no tiene geometría asociada')
            ->assertSee('Nota visible al propietario.')
            ->assertDontSee('<div x-ref="map"', false)
            ->assertDontSee('Guardar cambios')
            ->assertDontSee('Desasignar');
    }
}
