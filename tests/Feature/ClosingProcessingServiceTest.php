<?php

namespace Tests\Feature;

use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Domain\Balances\Services\PartnerBalanceService;
use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Collections\Services\CollectionApplicationService;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Models\Collection;
use App\Models\Expense;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClosingProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_distributes_only_overdue_and_consolidates_without_double_pending(): void
    {
        $owner = Propietario::factory()->create();
        $owner->lotes()->attach(Lote::factory()->create()->id, ['assigned_at' => now(), 'status' => 'active']);
        $data = ['expense_date' => '2026-10-01', 'distribution_type' => ExpenseDistributionType::EqualByPartner, 'amount' => 100];
        $overdue = Expense::factory()->create($data + ['due_date' => '2026-10-30']);
        $cutoff = Expense::factory()->create($data + ['due_date' => '2026-10-31']);
        $noDue = Expense::factory()->create($data + ['due_date' => null]);

        $period = app(MonthlyClosingService::class)->closePeriod(2026, 10);
        $this->assertSame(ExpenseStatus::Distributed, $overdue->fresh()->status);
        $this->assertSame(ExpenseStatus::Registered, $cutoff->fresh()->status);
        $this->assertContains($noDue->id, $period->snapshot->metadata['excluded_null_due_date']);
        $original = $overdue->charges()->firstOrFail();
        $consolidated = PartnerCharge::query()->where('charge_type', 'balance_consolidation')->firstOrFail();
        $this->assertSame(ChargeStatus::Consolidated, $original->status);
        $this->assertEquals(100, $consolidated->consolidationLinks()->firstOrFail()->transferred_amount);
        $this->assertStringContainsString('octubre', $consolidated->description);
        $this->assertEquals(100, app(PartnerBalanceService::class)->getPendingBalance($owner));
        $this->assertEquals(100, $period->snapshot->pending_balance);
        $this->assertEquals(0, $period->snapshot->overdue_1_30);
        $this->assertEquals(200, $period->snapshot->total_charges);
        $response = app(MonthlyClosingService::class)->exportClosedPeriodCsv($period);
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString('2026-10-31', $csv);
        $this->assertStringContainsString('Cobro #'.$original->id, $csv);
    }

    public function test_reopen_and_reclose_is_idempotent(): void
    {
        $charge = PartnerCharge::factory()->create(['amount' => 100, 'paid_amount' => 25, 'status' => ChargeStatus::Partial, 'due_date' => '2026-10-15']);
        $service = app(MonthlyClosingService::class);
        $period = $service->closePeriod(2026, 10);
        $consolidated = PartnerCharge::query()->where('charge_type', 'balance_consolidation')->firstOrFail();
        $this->assertEquals(75, $consolidated->amount);
        $this->assertEquals(100, $charge->fresh()->amount);
        $this->assertEquals(25, $charge->fresh()->paid_amount);
        $service->reopenPeriod($period, 'Corrección');
        $service->closePeriod(2026, 10);
        $this->assertSame(1, PartnerCharge::query()->where('charge_type', 'balance_consolidation')->count());
        $this->assertSame(1, $consolidated->consolidationLinks()->count());
    }

    public function test_undistributable_expense_rolls_back_the_entire_closing(): void
    {
        $expense = Expense::factory()->create(['title' => 'Sin lotes', 'expense_date' => '2026-10-01', 'due_date' => '2026-10-10', 'distribution_type' => ExpenseDistributionType::EqualByPartner]);
        try {
            app(MonthlyClosingService::class)->closePeriod(2026, 10);
            $this->fail('Se esperaba un error de distribución.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Sin lotes', $exception->getMessage());
        }
        $this->assertSame(ExpenseStatus::Registered, $expense->fresh()->status);
        $this->assertDatabaseCount('partner_charges', 0);
        $this->assertDatabaseCount('accounting_periods', 0);
    }

    public function test_payment_on_consolidation_blocks_reopening_and_source_reversal(): void
    {
        $owner = Propietario::factory()->create();
        $charge = PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'amount' => 100, 'paid_amount' => 0, 'due_date' => '2026-10-15']);
        $payment = Collection::factory()->create(['propietario_id' => $owner->id, 'amount' => 30, 'applied_amount' => 0, 'unapplied_amount' => 30, 'collection_date' => '2026-11-01']);
        $allocations = app(CollectionApplicationService::class)->applyCollectionManually($payment, [['charge_id' => $charge->id, 'amount' => 30]]);
        $service = app(MonthlyClosingService::class);
        $period = $service->closePeriod(2026, 10);
        $consolidated = PartnerCharge::query()->where('charge_type', 'balance_consolidation')->firstOrFail();
        $this->assertEquals(70, $consolidated->amount);
        $this->assertDatabaseHas('collection_allocations', ['id' => $allocations->first()->id, 'partner_charge_id' => $charge->id]);
        try {
            app(CollectionApplicationService::class)->reverseAllocation($allocations->first());
            $this->fail('No debe revertirse una asignación del origen consolidado.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('consolidado', $exception->getMessage());
        }
        $secondPayment = Collection::factory()->create(['propietario_id' => $owner->id, 'amount' => 10, 'applied_amount' => 0, 'unapplied_amount' => 10, 'collection_date' => '2026-11-01']);
        app(CollectionApplicationService::class)->applyCollectionManually($secondPayment, [['charge_id' => $consolidated->id, 'amount' => 10]]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede reabrir');
        $service->reopenPeriod($period, 'Corrección');
    }
}
