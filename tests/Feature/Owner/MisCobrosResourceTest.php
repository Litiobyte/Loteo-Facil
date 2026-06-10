<?php

namespace Tests\Feature\Owner;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Filament\Owner\Resources\Cobros\MisCobrosResource;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MisCobrosResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('propietario', 'web');
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
