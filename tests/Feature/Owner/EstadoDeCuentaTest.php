<?php

namespace Tests\Feature\Owner;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Collections\Enums\CollectionStatus;
use App\Filament\Owner\Pages\EstadoDeCuenta;
use App\Models\Collection;
use App\Models\Expense;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EstadoDeCuentaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('propietario', 'web');
        Filament::setCurrentPanel(Filament::getPanel('owner'));
    }

    public function test_statement_design_renders_real_data_without_exposing_other_owners(): void
    {
        [$user, $owner] = $this->createOwner();
        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'description' => 'Mantención de mi comunidad',
            'amount' => 15000,
            'paid_amount' => 0,
            'due_date' => today()->subDays(3),
        ]);
        PartnerCharge::factory()->create(['description' => 'Obligación privada de otro propietario']);

        $this->actingAs($user)->get(EstadoDeCuenta::getUrl())
            ->assertOk()
            ->assertSee('Mi estado de cuenta')
            ->assertSee('Saldo pendiente actual')
            ->assertSee('Mantención de mi comunidad')
            ->assertSee('Mis movimientos')
            ->assertSee('Tienes 1 cobros vencidos')
            ->assertSee('Comparación mes a mes')
            ->assertSee('Desglose por categoría')
            ->assertSee('Imprimir / PDF')
            ->assertSee('.fi-main-ctn { display: block !important', false)
            ->assertSee('.os-table tr, .os-sumline { break-inside: avoid; }', false)
            ->assertDontSee('Obligación privada de otro propietario')
            ->assertDontSee('Maqueta demostrativa');
    }

    public function test_date_filter_changes_history_but_keeps_current_pending_charges(): void
    {
        [$user, $owner] = $this->createOwner();
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => 15000,
            'expense_id' => Expense::factory()->state(['due_date' => null]),
            'paid_amount' => 0,
            'due_date' => today()->subMonths(2),
            'created_at' => now()->subMonths(2),
        ]);
        $other = PartnerCharge::factory()->create();
        Collection::factory()->create([
            'propietario_id' => $owner->id, 'amount' => 5000,
            'collection_date' => today(), 'status' => CollectionStatus::PendingApplication,
        ]);
        Collection::factory()->create([
            'propietario_id' => $owner->id, 'amount' => 9000,
            'collection_date' => today(), 'status' => CollectionStatus::Cancelled,
        ]);
        Collection::factory()->create([
            'propietario_id' => $owner->id, 'amount' => 7000,
            'collection_date' => today()->subMonths(2),
        ]);
        $this->actingAs($user);

        Livewire::test(EstadoDeCuenta::class)
            ->call('setQuickFilter', 'this_month')
            ->assertCanSeeTableRecords([$charge])
            ->assertCanNotSeeTableRecords([$other])
            ->assertViewHas('periodPaid', 5000.0)
            ->assertViewHas('pendingCharges', fn ($charges): bool => $charges->contains('id', $charge->id))
            ->assertViewHas('timeline', fn ($timeline): bool => ! $timeline->contains(fn ($event): bool => $event['type'] === 'charge' && $event['related_id'] === $charge->id))
            ->assertSee('Estos saldos son actuales y no cambian al filtrar el historial.');
    }

    public function test_empty_statement_renders_without_overdue_notice(): void
    {
        [$user] = $this->createOwner();

        $this->actingAs($user)->get(EstadoDeCuenta::getUrl())
            ->assertOk()
            ->assertSee('Sin movimientos en el período seleccionado.')
            ->assertSee('No hay deuda pendiente por categoría.')
            ->assertDontSee('Ver cobro vencido');
    }

    public function test_page_returns_null_statement_when_user_has_no_propietario(): void
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $this->actingAs($user);

        $page = new EstadoDeCuenta;
        $data = $this->invokeProtectedMethod($page, 'getViewData');

        $this->assertNull($data['statement']);
    }

    public function test_page_returns_statement_for_authenticated_owner(): void
    {
        [$user, $owner] = $this->createOwner();
        PartnerCharge::factory()->create(['propietario_id' => $owner->id]);
        Collection::factory()->create(['propietario_id' => $owner->id]);

        $this->actingAs($user);

        $page = new EstadoDeCuenta;
        $data = $this->invokeProtectedMethod($page, 'getViewData');

        $this->assertNotNull($data['statement']);
        $this->assertSame($owner->id, $data['statement']['owner']['id']);
    }

    public function test_debt_by_category_only_includes_positive_remaining_amounts(): void
    {
        [$user, $owner] = $this->createOwner();

        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'remaining_amount' => 50000,
            'status' => ChargeStatus::Pending->value,
        ]);
        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'remaining_amount' => 0,
            'status' => ChargeStatus::Paid->value,
        ]);

        $this->actingAs($user);

        $page = new EstadoDeCuenta;
        $data = $this->invokeProtectedMethod($page, 'getViewData');

        $this->assertGreaterThan(0, $data['debtByCategory']->sum());
    }

    public function test_pending_charges_collection_only_contains_pending_and_partial(): void
    {
        [$user, $owner] = $this->createOwner();

        PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'status' => ChargeStatus::Pending->value]);
        PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'status' => ChargeStatus::Partial->value]);
        PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'status' => ChargeStatus::Paid->value]);

        $this->actingAs($user);

        $page = new EstadoDeCuenta;
        $data = $this->invokeProtectedMethod($page, 'getViewData');

        $this->assertCount(2, $data['pendingCharges']);
    }

    public function test_quick_filters_set_expected_date_ranges(): void
    {
        $page = new EstadoDeCuenta;

        $page->setQuickFilter('this_month');
        $this->assertNotNull($page->desde);
        $this->assertNotNull($page->hasta);

        $page->setQuickFilter('all');
        $this->assertNull($page->desde);
        $this->assertNull($page->hasta);
    }

    public function test_month_comparison_contains_three_months(): void
    {
        [$user] = $this->createOwner();
        $this->actingAs($user);

        $page = new EstadoDeCuenta;
        $page->mount();

        $this->assertCount(3, $page->monthComparison);
    }

    public function test_timeline_is_limited_to_twenty_items(): void
    {
        [$user, $owner] = $this->createOwner();
        PartnerCharge::factory()->count(25)->create(['propietario_id' => $owner->id]);

        $this->actingAs($user);

        $page = new EstadoDeCuenta;
        $data = $this->invokeProtectedMethod($page, 'getViewData');

        $this->assertCount(20, $data['timeline']);
    }

    /**
     * @return array{0: User, 1: Propietario}
     */
    private function createOwner(): array
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $owner = Propietario::factory()->create(['user_id' => $user->id]);

        return [$user, $owner];
    }

    private function invokeProtectedMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }
}
