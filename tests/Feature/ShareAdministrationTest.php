<?php

namespace Tests\Feature;

use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Services\ChargeGenerationService;
use App\Domain\Owners\Services\CommunitySettingsService;
use App\Domain\Owners\Services\ShareHoldingService;
use App\Models\CommunitySetting;
use App\Models\Expense;
use App\Models\Lote;
use App\Models\Propietario;
use App\Models\ShareHolding;
use App\Models\ShareMovement;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ShareAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->configure('shares');
    }

    private function configure(string $mode, string $ratio = '800', int $precision = 4, ?string $authorized = null): void
    {
        app(CommunitySettingsService::class)->update([
            'mode' => $mode, 'square_meters_per_share' => $ratio,
            'share_precision' => $precision, 'authorized_shares' => $authorized,
        ], 'Configuración de prueba', $this->admin);
    }

    private function acquire(Propietario $owner, string $quantity, string $key = 'acquisition'): ShareHolding
    {
        return app(ShareHoldingService::class)->acquire($owner, $quantity, today()->toDateString(), $key, 'Asignación', $this->admin);
    }

    public function test_multiple_acquisitions_accumulate_exact_decimals_and_record_history(): void
    {
        $owner = Propietario::factory()->create();
        $this->acquire($owner, '50.1250');
        $this->acquire($owner, '0.0001', 'second');
        $totals = $owner->shareTotals();
        $this->assertTrue(BigDecimal::of($totals['quantity'])->isEqualTo('50.1251'));
        $this->assertTrue(BigDecimal::of($totals['square_meters'])->isEqualTo('40100.08'));
        $summary = app(ShareHoldingService::class)->totals();
        $this->assertTrue(BigDecimal::of($summary['issued'])->isEqualTo('50.1251'));
        $this->assertTrue(BigDecimal::of($summary['equivalent_square_meters'])->isEqualTo('40100.08'));
        $this->assertDatabaseCount('share_movements', 2);
        $this->assertDatabaseCount('lotes', 0);
        $this->assertSame('4', ShareHolding::displayQuantity('4.0000'));
        $this->assertSame('0.5', ShareHolding::displayQuantity('0.5000'));
        $this->assertSame('50.1251', ShareHolding::displayQuantity('50.1251'));
    }

    public function test_repeated_operations_are_idempotent_but_changed_payload_is_rejected(): void
    {
        $owner = Propietario::factory()->create();
        $first = $this->acquire($owner, '50');
        $this->assertSame($first->id, $this->acquire($owner, '50')->id);
        $this->assertDatabaseCount('share_holdings', 1);
        $this->expectException(DomainException::class);
        $this->acquire($owner, '51');
    }

    public function test_transfer_preserves_totals_ratio_and_both_sides_of_history(): void
    {
        $source = Propietario::factory()->create();
        $recipient = Propietario::factory()->create();
        $holding = $this->acquire($source, '50');
        $this->configure('mixed', '1000');
        $target = app(ShareHoldingService::class)->transfer($holding, $recipient, '12.5', 'transfer', 'Cesión', $this->admin);
        $this->assertSame('37.5000', $holding->fresh()->quantity);
        $this->assertSame('12.5000', $target->quantity);
        $this->assertSame('800.0000', $target->square_meters_per_share);
        $this->assertDatabaseCount('share_movements', 3);
        $this->assertSame($target->id, app(ShareHoldingService::class)->transfer($holding, $recipient, '12.5', 'transfer', 'Cesión', $this->admin)->id);
        $this->assertDatabaseCount('share_holdings', 2);
    }

    public function test_insufficient_transfer_rolls_back(): void
    {
        $holding = $this->acquire(Propietario::factory()->create(), '2');
        try {
            app(ShareHoldingService::class)->transfer($holding, Propietario::factory()->create(), '3', 'too-many', 'Cesión', $this->admin);
            $this->fail('Debió rechazar saldo negativo.');
        } catch (DomainException) {
            $this->assertSame('2.0000', $holding->fresh()->quantity);
            $this->assertDatabaseCount('share_movements', 1);
        }
    }

    public function test_adjustment_cannot_create_negative_holdings(): void
    {
        $holding = $this->acquire(Propietario::factory()->create(), '2');
        $this->expectException(ValidationException::class);
        app(ShareHoldingService::class)->adjust($holding, '-1', 'active', 'negative', 'Ajuste', $this->admin);
    }

    public function test_capacity_counts_inactive_holdings_and_precision_is_enforced(): void
    {
        $this->configure('shares', '800', 0, '10');
        $owner = Propietario::factory()->create();
        $holding = $this->acquire($owner, '10');
        app(ShareHoldingService::class)->adjust($holding, '10', 'inactive', 'deactivate', 'Suspensión', $this->admin);
        $this->expectException(DomainException::class);
        $this->acquire($owner, '1', 'overflow');
    }

    public function test_fractional_quantity_requires_community_precision(): void
    {
        $this->configure('shares', '800', 0);
        $this->expectException(DomainException::class);
        $this->acquire(Propietario::factory()->create(), '0.5');
    }

    public function test_equivalence_changes_only_apply_to_future_acquisitions(): void
    {
        $owner = Propietario::factory()->create();
        $old = $this->acquire($owner, '10');
        $this->configure('shares', '1000');
        $new = $this->acquire($owner, '10', 'new');
        $this->assertSame('800.0000', $old->fresh()->square_meters_per_share);
        $this->assertSame('1000.0000', $new->square_meters_per_share);
        $this->assertDatabaseCount('community_setting_revisions', 2);
    }

    public function test_proportional_distributions_without_lots_are_exact_and_auditable(): void
    {
        foreach (['50', '25', '25'] as $index => $quantity) {
            $this->acquire(Propietario::factory()->create(), $quantity, 'owner-'.$index);
        }
        foreach ([ExpenseDistributionType::ProportionalByShares, ExpenseDistributionType::ProportionalByEquivalentArea] as $type) {
            $expense = Expense::factory()->create(['amount' => '300000', 'distribution_type' => $type]);
            $charges = app(ChargeGenerationService::class)->generateChargesFromExpense($expense);
            $this->assertSame(['150000.00', '75000.00', '75000.00'], $charges->pluck('amount')->all());
            $this->assertSame('50.0000', $charges[0]->distribution_snapshot['quantity']);
            $this->assertSame($expense->id, $charges[0]->distribution_snapshot['expense_id']);
        }
    }

    public function test_equivalent_area_uses_acquisition_equivalences_not_the_latest_configuration(): void
    {
        $this->acquire(Propietario::factory()->create(), '1');
        $this->configure('shares', '1600');
        $this->acquire(Propietario::factory()->create(), '1', 'later');
        $expense = Expense::factory()->create(['amount' => '300', 'distribution_type' => ExpenseDistributionType::ProportionalByEquivalentArea]);
        $charges = app(ChargeGenerationService::class)->generateChargesFromExpense($expense);
        $this->assertSame(['100.00', '200.00'], $charges->pluck('amount')->all());
        $this->assertSame('2400.00000000', $charges[0]->distribution_snapshot['total_base']);
    }

    public function test_configuration_cannot_reduce_precision_below_existing_fractional_holdings(): void
    {
        $this->acquire(Propietario::factory()->create(), '1.5');
        $this->expectException(DomainException::class);
        $this->configure('shares', '800', 0);
    }

    public function test_migration_is_reversible_on_sqlite(): void
    {
        $migration = require database_path('migrations/2026_11_17_142641_create_community_share_administration_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('share_holdings'));
        $migration->up();
        $this->assertSame('lots', CommunitySetting::current()->mode);
    }

    public function test_equal_distribution_includes_registered_owners_without_holdings_or_lots_and_rounds_deterministically(): void
    {
        Propietario::factory()->count(3)->create();
        $expense = Expense::factory()->create(['amount' => '100', 'distribution_type' => ExpenseDistributionType::EqualByPartner]);
        $charges = app(ChargeGenerationService::class)->generateChargesFromExpense($expense);
        $this->assertSame(['33.34', '33.33', '33.33'], $charges->pluck('amount')->all());
        $this->assertSame('3', $charges[0]->distribution_snapshot['total_base']);
    }

    public function test_tiny_distribution_never_creates_negative_or_zero_charges(): void
    {
        foreach (range(1, 5) as $index) {
            $this->acquire(Propietario::factory()->create(), '1', 'owner-'.$index);
        }
        $expense = Expense::factory()->create(['amount' => '0.02', 'distribution_type' => ExpenseDistributionType::ProportionalByShares]);
        $charges = app(ChargeGenerationService::class)->generateChargesFromExpense($expense);
        $this->assertSame(['0.01', '0.01'], $charges->pluck('amount')->all());
    }

    public function test_inactive_holdings_are_excluded_and_empty_base_is_rejected(): void
    {
        $holding = $this->acquire(Propietario::factory()->create(), '10');
        app(ShareHoldingService::class)->adjust($holding, '10', 'inactive', 'disable', 'Suspensión', $this->admin);
        $expense = Expense::factory()->create(['distribution_type' => ExpenseDistributionType::ProportionalByShares]);
        $this->expectException(DomainException::class);
        app(ChargeGenerationService::class)->generateChargesFromExpense($expense);
    }

    public function test_mixed_distribution_uses_only_selected_base_and_transitions_preserve_history(): void
    {
        $owner = Propietario::factory()->create();
        $holding = $this->acquire($owner, '10');
        $this->configure('mixed');
        $lotOwner = Propietario::factory()->create();
        $lot = Lote::factory()->create(['metros_cuadrados' => 5000]);
        $lotOwner->lotes()->attach($lot, ['status' => 'active', 'assigned_at' => today()]);
        $expense = Expense::factory()->create(['amount' => '100', 'distribution_type' => ExpenseDistributionType::ProportionalByShares]);
        $charge = app(ChargeGenerationService::class)->generateChargesFromExpense($expense)->sole();
        $snapshot = $charge->distribution_snapshot;
        app(ShareHoldingService::class)->adjust($holding, '20', 'active', 'adjust', 'Rectificación', $this->admin);
        $this->configure('lots');
        $this->assertSame('100.00', $charge->fresh()->amount);
        $this->assertSame($snapshot, $charge->fresh()->distribution_snapshot);
        $this->assertDatabaseCount('share_holdings', 1);
        $this->assertDatabaseCount('lotes', 1);
        $this->configure('mixed');
        $this->assertSame('mixed', CommunitySetting::current()->mode);
        $this->assertSame(4, DB::table('community_setting_revisions')->count());
    }

    public function test_duplicate_charge_generation_does_not_recalculate(): void
    {
        $this->acquire(Propietario::factory()->create(), '10');
        $expense = Expense::factory()->create(['distribution_type' => ExpenseDistributionType::ProportionalByShares]);
        app(ChargeGenerationService::class)->generateChargesFromExpense($expense);
        $this->expectException(DomainException::class);
        app(ChargeGenerationService::class)->generateChargesFromExpense($expense);
    }

    public function test_transition_rejects_undistributed_expenses_that_would_be_disabled(): void
    {
        Expense::factory()->create(['distribution_type' => ExpenseDistributionType::ProportionalByShares]);
        $revisions = DB::table('community_setting_revisions')->count();
        try {
            $this->configure('lots');
            $this->fail('Debe resolver primero los gastos pendientes.');
        } catch (DomainException) {
            $this->assertSame('shares', CommunitySetting::current()->mode);
            $this->assertSame($revisions, DB::table('community_setting_revisions')->count());
        }
    }

    public function test_idempotency_is_independent_of_database_json_key_order(): void
    {
        $owner = Propietario::factory()->create();
        $holding = $this->acquire($owner, '50');
        $movement = ShareMovement::query()->sole();
        $context = $movement->context;
        $context['request'] = array_reverse($context['request'], true);
        DB::table('share_movements')->where('id', $movement->id)->update(['context' => json_encode($context)]);
        $this->assertSame($holding->id, $this->acquire($owner, '50')->id);
        $this->assertDatabaseCount('share_movements', 1);
    }

    public function test_owner_cannot_execute_administrative_services(): void
    {
        $owner = Propietario::factory()->create();
        $this->expectException(HttpException::class);
        app(ShareHoldingService::class)->acquire($owner, '10', today()->toDateString(), 'forbidden', 'Asignación', $owner->user);
    }

    public function test_holdings_and_history_cannot_be_deleted(): void
    {
        $holding = $this->acquire(Propietario::factory()->create(), '10');
        try {
            $holding->delete();
            $this->fail('Eliminación no permitida.');
        } catch (DomainException) {
            $this->assertDatabaseCount('share_holdings', 1);
        }
        $this->expectException(DomainException::class);
        ShareMovement::query()->firstOrFail()->delete();
    }
}
