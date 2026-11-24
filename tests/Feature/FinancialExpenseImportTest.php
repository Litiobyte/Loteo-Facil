<?php

namespace Tests\Feature;

use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Domain\Balances\Services\PartnerBalanceService;
use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Collections\Services\CollectionApplicationService;
use App\Domain\Expenses\Services\ChargeGenerationService;
use App\Domain\Imports\Services\FinancialExpenseImportService;
use App\Domain\Imports\Services\FinancialImportService;
use App\Domain\Imports\Services\HistoricalOpeningService;
use App\Domain\Statements\Services\PartnerStatementService;
use App\Models\Collection;
use App\Models\DataImport;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\HistoricalAdjustment;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinancialExpenseImportTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Propietario $owner;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('super_admin', 'web');
        $this->actor = User::factory()->create();
        $this->actor->assignRole('super_admin');
        $this->owner = Propietario::factory()->create();
        $this->category = ExpenseCategory::factory()->create();
    }

    private function row(array $overrides = []): array
    {
        return array_replace([
            'source' => 'ledger', 'movement_type' => 'expense', 'external_id' => 'E1',
            'title' => 'Gasto histórico', 'expense_category_id' => $this->category->id,
            'expense_date' => '2020-01-10', 'due_date' => '2020-02-10',
            'amount' => '100,00', 'distribution_type' => 'equal_by_partner', 'mode' => 'register_only',
        ], $overrides);
    }

    private function batch(array $rows): DataImport
    {
        $batch = DataImport::query()->create([
            'type' => 'financial', 'community_setting_id' => 1, 'user_id' => $this->actor->id,
            'original_name' => 'expenses.csv', 'path' => 'unused', 'headers' => [], 'status' => 'preview', 'total' => count($rows),
        ]);
        foreach ($rows as $index => $row) {
            $batch->rows()->create(['number' => $index + 2, 'raw' => [], 'data' => $row]);
        }

        return $batch;
    }

    public function test_register_only_has_no_charges_cash_or_recurrence(): void
    {
        $result = app(FinancialImportService::class)->execute($this->batch([$this->row(['is_recurring' => true])]), $this->actor);
        $this->assertTrue($result['committed']);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('partner_charges', 0);
        $this->assertDatabaseCount('collections', 0);
        $this->assertFalse(Expense::query()->firstOrFail()->is_recurring);
    }

    public function test_explicit_charges_are_exact_idempotent_and_historical(): void
    {
        $data = $this->row(['mode' => 'explicit', 'charges' => [['external_id' => 'C1', 'propietario_id' => $this->owner->id, 'amount' => '75,50']]]);
        $service = app(FinancialImportService::class);
        $this->assertTrue($service->execute($this->batch([$data]), $this->actor)['committed']);
        $charge = PartnerCharge::query()->firstOrFail();
        $this->assertSame('75.50', $charge->amount);
        $this->assertSame('0.00', $charge->paid_amount);
        $this->assertSame('2020-02-10', $charge->imputation_date->toDateString());
        $timeline = app(PartnerStatementService::class)->getMovementTimeline($this->owner, Carbon::parse('2020-02-01'), Carbon::parse('2020-02-29'));
        $this->assertSame('2020-02-10', $timeline->first()['date']->toDateString());
        $this->assertSame('skipped', $service->execute($this->batch([$data]), $this->actor)['rows'][0]['status']);
        $changed = $data;
        $changed['amount'] = '101,00';
        $result = $service->execute($this->batch([$changed]), $this->actor);
        $this->assertFalse($result['committed']);
        $this->assertSame('conflict', $result['rows'][0]['status']);
        $this->assertDatabaseCount('partner_charges', 1);
    }

    public function test_distribution_requires_confirmation_and_preserves_snapshot(): void
    {
        $service = app(FinancialImportService::class);
        $data = $this->row(['mode' => 'distribute']);
        $result = $service->execute($this->batch([$data]), $this->actor);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('cobros explícitos', $result['rows'][0]['message']);
        $lot = Lote::factory()->create(['metros_cuadrados' => 10000]);
        $this->owner->lotes()->attach($lot->id, ['assigned_at' => now(), 'status' => 'active']);
        $data['distribuir_con_datos_actuales'] = 'sí';
        $this->assertTrue($service->execute($this->batch([$data]), $this->actor)['committed']);
        $charge = PartnerCharge::query()->firstOrFail();
        $this->assertNotEmpty($charge->distribution_snapshot);
        $this->assertSame('2020-02-10', $charge->imputation_date->toDateString());
        $this->assertSame('skipped', $service->execute($this->batch([$data]), $this->actor)['rows'][0]['status']);
        $this->assertDatabaseCount('partner_charges', 1);
    }

    public function test_closed_expense_or_charge_period_rejects_batch(): void
    {
        app(MonthlyClosingService::class)->closePeriod(2020, 2);
        $result = app(FinancialImportService::class)->execute($this->batch([
            $this->row(), $this->row(['external_id' => 'E2', 'mode' => 'explicit', 'charges' => [['external_id' => 'C1', 'propietario_id' => $this->owner->id, 'amount' => '100']]]),
        ]), $this->actor);
        $this->assertFalse($result['committed']);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('historical_external_identities', 0);
    }

    public function test_duplicate_charge_identity_rolls_back_batch_and_persists_conflict(): void
    {
        $service = app(FinancialImportService::class);
        $first = $this->row(['mode' => 'explicit', 'charges' => [['external_id' => 'C1', 'propietario_id' => $this->owner->id, 'amount' => '100']]]);
        $this->assertTrue($service->execute($this->batch([$first]), $this->actor)['committed']);
        $batch = $this->batch([$this->row(['external_id' => 'E2']), array_replace($first, ['external_id' => 'E3'])]);
        $result = $service->execute($batch, $this->actor);
        $this->assertFalse($result['committed']);
        $this->assertSame(['failed', 'conflict'], array_column($result['rows'], 'status'));
        $this->assertSame(['failed', 'conflict'], $batch->rows()->orderBy('number')->pluck('result')->all());
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('partner_charges', 1);
        $this->assertDatabaseCount('historical_external_identities', 2);
    }

    public function test_imported_charge_status_follows_partial_and_exact_applications(): void
    {
        $data = $this->row(['mode' => 'explicit', 'charges' => [['external_id' => 'C1', 'propietario_id' => $this->owner->id, 'amount' => '100']]]);
        $this->assertTrue(app(FinancialImportService::class)->execute($this->batch([$data]), $this->actor)['committed']);
        $charge = PartnerCharge::query()->firstOrFail();
        $this->assertSame(ChargeStatus::Pending, $charge->status);
        $payment = Collection::factory()->create(['propietario_id' => $this->owner->id, 'amount' => 40, 'applied_amount' => 0, 'unapplied_amount' => 40]);
        app(CollectionApplicationService::class)->applyCollectionAutomatically($payment);
        $this->assertSame(ChargeStatus::Partial, $charge->fresh()->status);
        $payment = Collection::factory()->create(['propietario_id' => $this->owner->id, 'amount' => 60, 'applied_amount' => 0, 'unapplied_amount' => 60]);
        app(CollectionApplicationService::class)->applyCollectionAutomatically($payment);
        $this->assertSame(ChargeStatus::Paid, $charge->fresh()->status);
    }

    public function test_closed_expense_date_and_excess_charges_are_rejected(): void
    {
        app(MonthlyClosingService::class)->closePeriod(2020, 1);
        $service = app(FinancialImportService::class);
        $result = $service->execute($this->batch([$this->row()]), $this->actor);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('cerrado', $result['rows'][0]['message']);
        $result = $service->execute($this->batch([$this->row(['expense_date' => '2020-03-01', 'due_date' => null, 'mode' => 'explicit', 'charges' => [['external_id' => 'C1', 'propietario_id' => $this->owner->id, 'amount' => '101']]])]), $this->actor);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('exceden', $result['rows'][0]['message']);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_opening_cutoff_prevents_backdated_charges_and_rolls_back_expense(): void
    {
        app(HistoricalOpeningService::class)->create($this->owner, $this->actor, '50.00', '2020-02-10', ['source' => 'opening']);
        $data = $this->row(['mode' => 'explicit', 'charges' => [['external_id' => 'C1', 'propietario_id' => $this->owner->id, 'amount' => '100']]]);
        $result = app(FinancialImportService::class)->execute($this->batch([$data]), $this->actor);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('corte', $result['rows'][0]['message']);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('historical_external_identities', 0);
    }

    public function test_non_super_admin_cannot_import_expenses(): void
    {
        $this->expectException(HttpException::class);
        app(FinancialImportService::class)->execute($this->batch([$this->row()]), User::factory()->create());
    }

    public function test_direct_expense_service_requires_super_admin(): void
    {
        $this->expectException(HttpException::class);
        app(FinancialExpenseImportService::class)->create($this->row(), User::factory()->create(), '1');
    }

    public function test_closing_never_distributes_register_only_expense_over_debit_or_credit_openings(): void
    {
        $creditOwner = Propietario::factory()->create();
        foreach ([$this->owner, $creditOwner] as $owner) {
            $lot = Lote::factory()->create(['metros_cuadrados' => 10000]);
            $owner->lotes()->attach($lot->id, ['assigned_at' => now(), 'status' => 'active']);
        }
        $openings = app(HistoricalOpeningService::class);
        $openings->create($this->owner, $this->actor, '50.00', '2020-03-01', ['source' => 'opening']);
        $openings->create($creditOwner, $this->actor, '-30.00', '2020-03-01', ['source' => 'opening']);
        $this->assertTrue(app(FinancialImportService::class)->execute($this->batch([$this->row()]), $this->actor)['committed']);

        app(MonthlyClosingService::class)->closePeriod(2020, 4, $this->actor->id);
        $expense = Expense::query()->firstOrFail();
        $this->assertTrue($expense->historical_distribution_blocked);
        $this->assertSame(0, $expense->charges()->count());
        $this->assertSame(50.0, app(PartnerBalanceService::class)->getPendingBalance($this->owner));
        $this->assertSame(30.0, app(PartnerBalanceService::class)->getCreditBalance($creditOwner));
        $this->assertSame('2020-03-01', PartnerCharge::query()->where('charge_type', 'opening_balance')->firstOrFail()->imputation_date->toDateString());
        $this->assertSame('2020-03-01', HistoricalAdjustment::query()->where('propietario_id', $creditOwner->id)->firstOrFail()->effective_date->toDateString());
    }

    public function test_later_distribution_requires_confirmation_and_creates_charge_identity(): void
    {
        $lot = Lote::factory()->create(['metros_cuadrados' => 10000]);
        $this->owner->lotes()->attach($lot->id, ['assigned_at' => now(), 'status' => 'active']);
        $data = $this->row();
        $importer = app(FinancialImportService::class);
        $this->assertTrue($importer->execute($this->batch([$data]), $this->actor)['committed']);
        $expense = Expense::query()->firstOrFail();
        try {
            app(ChargeGenerationService::class)->generateChargesFromExpense($expense);
            $this->fail('El generador ordinario debe rechazar el gasto histórico.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('explícita', $exception->getMessage());
        }
        try {
            $expense->update(['amount' => '101.00']);
            $this->fail('El gasto histórico no puede modificarse por la vía ordinaria.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('operación histórica', $exception->getMessage());
        }
        $expense->refresh();
        try {
            app(FinancialExpenseImportService::class)->distributeRegisteredExpense($expense, $this->actor, false);
            $this->fail('Se requiere confirmación.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Confirme', $exception->getMessage());
        }
        app(FinancialExpenseImportService::class)->distributeRegisteredExpense($expense, $this->actor, true);
        $this->assertTrue($expense->fresh()->historical_distribution_blocked);
        $charge = $expense->charges()->firstOrFail();
        $this->assertSame('2020-02-10', $charge->imputation_date->toDateString());
        $this->assertNotEmpty($charge->distribution_snapshot);
        $this->assertDatabaseHas('historical_external_identities', ['movement_type' => 'expense_charge', 'identifiable_id' => $charge->id]);
        $this->assertSame('skipped', $importer->execute($this->batch([$data]), $this->actor)['rows'][0]['status']);
    }

    public function test_later_distribution_rejects_opening_cutoff_and_closed_charge_period(): void
    {
        $lot = Lote::factory()->create(['metros_cuadrados' => 10000]);
        $this->owner->lotes()->attach($lot->id, ['assigned_at' => now(), 'status' => 'active']);
        app(HistoricalOpeningService::class)->create($this->owner, $this->actor, '50.00', '2020-03-01', ['source' => 'opening']);
        $this->assertTrue(app(FinancialImportService::class)->execute($this->batch([$this->row()]), $this->actor)['committed']);
        $expense = Expense::query()->firstOrFail();
        try {
            app(FinancialExpenseImportService::class)->distributeRegisteredExpense($expense, $this->actor, true);
            $this->fail('El corte de apertura debe impedir la distribución.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('corte', $exception->getMessage());
        }
        $this->assertTrue($expense->fresh()->historical_distribution_blocked);
        $this->assertSame(0, $expense->charges()->count());
        app(MonthlyClosingService::class)->closePeriod(2020, 2);
        $this->expectException(DomainException::class);
        app(FinancialExpenseImportService::class)->distributeRegisteredExpense($expense, $this->actor, true);
    }

    public function test_legacy_charge_uses_expense_due_date_for_statement_closing_and_structural_guard(): void
    {
        $closing = app(MonthlyClosingService::class);
        $period = $closing->closePeriod(2020, 1);
        $expense = Expense::factory()->create([
            'expense_date' => '2020-01-01', 'due_date' => '2020-01-10',
        ]);
        $charge = PartnerCharge::factory()->create([
            'expense_id' => $expense->id, 'propietario_id' => $this->owner->id,
            'imputation_date' => null, 'due_date' => '2020-01-10',
            'created_at' => '2020-03-01 12:00:00',
        ]);
        $this->assertSame('2020-01-10', $closing->chargeImputationDate($charge->load('expense'), $period)->toDateString());
        $statement = app(PartnerStatementService::class);
        $january = $statement->getMovementTimeline($this->owner, Carbon::parse('2020-01-01'), Carbon::parse('2020-01-31'));
        $this->assertSame('2020-01-10', $january->first()['date']->toDateString());
        $this->assertCount(0, $statement->getChargesDetail($this->owner, Carbon::parse('2020-03-01'), Carbon::parse('2020-03-31')));
        $charge->description = 'Edición indebida';
        $this->expectException(DomainException::class);
        $charge->save();
    }
}
