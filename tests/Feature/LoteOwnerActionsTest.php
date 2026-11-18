<?php

namespace Tests\Feature;

use App\Domain\Owners\Services\LoteOwnershipAssignmentService;
use App\Filament\Resources\Lotes\Pages\ViewLote;
use App\Filament\Resources\Propietarios\PropietarioResource;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\Propietario;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LoteOwnerActionsTest extends TestCase
{
    use RefreshDatabase;

    private Lote $lote;

    private Propietario $propietario;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $etapa = Etapa::query()->create(['numero' => 1, 'nombre' => 'Prueba']);
        $this->lote = Lote::factory()->create(['estado' => 'disponible', 'etapa_id' => $etapa->id]);
        $this->propietario = Propietario::factory()->create();

        app(LoteOwnershipAssignmentService::class)->assign($this->propietario, $this->lote);
    }

    public function test_detail_links_to_active_owner_and_renders_confirmation_action(): void
    {
        $this->get('/admin/lotes/'.$this->lote->id)
            ->assertOk()
            ->assertSee(PropietarioResource::getUrl('edit', ['record' => $this->propietario]), false)
            ->assertSee('Desasignar');
    }

    public function test_confirming_unassignment_preserves_history_and_updates_lote_state(): void
    {
        Livewire::test(ViewLote::class, ['record' => $this->lote->id])
            ->callAction('desasignar', arguments: ['propietarioId' => $this->propietario->id])
            ->assertSee('Sin propietario asignado actualmente.');

        $this->assertSame('disponible', $this->lote->fresh()->estado);
        $this->assertDatabaseHas('lote_propietario', [
            'lote_id' => $this->lote->id,
            'propietario_id' => $this->propietario->id,
            'status' => 'unassigned',
        ]);
        $this->assertNotNull($this->propietario->lotes()->first()->pivot->unassigned_at);
    }

    public function test_opening_confirmation_does_not_unassign(): void
    {
        Livewire::test(ViewLote::class, ['record' => $this->lote->id])
            ->mountAction('desasignar', arguments: ['propietarioId' => $this->propietario->id]);

        $this->assertSame('reservado', $this->lote->fresh()->estado);
        $this->assertDatabaseHas('lote_propietario', [
            'lote_id' => $this->lote->id,
            'propietario_id' => $this->propietario->id,
            'status' => 'active',
        ]);
    }

    public function test_historical_owner_is_not_shown_as_current(): void
    {
        app(LoteOwnershipAssignmentService::class)->unassign($this->propietario, $this->lote);

        $this->get('/admin/lotes/'.$this->lote->id)
            ->assertOk()
            ->assertSee('Sin propietario asignado actualmente.')
            ->assertDontSee(PropietarioResource::getUrl('edit', ['record' => $this->propietario]), false);
    }

    public function test_sold_lote_cannot_be_unassigned_even_with_forged_action(): void
    {
        $this->lote->update(['estado' => 'vendido']);

        Livewire::test(ViewLote::class, ['record' => $this->lote->id])
            ->callAction('desasignar', arguments: ['propietarioId' => $this->propietario->id]);

        $this->assertSame('vendido', $this->lote->fresh()->estado);
        $this->assertDatabaseHas('lote_propietario', [
            'lote_id' => $this->lote->id,
            'propietario_id' => $this->propietario->id,
            'status' => 'active',
        ]);
    }

    public function test_unrelated_owner_id_cannot_be_unassigned(): void
    {
        $other = Propietario::factory()->create();

        Livewire::test(ViewLote::class, ['record' => $this->lote->id])
            ->callAction('desasignar', arguments: ['propietarioId' => $other->id])
            ->assertNotFound();

        $this->assertSame('reservado', $this->lote->fresh()->estado);
    }
}
