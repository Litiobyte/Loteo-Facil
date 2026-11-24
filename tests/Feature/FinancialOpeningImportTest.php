<?php

namespace Tests\Feature;

use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Domain\Balances\Services\CashBalanceService;
use App\Domain\Balances\Services\PartnerBalanceService;
use App\Domain\Imports\Services\FinancialImportService;
use App\Domain\Imports\Services\HistoricalAdjustmentService;
use App\Domain\Statements\Services\PartnerStatementService;
use App\Models\Collection;
use App\Models\DataImport;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\HistoricalAdjustment;
use App\Models\HistoricalExternalIdentity;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinancialOpeningImportTest extends TestCase
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

    private function row(array $changes = []): array
    {
        return array_replace(['source' => 'ledger', 'external_id' => '1', 'movement_type' => 'initial_opening',
            'propietario_id' => $this->owner->id, 'cutoff_date' => '2020-01-31',
            'opening_debt' => '100.00', 'opening_credit' => '0', 'information_source' => 'Libro',
            'concept' => 'Apertura', 'reference' => 'folio', 'observations' => 'Nota'], $changes);
    }

    private function runBatch(array $rows): array
    {
        $batch = DataImport::query()->create(['type' => 'financial', 'community_setting_id' => 1, 'user_id' => $this->actor->id,
            'original_name' => 'opening.csv', 'path' => 'unused', 'headers' => [], 'status' => 'preview', 'total' => count($rows)]);
        foreach ($rows as $index => $row) {
            $batch->rows()->create(['number' => $index + 2, 'raw' => [], 'data' => $row]);
        }

        return app(FinancialImportService::class)->execute($batch, $this->actor);
    }

    public function test_debt_has_traceability_balance_and_statement_without_cash(): void
    {
        $this->assertSame('created', $this->runBatch([$this->row()])['rows'][0]['status']);
        $charge = PartnerCharge::query()->firstOrFail();
        $this->assertSame('opening_balance', $charge->charge_type);
        $this->assertSame('Libro', json_decode($charge->calculation_notes, true)['information_source']);
        $this->assertSame(100.0, app(PartnerBalanceService::class)->getPendingBalance($this->owner));
        $this->assertNotEmpty(app(PartnerStatementService::class)->getMovementTimeline($this->owner));
        $this->assertSame(0.0, app(CashBalanceService::class)->getTotalIncome());
    }

    public function test_credit_can_be_applied_without_cash(): void
    {
        $this->assertSame('created', $this->runBatch([$this->row(['opening_debt' => '0', 'opening_credit' => '100'])])['rows'][0]['status']);
        $credit = HistoricalAdjustment::query()->firstOrFail();
        $this->assertSame('Libro', json_decode($credit->observations, true)['information_source']);
        $charge = PartnerCharge::factory()->create(['propietario_id' => $this->owner->id, 'expense_id' => Expense::factory()->create(['due_date' => null])->id, 'amount' => 80, 'created_at' => '2020-02-01']);
        app(HistoricalAdjustmentService::class)->applyCredit($credit, $charge, '30.00', '2020-02-02', $this->actor);
        $this->assertSame(70.0, app(PartnerBalanceService::class)->getCreditBalance($this->owner));
        $this->assertSame(0.0, app(CashBalanceService::class)->getTotalIncome());
    }

    public function test_zero_is_skipped_and_both_sides_and_missing_source_are_rejected(): void
    {
        $this->assertSame('skipped', $this->runBatch([$this->row(['opening_debt' => '0'])])['rows'][0]['status']);
        $this->assertFalse($this->runBatch([$this->row(['opening_credit' => '10'])])['committed']);
        $this->assertFalse($this->runBatch([$this->row(['information_source' => ' '])])['committed']);
        $this->assertDatabaseCount('partner_charges', 0);
        $this->assertDatabaseCount('historical_adjustments', 0);
        $this->assertDatabaseCount('historical_external_identities', 0);
    }

    public function test_reimport_and_overlapping_opening(): void
    {
        $this->assertTrue($this->runBatch([$this->row()])['committed']);
        $this->assertSame('skipped', $this->runBatch([$this->row()])['rows'][0]['status']);
        $this->assertSame('conflict', $this->runBatch([$this->row(['concept' => 'Otro'])])['rows'][0]['status']);
        $result = $this->runBatch([$this->row(['external_id' => '2'])]);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('movimientos existentes', $result['rows'][0]['message']);
        $this->assertDatabaseCount('partner_charges', 1);
    }

    public function test_closed_period_is_detected_during_prevalidation(): void
    {
        app(MonthlyClosingService::class)->closePeriod(2020, 2);
        $other = Propietario::factory()->create();
        $result = $this->runBatch([
            $this->row(),
            $this->row(['external_id' => '2', 'propietario_id' => $other->id, 'cutoff_date' => '2020-02-01']),
        ]);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('cerrado', $result['rows'][1]['message']);
        $this->assertDatabaseCount('partner_charges', 0);
        $this->assertDatabaseCount('historical_external_identities', 0);
    }

    public function test_batch_rolls_back_opening_and_identity_when_later_row_fails_during_execution(): void
    {
        $blocked = Propietario::factory()->create();
        $preexisting = PartnerCharge::factory()->create(['propietario_id' => $blocked->id, 'amount' => 50]);

        $result = $this->runBatch([
            $this->row(),
            $this->row(['external_id' => '2', 'propietario_id' => $blocked->id, 'cutoff_date' => '2020-01-31']),
        ]);

        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('movimientos existentes', $result['rows'][1]['message']);

        // La apertura y la identidad de la primera fila se revirtieron con la transacción.
        $this->assertDatabaseMissing('partner_charges', ['propietario_id' => $this->owner->id]);
        $this->assertDatabaseMissing('historical_external_identities', ['movement_type' => 'initial_opening', 'external_id' => '1']);

        // Los datos preexistentes del segundo propietario permanecen intactos.
        $this->assertSame(1, PartnerCharge::query()->count());
        $this->assertDatabaseHas('partner_charges', ['id' => $preexisting->id]);
        $this->assertSame('50.00', $preexisting->fresh()->amount);
    }

    public function test_later_expense_charges_block_an_earlier_opening_without_duplicating_debt(): void
    {
        $expense = [
            'source' => 'ledger', 'movement_type' => 'expense', 'external_id' => 'E1',
            'title' => 'Gasto', 'expense_category_id' => $this->category->id,
            'expense_date' => '2020-03-01', 'due_date' => '2020-03-10',
            'amount' => '100', 'distribution_type' => 'equal_by_partner', 'mode' => 'explicit',
            'charges' => [['external_id' => 'C1', 'propietario_id' => $this->owner->id, 'amount' => '100']],
        ];
        $this->assertTrue($this->runBatch([$expense])['committed']);

        $result = $this->runBatch([$this->row(['cutoff_date' => '2020-01-31'])]);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('movimientos existentes', $result['rows'][0]['message']);
        $this->assertDatabaseMissing('partner_charges', ['charge_type' => 'opening_balance']);
        $this->assertDatabaseMissing('historical_external_identities', ['movement_type' => 'initial_opening']);
        $this->assertSame(1, PartnerCharge::query()->count());
        $this->assertSame(100.0, app(PartnerBalanceService::class)->getPendingBalance($this->owner));
    }

    public function test_later_charge_import_blocks_an_earlier_opening_without_duplicating_debt(): void
    {
        $expense = [
            'source' => 'ledger', 'movement_type' => 'expense', 'external_id' => 'E1',
            'title' => 'Gasto', 'expense_category_id' => $this->category->id,
            'expense_date' => '2020-03-01', 'due_date' => '2020-03-10',
            'amount' => '100', 'distribution_type' => 'equal_by_partner', 'mode' => 'register_only',
        ];
        $charge = [
            'source' => 'ledger', 'movement_type' => 'expense_charge', 'external_id' => 'C1',
            'propietario_id' => $this->owner->id, 'amount' => '75.50',
            'origin_date' => '2020-03-01', 'due_date' => '2020-03-10',
            'mode' => 'linked', 'expense_external_id' => 'E1',
        ];
        $this->assertTrue($this->runBatch([$expense, $charge])['committed']);

        $result = $this->runBatch([$this->row(['cutoff_date' => '2020-01-31'])]);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('movimientos existentes', $result['rows'][0]['message']);
        $this->assertDatabaseMissing('partner_charges', ['charge_type' => 'opening_balance']);
        $this->assertDatabaseMissing('historical_external_identities', ['movement_type' => 'initial_opening']);
        $this->assertSame(1, PartnerCharge::query()->count());
        $this->assertSame(75.5, app(PartnerBalanceService::class)->getPendingBalance($this->owner));
    }

    public function test_later_collection_blocks_an_earlier_opening_without_duplicating_debt(): void
    {
        $collection = [
            'source' => 'ledger', 'movement_type' => 'collection', 'external_id' => 'P1',
            'mode' => 'unapplied', 'propietario_id' => $this->owner->id, 'amount' => '50',
            'collection_date' => '2020-03-15', 'collection_method' => 'transferencia',
        ];
        $this->assertTrue($this->runBatch([$collection])['committed']);

        $result = $this->runBatch([$this->row(['cutoff_date' => '2020-01-31'])]);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('movimientos existentes', $result['rows'][0]['message']);
        $this->assertDatabaseMissing('partner_charges', ['charge_type' => 'opening_balance']);
        $this->assertDatabaseMissing('historical_external_identities', ['movement_type' => 'initial_opening']);
        $this->assertSame(0, PartnerCharge::query()->count());
        $this->assertSame(50.0, app(PartnerBalanceService::class)->getCreditBalance($this->owner));
        $this->assertSame(50.0, app(CashBalanceService::class)->getTotalIncome());
    }

    public function test_credit_reimport_skips_and_conflicts_without_touching_balance_or_cash(): void
    {
        $other = Propietario::factory()->create();
        $credit = $this->row(['opening_debt' => '0', 'opening_credit' => '100']);

        $this->assertSame('created', $this->runBatch([$credit])['rows'][0]['status']);
        $this->assertSame('skipped', $this->runBatch([$credit])['rows'][0]['status']);

        foreach ([
            ['opening_credit' => '90'],
            ['opening_credit' => '0', 'opening_debt' => '100'],
            ['cutoff_date' => '2020-02-01'],
            ['propietario_id' => $other->id],
            ['concept' => 'Otro concepto'],
            ['reference' => 'otra-ref'],
            ['observations' => 'otra nota'],
        ] as $changes) {
            $result = $this->runBatch([array_replace($credit, $changes)]);
            $this->assertFalse($result['committed']);
            $this->assertSame('conflict', $result['rows'][0]['status']);
        }

        // Una fuente distinta es una identidad nueva: falla por solapamiento, no por conflicto.
        $result = $this->runBatch([array_replace($credit, ['source' => 'otro-libro'])]);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('movimientos existentes', $result['rows'][0]['message']);

        $this->assertSame(100.0, app(PartnerBalanceService::class)->getCreditBalance($this->owner));
        $this->assertSame(0.0, app(CashBalanceService::class)->getTotalIncome());
        $this->assertDatabaseCount('historical_adjustments', 1);
        $this->assertDatabaseCount('historical_external_identities', 1);
    }

    public function test_identity_with_missing_or_unsupported_link_fails_clearly(): void
    {
        $collection = Collection::factory()->create(['propietario_id' => $this->owner->id]);

        HistoricalExternalIdentity::query()->create([
            'community_scope' => '1', 'source' => 'ledger', 'movement_type' => 'initial_opening',
            'external_id' => 'missing', 'identifiable_type' => (new PartnerCharge)->getMorphClass(), 'identifiable_id' => 999999,
        ]);
        HistoricalExternalIdentity::query()->create([
            'community_scope' => '1', 'source' => 'ledger', 'movement_type' => 'initial_opening',
            'external_id' => 'unsupported', 'identifiable_type' => $collection->getMorphClass(), 'identifiable_id' => $collection->id,
        ]);

        foreach (['missing', 'unsupported'] as $externalId) {
            $result = $this->runBatch([$this->row(['external_id' => $externalId])]);
            $this->assertFalse($result['committed']);
            $this->assertSame('conflict', $result['rows'][0]['status']);
            $this->assertStringContainsString('revisión manual', $result['rows'][0]['message']);
        }

        $this->assertDatabaseCount('partner_charges', 0);
        $this->assertDatabaseCount('historical_adjustments', 0);
    }
}
