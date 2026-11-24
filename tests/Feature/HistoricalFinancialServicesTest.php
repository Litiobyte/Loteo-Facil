<?php

namespace Tests\Feature;

use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Domain\Balances\Services\CashBalanceService;
use App\Domain\Balances\Services\PartnerBalanceService;
use App\Domain\Imports\Enums\HistoricalAdjustmentCategory;
use App\Domain\Imports\Services\HistoricalAdjustmentService;
use App\Domain\Imports\Services\HistoricalOpeningService;
use App\Domain\Statements\Services\PartnerStatementService;
use App\Models\Expense;
use App\Models\HistoricalAdjustment;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HistoricalFinancialServicesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Propietario $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('super_admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
        $this->owner = Propietario::factory()->create();
    }

    public function test_debit_opening_is_debt_without_cash_and_uses_historical_date(): void
    {
        $charge = app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '100.00', '2020-01-31', ['source' => 'ledger']);
        $balance = app(PartnerBalanceService::class);
        $this->assertSame('opening_balance', $charge->charge_type);
        $this->assertNull($charge->expense_id);
        $this->assertSame(100.0, $balance->getPendingBalance($this->owner));
        $this->assertSame(100.0, $balance->getTotalCharges($this->owner));
        $this->assertSame(0.0, app(CashBalanceService::class)->getTotalIncome());
        $timeline = app(PartnerStatementService::class)->getMovementTimeline($this->owner, Carbon::parse('2020-01-01'), Carbon::parse('2020-01-31'));
        $this->assertSame('2020-01-31', $timeline->first()['date']->toDateString());
    }

    public function test_credit_opening_can_be_partially_applied_without_collection(): void
    {
        $adjustment = app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '-100.00', '2020-01-31', ['source' => 'ledger']);
        $charge = PartnerCharge::factory()->create(['propietario_id' => $this->owner->id, 'expense_id' => Expense::factory()->create(['due_date' => null])->id, 'amount' => 80, 'created_at' => '2020-02-01']);
        app(HistoricalAdjustmentService::class)->applyCredit($adjustment, $charge, '30.00', '2020-02-02', $this->admin);
        $balance = app(PartnerBalanceService::class);
        $this->assertSame(50.0, $balance->getPendingBalance($this->owner));
        $this->assertSame(70.0, $balance->getCreditBalance($this->owner));
        $this->assertSame(0.0, $balance->getTotalCollections($this->owner));
        $this->assertSame(0.0, app(CashBalanceService::class)->getTotalIncome());
        $this->assertTrue($balance->validateBalanceConsistency($this->owner)['valid']);
        $this->assertDatabaseHas('historical_adjustment_allocations', ['historical_adjustment_id' => $adjustment->id, 'partner_charge_id' => $charge->id, 'amount' => 30]);
    }

    public function test_zero_creates_no_movements(): void
    {
        $this->assertNull(app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '0.00', '2020-01-01', ['source' => 'ledger']));
        $this->assertDatabaseCount('partner_charges', 0);
        $this->assertDatabaseCount('historical_adjustments', 0);
    }

    public function test_correction_and_forgiveness_reduce_only_the_targeted_debt(): void
    {
        $charge = app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '100.00', '2020-01-01', ['source' => 'ledger']);
        $service = app(HistoricalAdjustmentService::class);
        $service->create($this->owner, $this->admin, HistoricalAdjustmentCategory::DebtCorrection, '20.00', '2020-02-01', 'day', ['source' => 'ledger'], $charge->id);
        $service->create($this->owner, $this->admin, HistoricalAdjustmentCategory::DebtForgiveness, '30.00', '2020-03-01', 'day', ['source' => 'ledger'], $charge->id);
        $service->create($this->owner, $this->admin, HistoricalAdjustmentCategory::UnexplainedDifference, '40.00', '2020-04', 'month', ['source' => 'ledger']);
        $balance = app(PartnerBalanceService::class);
        $this->assertSame(50.0, $balance->getPendingBalance($this->owner));
        $this->assertSame(0.0, $balance->getTotalCollections($this->owner));
        $this->assertTrue($balance->validateBalanceConsistency($this->owner)['valid']);
        $difference = HistoricalAdjustment::query()->where('category', 'unexplained_difference')->firstOrFail();
        $this->assertNull($difference->effective_date);
        $this->assertSame('pending_verification', $difference->evidence_status);
        $timeline = app(PartnerStatementService::class)->getMovementTimeline($this->owner);
        $event = $timeline->first(fn (array $item) => $item['related_type'] === 'HistoricalAdjustment' && $item['related_id'] === $difference->id);
        $this->assertNull($event['date']);
        $this->assertSame('2020-04', $event['date_label']);
    }

    public function test_closed_period_rejects_opening_and_open_period_is_allowed(): void
    {
        app(MonthlyClosingService::class)->closePeriod(2020, 1);
        $this->expectException(DomainException::class);
        app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '100.00', '2020-01-31', ['source' => 'ledger']);
    }

    public function test_excess_credit_application_is_rejected(): void
    {
        $adjustment = app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '-10.00', '2020-01-01', ['source' => 'ledger']);
        $charge = PartnerCharge::factory()->create(['propietario_id' => $this->owner->id, 'expense_id' => Expense::factory()->create(['due_date' => null])->id, 'amount' => 100, 'created_at' => '2020-02-01']);
        $this->expectException(DomainException::class);
        app(HistoricalAdjustmentService::class)->applyCredit($adjustment, $charge, '11.00', '2020-02-02', $this->admin);
    }

    public function test_adjustment_rejects_closed_month_even_with_year_precision(): void
    {
        app(MonthlyClosingService::class)->closePeriod(2020, 6);
        $this->expectException(DomainException::class);
        app(HistoricalAdjustmentService::class)->create($this->owner, $this->admin, HistoricalAdjustmentCategory::UnexplainedDifference, '10', '2020', 'year', ['source' => 'ledger']);
    }

    public function test_non_super_admin_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->expectException(DomainException::class);
        app(HistoricalOpeningService::class)->create($this->owner, $user, '100', '2020-01-01', ['source' => 'ledger']);
    }

    public function test_existing_movements_block_opening(): void
    {
        PartnerCharge::factory()->create(['propietario_id' => $this->owner->id]);
        $this->expectException(DomainException::class);
        app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '100', '2020-01-01', ['source' => 'ledger']);
    }

    public function test_cutoff_is_inclusive_for_later_adjustments(): void
    {
        $charge = app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '100', '2020-01-31', ['source' => 'ledger']);
        $this->expectException(DomainException::class);
        app(HistoricalAdjustmentService::class)->create($this->owner, $this->admin, HistoricalAdjustmentCategory::DebtForgiveness, '10', '2020-01-31', 'day', ['source' => 'ledger'], $charge->id);
    }

    public function test_exact_application_closes_charge_without_excess_credit(): void
    {
        $adjustment = app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '-100', '2020-01-01', ['source' => 'ledger']);
        $charge = PartnerCharge::factory()->create(['propietario_id' => $this->owner->id, 'expense_id' => Expense::factory()->create(['due_date' => null])->id, 'amount' => 100, 'created_at' => '2020-02-01']);
        app(HistoricalAdjustmentService::class)->applyCredit($adjustment, $charge, '100', '2020-02-02', $this->admin);
        $balance = app(PartnerBalanceService::class);
        $this->assertSame(0.0, $balance->getPendingBalance($this->owner));
        $this->assertSame(0.0, $balance->getCreditBalance($this->owner));
        $this->assertTrue($balance->validateBalanceConsistency($this->owner)['valid']);
    }

    public function test_credit_is_included_in_a_later_closing_without_becoming_a_collection(): void
    {
        app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '-100', '2020-01-01', ['source' => 'ledger']);
        $period = app(MonthlyClosingService::class)->closePeriod(2020, 2);
        $this->assertEquals(100, $period->snapshot->credit_balance);
        $this->assertEquals(0, $period->snapshot->total_collections);
    }

    public function test_adjustment_rejects_a_target_in_a_closed_period(): void
    {
        $charge = app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '100', '2020-01-01', ['source' => 'ledger']);
        app(MonthlyClosingService::class)->closePeriod(2020, 1);
        $this->expectException(DomainException::class);
        app(HistoricalAdjustmentService::class)->create($this->owner, $this->admin, HistoricalAdjustmentCategory::DebtForgiveness, '10', '2020-02-01', 'day', ['source' => 'ledger'], $charge->id);
    }

    public function test_unknown_precision_is_rejected_rather_than_inventing_a_date(): void
    {
        $this->expectException(DomainException::class);
        app(HistoricalAdjustmentService::class)->create($this->owner, $this->admin, HistoricalAdjustmentCategory::UnexplainedDifference, '10', '', 'unknown', ['source' => 'ledger']);
    }

    public function test_closed_expense_imputation_period_blocks_non_monetary_applications(): void
    {
        $credit = app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '-100', '2020-01-01', ['source' => 'ledger']);
        $expense = Expense::factory()->create(['expense_date' => '2020-01-01', 'due_date' => '2020-02-29']);
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->owner->id, 'expense_id' => $expense->id, 'amount' => 100,
            'created_at' => '2020-01-02', 'due_date' => '2020-02-29',
        ]);
        app(MonthlyClosingService::class)->closePeriod(2020, 2);
        $this->assertSame('pending', $charge->fresh()->status->value);
        $service = app(HistoricalAdjustmentService::class);

        foreach ([HistoricalAdjustmentCategory::DebtCorrection, HistoricalAdjustmentCategory::DebtForgiveness] as $category) {
            try {
                $service->create($this->owner, $this->admin, $category, '10', '2020-03-01', 'day', ['source' => 'ledger'], $charge->id);
                $this->fail('Una fecha contable cerrada debe bloquear el ajuste.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
        try {
            $service->applyCredit($credit, $charge, '10', '2020-03-01', $this->admin);
            $this->fail('Una fecha contable cerrada debe bloquear la aplicación.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }
        $this->assertSame(0.0, (float) $charge->fresh()->paid_amount);
        $this->assertDatabaseCount('historical_adjustment_allocations', 0);
        $this->assertDatabaseCount('historical_adjustments', 1);
    }

    public function test_closed_opening_cannot_be_structurally_modified(): void
    {
        $charge = app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '100', '2020-01-31', ['source' => 'ledger']);
        app(MonthlyClosingService::class)->closePeriod(2020, 1);
        $otherOwner = Propietario::factory()->create();
        foreach ([['amount' => 200], ['propietario_id' => $otherOwner->id], ['imputation_date' => '2020-02-01']] as $change) {
            try {
                $charge->fresh()->fill($change)->save();
                $this->fail('Los datos estructurales de la apertura no se pueden modificar.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
        $this->assertEquals(100, $charge->fresh()->amount);
        $this->assertSame($this->owner->id, $charge->fresh()->propietario_id);
        $this->assertSame('2020-01-31', $charge->fresh()->imputation_date->toDateString());
    }

    public function test_statement_includes_credit_allocation_in_its_own_period(): void
    {
        $credit = app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '-100', '2020-01-01', ['source' => 'ledger']);
        $expense = Expense::factory()->create(['due_date' => null]);
        $charge = PartnerCharge::factory()->create(['propietario_id' => $this->owner->id, 'expense_id' => $expense->id, 'amount' => 100, 'created_at' => '2020-02-01']);
        app(HistoricalAdjustmentService::class)->applyCredit($credit, $charge, '30', '2020-02-02', $this->admin);
        $timeline = app(PartnerStatementService::class)->getMovementTimeline($this->owner, Carbon::parse('2020-02-02'), Carbon::parse('2020-02-02'));
        $this->assertCount(1, $timeline);
        $this->assertSame('HistoricalAdjustmentAllocation', $timeline->first()['related_type']);
        $this->assertSame($credit->id, $timeline->first()['credit_adjustment_id']);
        $this->assertSame($charge->id, $timeline->first()['charge_id']);
        $this->assertEquals(0, $timeline->first()['balance_impact']);
    }
}
