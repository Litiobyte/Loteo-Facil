<?php

namespace Tests\Feature;

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Domain\Balances\Services\PartnerBalanceService;
use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Collections\Enums\CollectionMethod;
use App\Domain\Collections\Enums\CollectionStatus;
use App\Domain\Collections\Services\CollectionApplicationService;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Services\ChargeGenerationService;
use App\Domain\Owners\Services\CommunitySettingsService;
use App\Domain\Owners\Services\ShareHoldingService;
use App\Domain\Statements\Services\PartnerStatementService;
use App\Filament\Owner\Pages\EstadoDeCuenta;
use App\Models\Collection;
use App\Models\CommunitySetting;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class ShareFinancialIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CommunitySettingsService $settingsService;

    private ShareHoldingService $shareService;

    private ChargeGenerationService $chargeService;

    private CollectionApplicationService $collectionService;

    private PartnerBalanceService $balanceService;

    private PartnerStatementService $statementService;

    private MonthlyClosingService $closingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();

        $this->settingsService = app(CommunitySettingsService::class);
        $this->shareService = app(ShareHoldingService::class);
        $this->chargeService = app(ChargeGenerationService::class);
        $this->collectionService = app(CollectionApplicationService::class);
        $this->balanceService = app(PartnerBalanceService::class);
        $this->statementService = app(PartnerStatementService::class);
        $this->closingService = app(MonthlyClosingService::class);

        $this->enableShareMode();
    }

    private function createAdminUser(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function enableShareMode(): void
    {
        $this->settingsService->update([
            'mode' => 'shares',
            'square_meters_per_share' => '800',
            'share_precision' => 0,
            'authorized_shares' => null,
        ], 'Habilitar modalidad acciones para pruebas de integración', $this->admin);
    }

    private function createOwnerWithShares(string $quantity): Propietario
    {
        $owner = Propietario::factory()->create();
        $this->shareService->acquire(
            $owner,
            $quantity,
            '2026-01-01',
            'acq-'.$owner->id.'-'.uniqid(),
            'Adquisición de acciones QA',
            $this->admin
        );

        return $owner;
    }

    private function createShareExpense(
        float $amount,
        string $dueDate,
        ExpenseDistributionType $type = ExpenseDistributionType::ProportionalByShares,
        string $expenseDate = '2026-01-01'
    ): Expense {
        $category = ExpenseCategory::factory()->create();

        return Expense::factory()->create([
            'expense_category_id' => $category->id,
            'title' => 'Gasto prueba acciones',
            'amount' => $amount,
            'expense_date' => $expenseDate,
            'due_date' => $dueDate,
            'distribution_type' => $type,
            'status' => ExpenseStatus::Registered,
        ]);
    }

    private function createCollection(Propietario $owner, float $amount, string $date): Collection
    {
        return Collection::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => $amount,
            'applied_amount' => 0,
            'unapplied_amount' => $amount,
            'collection_date' => $date,
            'collection_method' => CollectionMethod::Transferencia,
            'status' => CollectionStatus::PendingApplication,
            'created_by' => $this->admin->id,
        ]);
    }

    private function assertGlobalConsistencyIsValid(): void
    {
        $result = $this->closingService->validateGlobalConsistency();

        $this->assertTrue(
            $result['valid'],
            'Inconsistencia global detectada: '.implode(' | ', $result['errors'])
        );
    }

    private function assertOwnerBalanceConsistency(Propietario $owner): void
    {
        $result = $this->balanceService->validateBalanceConsistency($owner);

        $this->assertTrue(
            $result['valid'],
            'Inconsistencia de balances para propietario #'.$owner->id.': '.implode(' | ', $result['errors'])
        );
    }

    public function test_proportional_by_shares_distributes_exactly_by_holdings(): void
    {
        $ownerA = $this->createOwnerWithShares('1');
        $ownerB = $this->createOwnerWithShares('3');

        $expense = $this->createShareExpense(400.00, '2026-01-20');

        $charges = $this->chargeService->generateChargesFromExpense($expense);

        $this->assertCount(2, $charges);
        $this->assertEqualsWithDelta(400.00, $charges->sum('amount'), 0.01);

        $chargeA = $charges->firstWhere('propietario_id', $ownerA->id);
        $chargeB = $charges->firstWhere('propietario_id', $ownerB->id);

        $this->assertNotNull($chargeA);
        $this->assertNotNull($chargeB);
        $this->assertEquals(100.00, (float) $chargeA->amount);
        $this->assertEquals(300.00, (float) $chargeB->amount);
        $this->assertSame(ExpenseDistributionType::ProportionalByShares, $chargeA->calculation_type);
        $this->assertSame(ChargeStatus::Pending, $chargeA->status);

        $this->assertEquals('share', $chargeA->distribution_snapshot['unit'] ?? null);
        $this->assertEqualsWithDelta(1.0, (float) $chargeA->distribution_snapshot['base'], 0.0001);
        $this->assertEqualsWithDelta(4.0, (float) $chargeA->distribution_snapshot['total_base'], 0.0001);

        $expense->refresh();
        $this->assertSame(ExpenseStatus::Distributed, $expense->status);

        $this->assertOwnerBalanceConsistency($ownerA);
        $this->assertOwnerBalanceConsistency($ownerB);
        $this->assertGlobalConsistencyIsValid();
    }

    public function test_partial_payment_then_excess_payment_creates_credit_balance(): void
    {
        $owner = $this->createOwnerWithShares('1');
        $expense = $this->createShareExpense(200.00, '2026-01-20');
        $charge = $this->chargeService->generateChargesFromExpense($expense)->first();

        $firstPayment = $this->createCollection($owner, 50.00, '2026-01-15');

        $this->actingAs($this->admin);
        $this->collectionService->applyCollectionAutomatically($firstPayment);

        $charge->refresh();
        $this->assertSame(ChargeStatus::Partial, $charge->status);
        $this->assertEquals(50.00, (float) $charge->paid_amount);
        $this->assertEquals(150.00, (float) $charge->remaining_amount);
        $this->assertOwnerBalanceConsistency($owner);

        $secondPayment = $this->createCollection($owner, 250.00, '2026-01-15');
        $this->collectionService->applyCollectionAutomatically($secondPayment);

        $charge->refresh();
        $this->assertSame(ChargeStatus::Paid, $charge->status);
        $this->assertEquals(0.00, (float) $charge->remaining_amount);

        $secondPayment->refresh();
        $this->assertEquals(150.00, (float) $secondPayment->applied_amount);
        $this->assertEquals(100.00, (float) $secondPayment->unapplied_amount);
        $this->assertSame(CollectionStatus::PartiallyApplied, $secondPayment->status);

        $summary = $this->balanceService->getBalanceSummary($owner);
        $this->assertEquals(200.00, $summary['total_charges']);
        $this->assertEquals(300.00, $summary['total_collections']);
        $this->assertEquals(200.00, $summary['total_applied']);
        $this->assertEquals(0.00, $summary['pending_balance']);
        $this->assertEquals(100.00, $summary['credit_balance']);
        $this->assertEquals(100.00, $summary['net_balance']);

        $this->assertOwnerBalanceConsistency($owner);
        $this->assertGlobalConsistencyIsValid();
    }

    public function test_overpayment_across_multiple_share_charges_uses_fifo_and_credit(): void
    {
        $owner = $this->createOwnerWithShares('2');

        $expenseOlder = $this->createShareExpense(100.00, '2026-01-10');
        $expenseNewer = $this->createShareExpense(150.00, '2026-01-20');

        $chargeOlder = $this->chargeService->generateChargesFromExpense($expenseOlder)->first();
        $chargeNewer = $this->chargeService->generateChargesFromExpense($expenseNewer)->first();

        $payment = $this->createCollection($owner, 300.00, '2026-01-15');

        $this->actingAs($this->admin);
        $allocations = $this->collectionService->applyCollectionAutomatically($payment);

        $this->assertCount(2, $allocations);
        $this->assertEquals($chargeOlder->id, $allocations[0]->partner_charge_id);
        $this->assertEquals(100.00, (float) $allocations[0]->amount);
        $this->assertEquals($chargeNewer->id, $allocations[1]->partner_charge_id);
        $this->assertEquals(150.00, (float) $allocations[1]->amount);

        $chargeOlder->refresh();
        $chargeNewer->refresh();
        $this->assertSame(ChargeStatus::Paid, $chargeOlder->status);
        $this->assertSame(ChargeStatus::Paid, $chargeNewer->status);

        $payment->refresh();
        $this->assertSame(CollectionStatus::PartiallyApplied, $payment->status);
        $this->assertEquals(250.00, (float) $payment->applied_amount);
        $this->assertEquals(50.00, (float) $payment->unapplied_amount);

        $summary = $this->balanceService->getBalanceSummary($owner);
        $this->assertEquals(0.00, $summary['pending_balance']);
        $this->assertEquals(50.00, $summary['credit_balance']);
        $this->assertEquals(50.00, $summary['net_balance']);

        $this->assertOwnerBalanceConsistency($owner);
        $this->assertGlobalConsistencyIsValid();
    }

    public function test_manual_application_rejects_cross_owner_charge(): void
    {
        $ownerA = $this->createOwnerWithShares('1');
        $ownerB = $this->createOwnerWithShares('1');

        $chargeB = PartnerCharge::factory()->create([
            'propietario_id' => $ownerB->id,
            'amount' => 100.00,
        ]);

        $paymentA = $this->createCollection($ownerA, 200.00, '2026-01-15');

        $this->actingAs($this->admin);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no pertenece al propietario del pago');

        $this->collectionService->applyCollectionManually($paymentA, [
            ['charge_id' => $chargeB->id, 'amount' => 50.00],
        ]);
    }

    public function test_manual_application_rejects_amount_above_charge_remaining(): void
    {
        $owner = $this->createOwnerWithShares('1');

        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => 100.00,
        ]);

        $payment = $this->createCollection($owner, 200.00, '2026-01-15');

        $this->actingAs($this->admin);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('excede su saldo pendiente');

        $this->collectionService->applyCollectionManually($payment, [
            ['charge_id' => $charge->id, 'amount' => 150.00],
        ]);
    }

    public function test_share_distribution_preserves_total_despite_rounding(): void
    {
        $owners = collect();
        for ($i = 0; $i < 3; $i++) {
            $owners->push($this->createOwnerWithShares('1'));
        }

        $expense = $this->createShareExpense(100.00, '2026-01-20');
        $charges = $this->chargeService->generateChargesFromExpense($expense);

        $this->assertCount(3, $charges);
        $this->assertEqualsWithDelta(100.00, $charges->sum('amount'), 0.01);

        $amounts = $charges->pluck('amount')->map(fn ($value): float => (float) $value)->toArray();
        sort($amounts);
        $this->assertEquals([33.33, 33.33, 33.34], $amounts);

        foreach ($charges as $charge) {
            $this->assertEquals('share', $charge->distribution_snapshot['unit']);
            $this->assertEqualsWithDelta(1.0, (float) $charge->distribution_snapshot['base'], 0.0001);
            $this->assertEqualsWithDelta(3.0, (float) $charge->distribution_snapshot['total_base'], 0.0001);
        }

        $this->assertGlobalConsistencyIsValid();
    }

    public function test_distribution_snapshots_remain_frozen_after_settings_change(): void
    {
        $ownerA = $this->createOwnerWithShares('2');

        $expenseBefore = $this->createShareExpense(1000.00, '2026-01-15');
        $chargeBefore = $this->chargeService->generateChargesFromExpense($expenseBefore)->first();

        $this->assertEquals(1000.00, (float) $chargeBefore->amount);
        $this->assertEqualsWithDelta(800.0, (float) $chargeBefore->distribution_snapshot['holdings'][0]['square_meters_per_share'], 0.0001);
        $this->assertEqualsWithDelta(1600.0, (float) $chargeBefore->distribution_snapshot['equivalent_square_meters'], 0.0001);

        $this->settingsService->update([
            'mode' => 'shares',
            'square_meters_per_share' => '1000',
            'share_precision' => 0,
            'authorized_shares' => null,
        ], 'Cambio de metros cuadrados por acción', $this->admin);

        $ownerB = $this->createOwnerWithShares('1');

        $expenseAfter = $this->createShareExpense(300.00, '2026-01-20');
        $chargesAfter = $this->chargeService->generateChargesFromExpense($expenseAfter);

        $chargeA = $chargesAfter->firstWhere('propietario_id', $ownerA->id);
        $chargeB = $chargesAfter->firstWhere('propietario_id', $ownerB->id);

        $this->assertNotNull($chargeA);
        $this->assertNotNull($chargeB);
        $this->assertEquals(200.00, (float) $chargeA->amount);
        $this->assertEquals(100.00, (float) $chargeB->amount);

        $chargeBefore->refresh();
        $this->assertEquals(1000.00, (float) $chargeBefore->amount);
        $this->assertEqualsWithDelta(800.0, (float) $chargeBefore->distribution_snapshot['holdings'][0]['square_meters_per_share'], 0.0001);
        $this->assertEqualsWithDelta(1600.0, (float) $chargeBefore->distribution_snapshot['equivalent_square_meters'], 0.0001);

        $this->assertOwnerBalanceConsistency($ownerA);
        $this->assertOwnerBalanceConsistency($ownerB);
        $this->assertGlobalConsistencyIsValid();
    }

    public function test_monthly_closing_consolidates_share_charges_and_preserves_invariants(): void
    {
        $owner = $this->createOwnerWithShares('1');
        $expense = $this->createShareExpense(100.00, '2026-01-10');

        $period = $this->closingService->closePeriod(2026, 1, $this->admin->id);
        $this->assertSame(AccountingPeriodStatus::Closed, $period->status);

        $originalCharge = PartnerCharge::query()
            ->where('propietario_id', $owner->id)
            ->where('charge_type', 'distribution')
            ->firstOrFail();

        $janConsolidation = PartnerCharge::query()
            ->where('propietario_id', $owner->id)
            ->where('charge_type', 'balance_consolidation')
            ->where('consolidation_year', 2026)
            ->where('consolidation_month', 1)
            ->firstOrFail();

        $this->assertSame(ChargeStatus::Consolidated, $originalCharge->fresh()->status);
        $this->assertSame(ChargeStatus::Pending, $janConsolidation->status);
        $this->assertEquals(100.00, (float) $janConsolidation->amount);
        $this->assertEquals(100.00, (float) $janConsolidation->consolidationLinks->first()->transferred_amount);

        $this->assertNotNull($period->snapshot);
        $this->assertEqualsWithDelta(200.00, (float) $period->snapshot->total_charges, 0.01);
        $this->assertEqualsWithDelta(100.00, (float) $period->snapshot->pending_balance, 0.01);

        $this->assertOwnerBalanceConsistency($owner);
        $this->assertGlobalConsistencyIsValid();

        // Pago parcial sobre la consolidación de enero, registrado en febrero.
        $partialPayment = $this->createCollection($owner, 30.00, '2026-02-05');
        $this->actingAs($this->admin);
        $this->collectionService->applyCollectionManually($partialPayment, [
            ['charge_id' => $janConsolidation->id, 'amount' => 30.00],
        ]);

        $janConsolidation->refresh();
        $this->assertSame(ChargeStatus::Partial, $janConsolidation->status);
        $this->assertEquals(30.00, (float) $janConsolidation->paid_amount);
        $this->assertEquals(70.00, (float) $janConsolidation->remaining_amount);
        $this->assertGlobalConsistencyIsValid();

        // Cierre de febrero: debe consolidar el residual de enero.
        $febPeriod = $this->closingService->closePeriod(2026, 2, $this->admin->id);
        $this->assertSame(AccountingPeriodStatus::Closed, $febPeriod->status);

        $febConsolidation = PartnerCharge::query()
            ->where('propietario_id', $owner->id)
            ->where('charge_type', 'balance_consolidation')
            ->where('consolidation_year', 2026)
            ->where('consolidation_month', 2)
            ->firstOrFail();

        $this->assertEquals(70.00, (float) $febConsolidation->amount);
        $this->assertSame(ChargeStatus::Pending, $febConsolidation->status);
        $this->assertEquals(70.00, (float) $febConsolidation->consolidationLinks->first()->transferred_amount);

        $pendingBalance = $this->balanceService->getPendingBalance($owner);
        $this->assertEquals(70.00, $pendingBalance);
        $this->assertEquals(100.00, $this->balanceService->getTotalCharges($owner));
        $timeline = app(PartnerStatementService::class)->getMovementTimeline($owner);
        $this->assertEquals(70.00, $timeline->sum('balance_impact'));
        $this->assertEquals(100.00, $this->balanceService->getBalanceSummary($owner)['total_charges']);
        $this->actingAs($owner->user);
        $page = new EstadoDeCuenta;
        $viewData = (new \ReflectionMethod($page, 'getViewData'))->invoke($page);
        $this->assertEquals(70.00, $viewData['debtByCategory']->sum());
        $this->assertEquals(100.00, $viewData['resumen']['cobrado']);
        $this->actingAs($this->admin);

        $this->assertOwnerBalanceConsistency($owner);
        $this->assertGlobalConsistencyIsValid();

        // La reapertura de enero debe bloquearse por dependencias posteriores.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede reabrir');
        $this->closingService->reopenPeriod($period, 'Corrección', $this->admin->id);
    }

    public function test_csv_export_for_closed_period_is_available(): void
    {
        $owner = $this->createOwnerWithShares('1');
        $expense = $this->createShareExpense(100.00, '2026-01-10');

        $period = $this->closingService->closePeriod(2026, 1, $this->admin->id);

        $response = $this->closingService->exportClosedPeriodCsv($period);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertStringContainsString('cierre-', $response->headers->get('Content-Disposition'));
    }

    public function test_reversal_restores_charge_and_payment_states(): void
    {
        $owner = $this->createOwnerWithShares('1');
        $expense = $this->createShareExpense(150.00, '2026-01-20');
        $charge = $this->chargeService->generateChargesFromExpense($expense)->first();

        $payment = $this->createCollection($owner, 100.00, '2026-01-15');

        $this->actingAs($this->admin);
        $allocations = $this->collectionService->applyCollectionAutomatically($payment);

        $charge->refresh();
        $payment->refresh();
        $this->assertSame(ChargeStatus::Partial, $charge->status);
        $this->assertSame(CollectionStatus::FullyApplied, $payment->status);

        $this->collectionService->reverseAllocation($allocations->first());

        $charge->refresh();
        $payment->refresh();

        $this->assertSame(ChargeStatus::Pending, $charge->status);
        $this->assertEquals(0.00, (float) $charge->paid_amount);
        $this->assertEquals(150.00, (float) $charge->remaining_amount);

        $this->assertSame(CollectionStatus::PendingApplication, $payment->status);
        $this->assertEquals(0.00, (float) $payment->applied_amount);
        $this->assertEquals(100.00, (float) $payment->unapplied_amount);

        $this->assertOwnerBalanceConsistency($owner);
        $this->assertGlobalConsistencyIsValid();
    }

    public function test_statement_reconciles_summary_with_detailed_movements(): void
    {
        $owner = $this->createOwnerWithShares('1');
        $expense = $this->createShareExpense(200.00, '2026-01-20');
        $charge = $this->chargeService->generateChargesFromExpense($expense)->first();

        $payment = $this->createCollection($owner, 250.00, '2026-01-15');

        $this->actingAs($this->admin);
        $this->collectionService->applyCollectionAutomatically($payment);

        $statement = $this->statementService->generateStatement($owner);

        $this->assertEquals(
            $this->balanceService->getBalanceSummary($owner),
            $statement['summary']
        );

        $this->assertCount(1, $statement['charges']);
        $this->assertCount(1, $statement['collections']);
        $this->assertCount(1, $statement['allocations']);
        $this->assertCount(3, $statement['timeline']);

        $this->assertEquals(200.00, $statement['charges']->sum('amount'));
        $this->assertEquals(250.00, $statement['collections']->sum('amount'));
        $this->assertEquals(200.00, $statement['allocations']->sum('amount'));

        $this->assertOwnerBalanceConsistency($owner);
        $this->assertGlobalConsistencyIsValid();
    }

    public function test_community_setting_revisions_are_persisted_on_mode_change(): void
    {
        $this->assertDatabaseHas('community_settings', [
            'id' => 1,
            'mode' => 'shares',
        ]);

        $this->assertDatabaseHas('community_setting_revisions', [
            'created_by' => $this->admin->id,
        ]);

        $setting = CommunitySetting::query()->findOrFail(1);
        $this->assertTrue($setting->usesShares());
        $this->assertFalse($setting->usesLots());
    }
}
