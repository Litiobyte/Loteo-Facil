<?php

namespace Tests\Feature\Owner;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domain\Charges\Enums\ChargeStatus;
use App\Filament\Owner\Pages\Dashboard;
use App\Models\Collection;
use App\Models\CommunitySetting;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\ShareHolding;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('propietario', 'web');
        Filament::setCurrentPanel(Filament::getPanel('owner'));
    }

    public function test_dashboard_route_renders_real_scoped_data_and_preserves_existing_details(): void
    {
        [$user, $owner] = $this->createOwner();
        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id, 'description' => 'Mantención propia',
            'amount' => 45000, 'paid_amount' => 0, 'due_date' => today()->addDays(3),
        ]);
        PartnerCharge::factory()->create(['description' => 'Cobro privado ajeno']);
        $lot = Lote::factory()->create(['codigo' => 'MI-LOTE', 'metros_cuadrados' => 10000]);
        $owner->lotes()->attach($lot, ['status' => 'active', 'assigned_at' => now()]);

        $this->actingAs($user)->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('Mi comunidad')
            ->assertSee('Mantención propia')
            ->assertSee('MI-LOTE')
            ->assertSee('Saldo a favor')
            ->assertSee('Balance neto')
            ->assertSee('Estimación próximo pago')
            ->assertDontSee('Cobro privado ajeno')
            ->assertDontSee('Asamblea de propietarios');
    }

    #[DataProvider('participationModes')]
    public function test_dashboard_respects_real_participation_mode(string $mode, bool $shares, bool $lots): void
    {
        [$user, $owner] = $this->createOwner();
        CommunitySetting::query()->whereKey(1)->update(['mode' => $mode]);
        ShareHolding::query()->create([
            'propietario_id' => $owner->id, 'quantity' => '12.5000',
            'square_meters_per_share' => '800', 'acquired_at' => today(), 'status' => 'active',
        ]);
        $this->actingAs($user);

        $page = Livewire::test(Dashboard::class)
            ->assertViewHas('usesShares', $shares)
            ->assertViewHas('usesLots', $lots)
            ->assertViewHas('shares', fn (array $totals): bool => (float) $totals['quantity'] === 12.5);

        if ($shares) {
            $page->assertSee('Mis acciones')->assertSee('Superficie referencial')->assertSee('12.5');
        } else {
            $page->assertDontSee('Superficie referencial');
        }

        if ($lots) {
            $page->assertSee('Lotes asignados')->assertSee('Mapa del proyecto');
        } else {
            $page->assertDontSee('Lotes asignados')->assertDontSee('Mapa del proyecto');
        }
    }

    public static function participationModes(): array
    {
        return [
            'lots' => ['lots', false, true],
            'shares' => ['shares', true, false],
            'mixed' => ['mixed', true, true],
        ];
    }

    #[DataProvider('communityLogos')]
    public function test_community_name_is_visible_only_with_default_logo_in_owner_panel(?string $path, bool $exists, bool $showName): void
    {
        Storage::fake('public');
        if ($exists) {
            Storage::disk('public')->put($path, 'logo');
        }

        CommunitySetting::query()->whereKey(1)->update([
            'community_name' => 'Comunidad de prueba',
            'logo_path' => $path,
        ]);
        [$user] = $this->createOwner();

        $response = $this->actingAs($user)->get(Dashboard::getUrl())->assertOk();
        if ($showName) {
            $response->assertSeeText('Comunidad de prueba');
            $response->assertSee('<span class="od-context">Comunidad de prueba</span>', false);
        } else {
            $response->assertDontSeeText('Comunidad de prueba');
        }

        $response->assertSee('alt="Comunidad de prueba"', false);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->view('filament.branding.brand-logo')->assertSeeText('Comunidad de prueba');
    }

    public static function communityLogos(): array
    {
        $validPath = 'community-logos/'.str_repeat('a', 40).'.png';

        return [
            'default' => [null, false, true],
            'custom' => [$validPath, true, false],
            'missing file falls back' => [$validPath, false, true],
            'invalid path falls back' => ['community-logos/invalid.png', true, true],
        ];
    }

    public function test_movement_filters_and_show_more_are_scoped_and_reset_limit(): void
    {
        [$user, $owner] = $this->createOwner();
        PartnerCharge::factory()->count(7)->create(['propietario_id' => $owner->id]);
        Collection::factory()->create(['propietario_id' => $owner->id, 'reference' => 'PAGO-PROPIO']);
        Collection::factory()->create(['reference' => 'PAGO-AJENO']);
        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->assertViewHas('movementCount', 8)
            ->assertViewHas('movements', fn ($rows): bool => $rows->count() === 5)
            ->call('showMoreMovements')
            ->assertViewHas('movements', fn ($rows): bool => $rows->count() === 8)
            ->call('filterMovements', 'payment')
            ->assertSet('movementLimit', 5)
            ->assertViewHas('movementCount', 1)
            ->assertSee('PAGO-PROPIO')
            ->assertDontSee('PAGO-AJENO')
            ->call('filterMovements', 'allocation')
            ->assertSee('No hay movimientos para este filtro.');
    }

    public function test_pending_list_excludes_paid_cancelled_and_consolidated_charges(): void
    {
        [$user, $owner] = $this->createOwner();
        PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'description' => 'Pendiente sin fecha', 'due_date' => null]);
        PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'description' => 'Pendiente con fecha', 'due_date' => today()->subDay()]);
        foreach ([ChargeStatus::Paid, ChargeStatus::Cancelled, ChargeStatus::Consolidated] as $status) {
            PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'status' => $status]);
        }
        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->assertViewHas('pendingCount', 2)
            ->assertViewHas('pendingCharges', fn ($rows): bool => $rows->first()->description === 'Pendiente con fecha')
            ->assertSee('Vencido')
            ->assertSee('Sin fecha de vencimiento');
    }

    public function test_owner_without_profile_gets_clear_empty_state(): void
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->assertSee('No tienes un propietario asociado')
            ->assertDontSee('Mis movimientos');
    }

    public function test_unknown_movement_filter_is_rejected(): void
    {
        [$user] = $this->createOwner();
        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->call('filterMovements', 'unsupported')
            ->assertStatus(422);
    }

    public function test_guests_and_other_roles_cannot_access_owner_dashboard(): void
    {
        $this->get(Dashboard::getUrl())->assertRedirect();
        $this->actingAs(User::factory()->create())->get(Dashboard::getUrl())->assertForbidden();
    }

    /** @return array{User, Propietario} */
    private function createOwner(): array
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');

        return [$user, Propietario::factory()->create(['user_id' => $user->id])];
    }
}
