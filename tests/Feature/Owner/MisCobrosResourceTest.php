<?php

namespace Tests\Feature\Owner;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Filament\Owner\Resources\Cobros\MisCobrosResource;
use App\Filament\Owner\Resources\Cobros\Pages\ListMisCobros;
use App\Filament\Owner\Resources\Cobros\Pages\ViewCobro;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MisCobrosResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('propietario', 'web');
        Filament::setCurrentPanel(Filament::getPanel('owner'));
    }

    public function test_redesigned_list_uses_real_scoped_totals_and_statuses(): void
    {
        [$user, $owner] = $this->createOwner();
        $pending = PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'amount' => 15000, 'paid_amount' => 5000, 'status' => ChargeStatus::Partial, 'description' => 'Mi cobro vencido', 'due_date' => today()->subDay()]);
        $consolidated = PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'amount' => 5000, 'status' => ChargeStatus::Consolidated, 'description' => 'Mi cobro consolidado']);
        $paid = PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'amount' => 2000, 'paid_amount' => 2000, 'status' => ChargeStatus::Paid]);
        PartnerCharge::factory()->create(['description' => 'Cobro privado ajeno', 'amount' => 999999]);
        $this->actingAs($user);

        $this->get(MisCobrosResource::getUrl())->assertOk()
            ->assertSee('Detalle de mis cobros')->assertSee('Mi cobro vencido')
            ->assertSee('Vencido · Parcial')->assertSee('Mi cobro consolidado')
            ->assertDontSee('Cobro privado ajeno');

        Livewire::test(ListMisCobros::class)
            ->assertViewHas('pendingBalance', 10000.0)
            ->assertViewHas('pendingCount', 1)
            ->assertViewHas('issuedTotal', fn ($value): bool => (float) $value === 22000.0)
            ->assertViewHas('paidTotal', fn ($value): bool => (float) $value === 7000.0)
            ->assertViewHas('chargeCount', 3)
            ->assertCanSeeTableRecords([$pending, $consolidated, $paid])
            ->set('activeTab', 'pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$consolidated, $paid])
            ->assertViewHas('chargeCount', 3)
            ->set('activeTab', 'consolidated')
            ->assertCanSeeTableRecords([$consolidated])
            ->assertCanNotSeeTableRecords([$pending, $paid])
            ->set('activeTab', 'all')
            ->searchTable('Mi cobro vencido')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$consolidated, $paid]);
    }

    public function test_badge_counts_only_positive_unpaid_balances_of_current_owner(): void
    {
        [$user, $owner] = $this->createOwner();
        foreach ([
            [ChargeStatus::Pending, 0],
            [ChargeStatus::Partial, 500],
            [ChargeStatus::Pending, 1000],
            [ChargeStatus::Paid, 1000],
            [ChargeStatus::Cancelled, 0],
            [ChargeStatus::Consolidated, 0],
        ] as [$status, $paid]) {
            PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'amount' => 1000, 'paid_amount' => $paid, 'status' => $status]);
        }
        PartnerCharge::factory()->create();
        $this->actingAs($user);
        $this->assertSame('2', MisCobrosResource::getNavigationBadge());
        $this->assertSame('danger', MisCobrosResource::getNavigationBadgeColor());
        [$otherUser] = $this->createOwner();
        $this->actingAs($otherUser);
        $this->assertNull(MisCobrosResource::getNavigationBadge());
    }

    public function test_empty_list_and_detail_access_remain_scoped(): void
    {
        [$user] = $this->createOwner();
        $other = PartnerCharge::factory()->create(['description' => 'Detalle privado']);
        $this->actingAs($user);
        Livewire::test(ListMisCobros::class)->assertViewHas('chargeCount', 0)
            ->assertViewHas('hasConsolidated', false)->assertSee('Sin cobros registrados')
            ->assertDontSee('Sobre tus cobros anteriores:')->assertDontSee('Detalle privado');
        $this->get(MisCobrosResource::getUrl('view', ['record' => $other]))->assertNotFound();
    }

    public function test_export_respects_owner_search_and_tab_and_neutralizes_formulas(): void
    {
        [$user, $owner] = $this->createOwner();
        PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'description' => '=HYPERLINK("example")', 'status' => ChargeStatus::Partial, 'amount' => 15000, 'paid_amount' => 5000]);
        PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'description' => 'Historial consolidado', 'status' => ChargeStatus::Consolidated]);
        PartnerCharge::factory()->create(['description' => 'Exportación privada ajena']);
        $this->actingAs($user);
        $page = Livewire::test(ListMisCobros::class)->set('activeTab', 'pending')->searchTable('HYPERLINK');
        $csv = TestResponse::fromBaseResponse($page->instance()->exportCsv())->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('15000', $csv);
        $this->assertStringContainsString('10000', $csv);
        $this->assertStringNotContainsString('Historial consolidado', $csv);
        $this->assertStringNotContainsString('Exportación privada ajena', $csv);
        $page->call('exportCsv')->assertFileDownloaded('mis-cobros.csv');
    }

    public function test_existing_detail_preserves_snapshot_and_payment_history(): void
    {
        [$user, $owner] = $this->createOwner();
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'distribution_snapshot' => ['unit' => 'shares', 'base' => '12', 'total_base' => '120', 'quantity' => '12'],
        ]);
        $this->actingAs($user);
        Livewire::test(ViewCobro::class, ['record' => $charge->getRouteKey()])
            ->assertSee('Cálculo Aplicado')->assertSee('Mi base congelada')->assertSee('shares');
    }

    public function test_scope_only_returns_authenticated_owner_charges(): void
    {
        [$user, $owner] = $this->createOwner();
        [, $otherOwner] = $this->createOwner();

        PartnerCharge::factory()->count(2)->create(['propietario_id' => $owner->id]);
        PartnerCharge::factory()->count(3)->create(['propietario_id' => $otherOwner->id]);

        $this->actingAs($user);

        $this->assertSame(2, MisCobrosResource::getEloquentQuery()->count());
    }

    public function test_scope_returns_empty_query_for_user_without_propietario(): void
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $this->actingAs($user);

        $this->assertSame(0, MisCobrosResource::getEloquentQuery()->count());
    }

    public function test_resource_is_read_only_for_owner(): void
    {
        [$user, $owner] = $this->createOwner();
        $charge = PartnerCharge::factory()->create(['propietario_id' => $owner->id]);

        $this->actingAs($user);

        $this->assertFalse(MisCobrosResource::canCreate());
        $this->assertFalse(MisCobrosResource::canEdit($charge));
        $this->assertFalse(MisCobrosResource::canDelete($charge));
    }

    public function test_can_view_only_own_charge(): void
    {
        [$user, $owner] = $this->createOwner();
        [, $otherOwner] = $this->createOwner();

        $ownCharge = PartnerCharge::factory()->create(['propietario_id' => $owner->id]);
        $otherCharge = PartnerCharge::factory()->create(['propietario_id' => $otherOwner->id]);

        $this->actingAs($user);

        $this->assertTrue(MisCobrosResource::canView($ownCharge));
        $this->assertFalse(MisCobrosResource::canView($otherCharge));
    }

    public function test_status_label_translation_is_correct(): void
    {
        $this->assertSame('Pendiente', MisCobrosResource::statusLabel(ChargeStatus::Pending));
        $this->assertSame('Parcial', MisCobrosResource::statusLabel(ChargeStatus::Partial));
        $this->assertSame('Pagado', MisCobrosResource::statusLabel(ChargeStatus::Paid));
        $this->assertSame('Cancelado', MisCobrosResource::statusLabel(ChargeStatus::Cancelled));
    }

    public function test_status_color_mapping_is_correct(): void
    {
        $this->assertSame('danger', MisCobrosResource::statusColor(ChargeStatus::Pending));
        $this->assertSame('warning', MisCobrosResource::statusColor(ChargeStatus::Partial));
        $this->assertSame('success', MisCobrosResource::statusColor(ChargeStatus::Paid));
        $this->assertSame('gray', MisCobrosResource::statusColor(ChargeStatus::Cancelled));
    }

    public function test_calculation_type_label_mapping_is_correct(): void
    {
        $this->assertSame('Partes iguales', MisCobrosResource::calculationTypeLabel(ExpenseDistributionType::EqualByPartner));
        $this->assertSame('Proporcional por hectáreas', MisCobrosResource::calculationTypeLabel(ExpenseDistributionType::ProportionalByHectares));
        $this->assertSame('Manual', MisCobrosResource::calculationTypeLabel(ExpenseDistributionType::Manual));
    }

    public function test_overdue_filter_condition_matches_overdue_unpaid_charges(): void
    {
        [$user, $owner] = $this->createOwner();

        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'status' => ChargeStatus::Pending->value,
            'due_date' => now()->subDay()->toDateString(),
        ]);
        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'status' => ChargeStatus::Paid->value,
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($user);

        $count = MisCobrosResource::getEloquentQuery()
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereIn('status', [ChargeStatus::Pending->value, ChargeStatus::Partial->value])
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_next_month_filter_condition_matches_next_month_charges_only(): void
    {
        [$user, $owner] = $this->createOwner();

        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'due_date' => now()->addMonthNoOverflow()->startOfMonth()->addDays(1)->toDateString(),
        ]);
        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'due_date' => now()->addMonthsNoOverflow(2)->startOfMonth()->toDateString(),
        ]);

        $this->actingAs($user);

        $count = MisCobrosResource::getEloquentQuery()
            ->whereBetween('due_date', [now()->addMonthNoOverflow()->startOfMonth()->toDateString(), now()->addMonthNoOverflow()->endOfMonth()->toDateString()])
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_table_query_can_eager_load_expected_relationships(): void
    {
        [$user, $owner] = $this->createOwner();
        PartnerCharge::factory()->create(['propietario_id' => $owner->id]);

        $this->actingAs($user);

        $record = MisCobrosResource::getEloquentQuery()
            ->with(['expense.category', 'allocations.payment'])
            ->firstOrFail();

        $this->assertTrue($record->relationLoaded('expense'));
    }

    public function test_only_current_owner_records_are_returned_when_mixed_statuses_exist(): void
    {
        [$user, $owner] = $this->createOwner();
        [, $otherOwner] = $this->createOwner();

        PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'status' => ChargeStatus::Pending->value]);
        PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'status' => ChargeStatus::Paid->value]);
        PartnerCharge::factory()->create(['propietario_id' => $otherOwner->id, 'status' => ChargeStatus::Pending->value]);

        $this->actingAs($user);

        $this->assertSame(2, MisCobrosResource::getEloquentQuery()->count());
    }

    public function test_next_month_and_overdue_conditions_can_be_combined_safely(): void
    {
        [$user, $owner] = $this->createOwner();

        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'status' => ChargeStatus::Pending->value,
            'due_date' => now()->subDays(2)->toDateString(),
        ]);
        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'status' => ChargeStatus::Partial->value,
            'due_date' => now()->addMonthNoOverflow()->startOfMonth()->addDays(4)->toDateString(),
        ]);

        $this->actingAs($user);

        $overdueCount = MisCobrosResource::getEloquentQuery()
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereIn('status', [ChargeStatus::Pending->value, ChargeStatus::Partial->value])
            ->count();

        $nextMonthCount = MisCobrosResource::getEloquentQuery()
            ->whereBetween('due_date', [now()->addMonthNoOverflow()->startOfMonth()->toDateString(), now()->addMonthNoOverflow()->endOfMonth()->toDateString()])
            ->whereIn('status', [ChargeStatus::Pending->value, ChargeStatus::Partial->value])
            ->count();

        $this->assertSame(1, $overdueCount);
        $this->assertSame(1, $nextMonthCount);
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
}
