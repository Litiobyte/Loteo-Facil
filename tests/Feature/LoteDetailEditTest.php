<?php

namespace Tests\Feature;

use App\Filament\Resources\Lotes\LoteResource;
use App\Filament\Resources\Lotes\Pages\ViewLote;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LoteDetailEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_lote_detail_is_editable_and_shows_map(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $etapa = Etapa::query()->create(['numero' => 1, 'nombre' => 'Prueba']);
        $lote = Lote::factory()->create(['codigo' => '6-2', 'etapa_id' => $etapa->id]);

        $this->actingAs($admin)
            ->get(LoteResource::getUrl('view', ['record' => $lote]))
            ->assertOk()
            ->assertSee('Ficha del lote')
            ->assertSee('Ubicación en el mapa')
            ->assertSee('Guardar cambios')
            ->assertSee('Información general del terreno')
            ->assertSee('Ubicación pendiente')
            ->assertSee('Aún no hay mapa publicado')
            ->assertSee('lote-detail-columns', false)
            ->assertSee('grid-template-columns: repeat(2, minmax(0, 1fr))', false)
            ->assertSeeInOrder(['Información general del terreno', 'Ficha del lote', 'Guardar cambios', 'Ubicación en el mapa'])
            ->assertSee('wire:submit="save"', false);
    }

    public function test_save_updates_lote_record(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $etapa = Etapa::query()->create(['numero' => 1, 'nombre' => 'Prueba']);
        $lote = Lote::factory()->create(['codigo' => '6-2', 'etapa_id' => $etapa->id]);

        Livewire::test(ViewLote::class, ['record' => $lote->id])
            ->fillForm([
                'estado' => 'vendido',
                'valor_lote' => 9_999_999,
                'notas' => 'Ficha actualizada desde el formulario.',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Lote actualizado correctamente');

        $lote->refresh();

        $this->assertSame('vendido', $lote->estado);
        $this->assertSame(9_999_999, (int) $lote->valor_lote);
        $this->assertSame('Ficha actualizada desde el formulario.', $lote->notas);
    }

    public function test_save_validates_the_editable_form(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $lote = Lote::factory()->create(['metros_cuadrados' => 5000]);

        Livewire::test(ViewLote::class, ['record' => $lote->id])
            ->fillForm(['metros_cuadrados' => 0])
            ->call('save')
            ->assertHasFormErrors(['metros_cuadrados']);

        $this->assertSame(5000, (int) $lote->fresh()->metros_cuadrados);
    }

    public function test_owner_cannot_access_admin_lote_detail(): void
    {
        Role::findOrCreate('propietario', 'web');
        $user = User::factory()->create();
        $user->assignRole('propietario');

        $etapa = Etapa::query()->create(['numero' => 1, 'nombre' => 'Prueba']);
        $lote = Lote::factory()->create(['codigo' => '6-2', 'etapa_id' => $etapa->id]);

        $this->actingAs($user)->get(LoteResource::getUrl('view', ['record' => $lote]))->assertForbidden();
    }
}
