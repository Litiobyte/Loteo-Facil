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
use App\Domain\Expenses\Services\RecurringExpenseService;
use App\Models\Collection;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonthlyClosingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private MonthlyClosingService $closingService;

    private CollectionApplicationService $applicationService;

    private Propietario $owner;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-01-10 12:00:00');

        $this->closingService = app(MonthlyClosingService::class);
        $this->applicationService = app(CollectionApplicationService::class);
        $this->user = User::factory()->create();
        $this->owner = Propietario::factory()->create();
        $lote = Lote::factory()->create();
        $this->owner->lotes()->attach($lote, ['assigned_at' => now(), 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Flujo integral:
     * - Gasto recurrente de 3 meses.
     * - Cierre mes 1: distribuye y consolida el cobro de enero.
     * - Pago parcial sobre la consolidación de enero.
     * - Cierre mes 2: distribuye febrero y consolida el residual de enero + febrero.
     * - Pago sobre la consolidación de febrero.
     * - Reapertura de febrero bloqueada por dependencias.
     */
    public function test_recurring_expense_full_closing_flow_with_invariants(): void
    {
        $head = $this->createThreeMonthRecurringExpense();
        $children = Expense::query()->where('parent_expense_id', $head->id)->orderBy('expense_date')->get();
        // Cabeza (enero) + 2 hijos (febrero, marzo) = 3 meses.
        $this->assertCount(2, $children);

        // --- Cierre mes 1 (enero) ---
        $janPeriod = $this->closingService->closePeriod(2026, 1, $this->user->id);
        $this->assertSame(AccountingPeriodStatus::Closed, $janPeriod->status);

        $janExpense = $head;
        $this->assertSame(ExpenseStatus::Distributed, $janExpense->fresh()->status);

        $janOriginalCharge = PartnerCharge::query()
            ->where('expense_id', $janExpense->id)
            ->where('charge_type', 'distribution')
            ->firstOrFail();
        $janConsolidated = PartnerCharge::query()
            ->where('charge_type', 'balance_consolidation')
            ->where('consolidation_year', 2026)
            ->where('consolidation_month', 1)
            ->firstOrFail();

        $this->assertSame(ChargeStatus::Consolidated, $janOriginalCharge->fresh()->status);
        $this->assertSame(ChargeStatus::Pending, $janConsolidated->status);
        $this->assertEquals(300.0, (float) $janConsolidated->amount);
        $this->assertStringContainsString('Saldo enero 2026', $janConsolidated->description);
        $this->assertEquals(300.0, (float) $janConsolidated->consolidationLinks->first()->transferred_amount);

        // Snapshot: cobro original + consolidación = 600
        $this->assertNotNull($janPeriod->snapshot);
        $this->assertEquals(600.0, (float) $janPeriod->snapshot->total_charges);
        $this->assertEquals(300.0, (float) $janPeriod->snapshot->pending_balance);

        $this->assertOwnerChargeInvariant($this->owner);
        $this->assertGlobalConsistencyIsValid();

        // --- Pago parcial a la consolidación de enero (registrado en febrero) ---
        Carbon::setTestNow('2026-02-05 12:00:00');
        $partialPayment = Collection::query()->create([
            'propietario_id' => $this->owner->id,
            'amount' => 100.0,
            'applied_amount' => 0,
            'unapplied_amount' => 100.0,
            'collection_date' => '2026-02-05',
            'collection_method' => CollectionMethod::Transferencia->value,
            'status' => CollectionStatus::PendingApplication->value,
            'created_by' => $this->user->id,
        ]);
        $this->actingAs($this->user);
        $this->applicationService->applyCollectionManually($partialPayment, [
            ['charge_id' => $janConsolidated->id, 'amount' => 100.0],
        ]);

        $janConsolidated->refresh();
        $this->assertSame(ChargeStatus::Partial, $janConsolidated->status);
        $this->assertEquals(100.0, (float) $janConsolidated->paid_amount);
        $this->assertEquals(200.0, (float) $janConsolidated->remaining_amount);
        $this->assertOwnerChargeInvariant($this->owner);
        $this->assertPaymentAllocationInvariant($partialPayment);

        // --- Cierre mes 2 (febrero) ---
        $febPeriod = $this->closingService->closePeriod(2026, 2, $this->user->id);
        $this->assertSame(AccountingPeriodStatus::Closed, $febPeriod->status);

        $febExpense = $children->first();
        $this->assertSame(ExpenseStatus::Distributed, $febExpense->fresh()->status);

        $febConsolidated = PartnerCharge::query()
            ->where('charge_type', 'balance_consolidation')
            ->where('consolidation_year', 2026)
            ->where('consolidation_month', 2)
            ->firstOrFail();

        // Consolidación de febrero = residual enero (200) + febrero (300)
        $this->assertEquals(500.0, (float) $febConsolidated->amount);
        $this->assertStringContainsString('Saldo febrero 2026', $febConsolidated->description);
        $this->assertSame(ChargeStatus::Pending, $febConsolidated->status);

        $this->assertEquals(2, $febConsolidated->consolidationLinks()->count());
        $this->assertEquals(200.0, (float) $febConsolidated->consolidationLinks()
            ->where('source_charge_id', $janConsolidated->id)
            ->first()->transferred_amount);
        $this->assertEquals(300.0, (float) $febConsolidated->consolidationLinks()
            ->where('source_charge_id', '!=', $janConsolidated->id)
            ->first()->transferred_amount);

        // El saldo pendiente debe ser solo la consolidación de febrero (la de enero ya está consolidada)
        $pendingBalance = app(PartnerBalanceService::class)->getPendingBalance($this->owner);
        $this->assertEquals(500.0, $pendingBalance);

        $this->assertOwnerChargeInvariant($this->owner);
        $this->assertGlobalConsistencyIsValid();

        // --- Pago sobre la consolidación de febrero ---
        // Se registra en marzo para no caer en el período cerrado de febrero.
        Carbon::setTestNow('2026-03-05 12:00:00');
        $fullPayment = Collection::query()->create([
            'propietario_id' => $this->owner->id,
            'amount' => 150.0,
            'applied_amount' => 0,
            'unapplied_amount' => 150.0,
            'collection_date' => '2026-03-05',
            'collection_method' => CollectionMethod::Transferencia->value,
            'status' => CollectionStatus::PendingApplication->value,
            'created_by' => $this->user->id,
        ]);
        $this->applicationService->applyCollectionManually($fullPayment, [
            ['charge_id' => $febConsolidated->id, 'amount' => 150.0],
        ]);

        $febConsolidated->refresh();
        $this->assertSame(ChargeStatus::Partial, $febConsolidated->status);
        $this->assertEquals(150.0, (float) $febConsolidated->paid_amount);
        $this->assertEquals(350.0, (float) $febConsolidated->remaining_amount);
        $this->assertOwnerChargeInvariant($this->owner);
        $this->assertPaymentAllocationInvariant($fullPayment);

        // --- Reapertura de febrero bloqueada ---
        try {
            $this->closingService->reopenPeriod($febPeriod, 'Corrección', $this->user->id);
            $this->fail('La reapertura de febrero debía bloquearse por pagos sobre la consolidación.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('No se puede reabrir', $exception->getMessage());
        }

        // --- Reapertura de enero bloqueada por consolidación posterior ---
        // Nota: la consolidación de enero es origen de la de febrero, por lo tanto
        // la reapertura de enero también queda bloqueada por dependencias posteriores.
        try {
            $this->closingService->reopenPeriod($janPeriod, 'Corrección', $this->user->id);
            $this->fail('La reapertura de enero debía bloquearse por consolidación posterior.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('No se puede reabrir', $exception->getMessage());
        }
    }

    /**
     * Cuando un período cerrado no tiene dependencias posteriores, debe poder reabrirse.
     */
    public function test_can_reopen_closed_period_without_dependencies(): void
    {
        $charge = PartnerCharge::query()->create([
            'propietario_id' => $this->owner->id,
            'expense_id' => Expense::query()->create([
                'expense_category_id' => ExpenseCategory::factory()->create()->id,
                'title' => 'Gasto base',
                'expense_date' => '2026-01-01',
                'due_date' => '2026-01-15',
                'distribution_type' => ExpenseDistributionType::EqualByPartner,
                'amount' => 100.0,
                'status' => ExpenseStatus::Distributed,
                'created_by' => $this->user->id,
            ])->id,
            'charge_type' => 'distribution',
            'amount' => 100.0,
            'paid_amount' => 0,
            'remaining_amount' => 100.0,
            'status' => ChargeStatus::Pending->value,
            'due_date' => '2026-01-15',
            'calculation_type' => ExpenseDistributionType::EqualByPartner,
        ]);

        $period = $this->closingService->closePeriod(2026, 1, $this->user->id);
        $this->assertSame(AccountingPeriodStatus::Closed, $period->status);

        $consolidated = PartnerCharge::query()
            ->where('charge_type', 'balance_consolidation')
            ->where('consolidation_year', 2026)
            ->where('consolidation_month', 1)
            ->firstOrFail();

        $this->assertEquals(100.0, (float) $consolidated->amount);

        // No se registran pagos ni consolidaciones posteriores: la reapertura debe permitirse.
        $reopened = $this->closingService->reopenPeriod($period, 'Ajuste inicial', $this->user->id);
        $this->assertSame(AccountingPeriodStatus::Open, $reopened->status);
        $this->assertNotNull($reopened->reopened_at);
        $this->assertSame('Ajuste inicial', $reopened->reopen_reason);
    }

    /**
     * Recerrar un período previamente reabierto debe rechazarse si aparecen nuevos orígenes.
     */
    public function test_reclosing_with_new_origins_rejects_period(): void
    {
        // Cobro inicial para que el primer cierre genere una consolidación.
        PartnerCharge::query()->create([
            'propietario_id' => $this->owner->id,
            'expense_id' => Expense::query()->create([
                'expense_category_id' => ExpenseCategory::factory()->create()->id,
                'title' => 'Gasto base',
                'expense_date' => '2026-01-01',
                'due_date' => '2026-01-15',
                'distribution_type' => ExpenseDistributionType::EqualByPartner,
                'amount' => 100.0,
                'status' => ExpenseStatus::Distributed,
                'created_by' => $this->user->id,
            ])->id,
            'charge_type' => 'distribution',
            'amount' => 100.0,
            'paid_amount' => 0,
            'remaining_amount' => 100.0,
            'status' => ChargeStatus::Pending->value,
            'due_date' => '2026-01-15',
            'calculation_type' => ExpenseDistributionType::EqualByPartner,
        ]);

        $period = $this->closingService->closePeriod(2026, 1, $this->user->id);
        $this->assertSame(AccountingPeriodStatus::Closed, $period->status);
        $this->assertSame(1, PartnerCharge::query()->where('charge_type', 'balance_consolidation')->count());

        $this->closingService->reopenPeriod($period, 'Corrección', $this->user->id);

        // Nuevo gasto vencido que generará un nuevo origen de consolidación al recerrar.
        $newExpense = Expense::query()->create([
            'expense_category_id' => ExpenseCategory::factory()->create()->id,
            'title' => 'Nuevo gasto posterior',
            'expense_date' => '2026-01-01',
            'due_date' => '2026-01-20',
            'distribution_type' => ExpenseDistributionType::EqualByPartner,
            'amount' => 150.0,
            'status' => ExpenseStatus::Registered,
            'created_by' => $this->user->id,
        ]);

        $this->assertSame(AccountingPeriodStatus::Open, $period->fresh()->status);

        try {
            $this->closingService->closePeriod(2026, 1, $this->user->id);
            $this->fail('El recierre debía rechazarse por nuevos orígenes de consolidación.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Ya existe una consolidación', $exception->getMessage());
            $this->assertStringContainsString('nuevos cobros', $exception->getMessage());
        }

        $this->assertSame(ExpenseStatus::Registered, $newExpense->fresh()->status);
        $this->assertSame(AccountingPeriodStatus::Open, $period->fresh()->status);
    }

    /**
     * Un gasto con due_date igual al fin del período no se procesa ni consolida.
     */
    public function test_expense_due_date_equal_to_period_end_is_excluded(): void
    {
        $exactCutoff = Expense::query()->create([
            'expense_category_id' => ExpenseCategory::factory()->create()->id,
            'title' => 'Gasto con vencimiento en fin de período',
            'expense_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'distribution_type' => ExpenseDistributionType::EqualByPartner,
            'amount' => 100.0,
            'status' => ExpenseStatus::Registered,
            'created_by' => $this->user->id,
        ]);

        $period = $this->closingService->closePeriod(2026, 1, $this->user->id);

        $this->assertSame(ExpenseStatus::Registered, $exactCutoff->fresh()->status);
        $excludedIds = collect($period->snapshot->metadata['excluded_not_overdue'])->pluck('id')->all();
        $this->assertContains($exactCutoff->id, $excludedIds);
        $this->assertDatabaseCount('partner_charges', 0);
    }

    private function createThreeMonthRecurringExpense(): Expense
    {
        return app(RecurringExpenseService::class)->createRecurringSeries([
            'expense_category_id' => ExpenseCategory::factory()->create()->id,
            'title' => 'Gasto recurrente QA',
            'amount' => 300.0,
            'expense_date' => '2026-01-01',
            'due_date' => '2026-01-15',
            'distribution_type' => ExpenseDistributionType::EqualByPartner,
            'is_recurring' => true,
            'recurrence_end_date' => '2026-03-31',
            'created_by' => $this->user->id,
        ]);
    }

    private function assertOwnerChargeInvariant(Propietario $owner): void
    {
        $charges = PartnerCharge::query()->where('propietario_id', $owner->id)->get();

        foreach ($charges as $charge) {
            $this->assertEqualsWithDelta(
                (float) $charge->amount,
                (float) $charge->paid_amount + (float) $charge->remaining_amount,
                0.01,
                "Invariante de cobro rota para el cobro #{$charge->id}"
            );
        }
    }

    private function assertGlobalConsistencyIsValid(): void
    {
        $consistency = $this->closingService->validateGlobalConsistency();
        $this->assertTrue(
            $consistency['valid'],
            'Inconsistencia global: '.implode(' | ', $consistency['errors'])
        );
    }

    private function assertPaymentAllocationInvariant(Collection $payment): void
    {
        $payment->refresh();
        $totalAllocations = (float) $payment->allocations()->sum('amount');

        $this->assertEqualsWithDelta(
            (float) $payment->applied_amount,
            $totalAllocations,
            0.01,
            "El monto aplicado del pago #{$payment->id} no coincide con la suma de asignaciones"
        );

        $this->assertEqualsWithDelta(
            (float) $payment->amount,
            (float) $payment->applied_amount + (float) $payment->unapplied_amount,
            0.01,
            "Invariante de pago rota para el pago #{$payment->id}"
        );
    }
}
