<?php

namespace Tests\Feature\Owner;

use App\Filament\Owner\Resources\ShareHoldingResource;
use App\Filament\Owner\Resources\ShareHoldingResource\Pages\ListMyShares;
use App\Models\CommunitySetting;
use App\Models\Lote;
use App\Models\Propietario;
use App\Models\ShareHolding;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MyParticipationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('propietario', 'web');
        Filament::setCurrentPanel(Filament::getPanel('owner'));
    }

    public static function participationModes(): array
    {
        return [
            'solo lotes' => ['lots'],
            'solo acciones' => ['shares'],
            'mixto' => ['mixed'],
        ];
    }

    #[DataProvider('participationModes')]
    public function test_navigation_registers_in_every_mode(string $mode): void
    {
        CommunitySetting::query()->whereKey(1)->update(['mode' => $mode]);
        [$user] = $this->createOwner();
        $this->actingAs($user);

        $this->assertTrue(ShareHoldingResource::shouldRegisterNavigation());
    }

    #[DataProvider('participationModes')]
    public function test_page_follows_community_mode(string $mode): void
    {
        [$user, $owner] = $this->createOwner();
        CommunitySetting::query()->whereKey(1)->update(['mode' => $mode]);
        ShareHolding::query()->create([
            'propietario_id' => $owner->id, 'quantity' => '12.5000',
            'square_meters_per_share' => '800', 'acquired_at' => today(), 'status' => 'active',
        ]);
        $own = Lote::factory()->create(['codigo' => 'LOT-PROPIO', 'metros_cuadrados' => 5000]);
        $owner->lotes()->attach($own, ['status' => 'active', 'assigned_at' => now()]);
        $other = Propietario::factory()->create();
        $foreign = Lote::factory()->create(['codigo' => 'LOT-AJENO']);
        $other->lotes()->attach($foreign, ['status' => 'active', 'assigned_at' => now()]);
        $this->actingAs($user);

        $page = Livewire::test(ListMyShares::class);

        if ($mode === 'shares') {
            $page->assertSee('Solo acciones')
                ->assertSee('Mis acciones')
                ->assertSee('Superficie referencial')
                ->assertSee('Estado de asignación')
                ->assertSee('Distribución de lotes pendiente')
                ->assertSee('10.000 m²')
                ->assertDontSee('Superficie de lotes')
                ->assertDontSee('LOT-PROPIO');
        } elseif ($mode === 'lots') {
            $page->assertSee('Solo lotes')
                ->assertSee('Mis terrenos')
                ->assertSee('Superficie de lotes')
                ->assertSee('LOT-PROPIO')
                ->assertSee('5.000 m²')
                ->assertSee('Asignación de Lote LOT-PROPIO')
                ->assertDontSee('Superficie referencial')
                ->assertDontSee('Distribución de lotes pendiente');
        } else {
            $page->assertSee('Acciones y lotes')
                ->assertSee('Acciones y lotes son registros independientes.')
                ->assertSee('Mis acciones')
                ->assertSee('Superficie referencial')
                ->assertSee('Mis terrenos')
                ->assertSee('Superficie de lotes')
                ->assertSee('LOT-PROPIO')
                ->assertSee('10.000 m²');
        }

        $page->assertDontSee('LOT-AJENO');
    }

    public function test_lot_history_scopes_and_reports_withdrawals(): void
    {
        [$user, $owner] = $this->createOwner();
        CommunitySetting::query()->whereKey(1)->update(['mode' => 'lots']);
        $withdrawn = Lote::factory()->create(['codigo' => 'LOT-RETIRADO', 'metros_cuadrados' => 3000]);
        $owner->lotes()->attach($withdrawn, ['status' => 'inactive', 'assigned_at' => now()->subMonth(), 'unassigned_at' => now()->subWeek()]);
        $other = Propietario::factory()->create();
        $foreign = Lote::factory()->create(['codigo' => 'LOT-AJENO']);
        $other->lotes()->attach($foreign, ['status' => 'active', 'assigned_at' => now()]);
        $this->actingAs($user);

        Livewire::test(ListMyShares::class)
            ->assertSee('Asignación de Lote LOT-RETIRADO')
            ->assertSee('Retiro de Lote LOT-RETIRADO')
            ->assertDontSee('LOT-AJENO');
    }

    /** @return array{User, Propietario} */
    private function createOwner(): array
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');

        return [$user, Propietario::factory()->create(['user_id' => $user->id])];
    }
}
