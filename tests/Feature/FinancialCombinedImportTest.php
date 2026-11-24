<?php

namespace Tests\Feature;

use App\Domain\Balances\Services\CashBalanceService;
use App\Domain\Balances\Services\HistoricalReconciliationService;
use App\Domain\Balances\Services\PartnerBalanceService;
use App\Domain\Imports\Services\FinancialImportService;
use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Models\HistoricalExternalIdentity;
use App\Models\HistoricalReconciliation;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use App\Domain\Statements\Services\PartnerStatementService;
use App\Models\ExpenseCategory;
use App\Models\DataImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * QA Etapa 7: importación combinada de todas las etapas en orden
 * (apertura → gasto → cobro → recaudación → conciliación), idempotencia
 * de la cadena completa y bloqueo de períodos cerrados.
 */
class FinancialCombinedImportTest extends TestCase
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

    private function runBatch(array $rows): array
    {
        $batch = DataImport::query()->create(['type' => 'financial', 'community_setting_id' => 1, 'user_id' => $this->actor->id,
            'original_name' => 'combined.csv', 'path' => 'unused', 'headers' => [], 'status' => 'preview', 'total' => count($rows)]);
        foreach ($rows as $index => $row) {
            $batch->rows()->create(['number' => $index + 2, 'raw' => [], 'data' => $row]);
        }

        return app(FinancialImportService::class)->execute($batch, $this->actor);
    }

    private function opening(): array
    {
        return ['source' => 'ledger', 'movement_type' => 'initial_opening', 'external_id' => 'OP1',
            'propietario_id' => $this->owner->id, 'cutoff_date' => '2020-01-31',
            'opening_debt' => '100.00', 'opening_credit' => '0', 'information_source' => 'Libro 2020',
            'concept' => 'Apertura', 'reference' => 'folio-1', 'observations' => 'Nota apertura'];
    }

    private function expense(): array
    {
        return ['source' => 'ledger', 'movement_type' => 'expense', 'external_id' => 'E1',
            'title' => 'Gasto combinado', 'expense_category_id' => $this->category->id,
            'expense_date' => '2020-03-01', 'due_date' => '2020-03-10', 'amount' => '80',
            'distribution_type' => 'equal_by_partner', 'mode' => 'register_only'];
    }

    private function charge(): array
    {
        return ['source' => 'ledger', 'movement_type' => 'expense_charge', 'external_id' => 'C1',
            'propietario_id' => $this->owner->id, 'amount' => '80', 'origin_date' => '2020-03-01',
            'due_date' => '2020-03-10', 'mode' => 'linked', 'expense_external_id' => 'E1'];
    }

    private function collection(): array
    {
        return ['source' => 'ledger', 'movement_type' => 'collection', 'external_id' => 'P1',
            'mode' => 'manual', 'propietario_id' => $this->owner->id, 'amount' => '30',
            'collection_date' => '2020-03-15', 'collection_method' => 'transferencia',
            'allocations' => [['charge_external_id' => 'C1', 'amount' => '30']]];
    }

    public function test_combined_chain_builds_balances_cash_and_evidence_without_cash_for_openings(): void
    {
        $this->assertTrue($this->runBatch([$this->opening()])['committed']);
        $this->assertTrue($this->runBatch([$this->expense(), $this->charge()])['committed']);
        $this->assertTrue($this->runBatch([$this->collection()])['committed']);

        $charge = PartnerCharge::query()->where('charge_type', 'distribution')->firstOrFail();
        $this->assertSame('30.00', (string) $charge->paid_amount);

        $result = app(HistoricalReconciliationService::class)->recognizeHistoricalPayment(
            $this->owner,
            $this->actor,
            '50.00',
            '2020-04-02',
            [['charge_id' => $charge->id, 'amount' => '50']],
            'Libro de administración 2021',
            'REC-2021',
            'Pago confirmado sin comprobante.',
        );
        $this->assertSame('recognized', $result['collection']->evidence_status->value);

        // Deuda restante = apertura 100 (el cargo quedó saldado con 30 documentados + 50 reconocidos).
        $this->assertSame(100.0, app(PartnerBalanceService::class)->getPendingBalance($this->owner));
        $this->assertSame(80.0, app(CashBalanceService::class)->getTotalIncome());
        $this->assertTrue(app(PartnerBalanceService::class)->validateBalanceConsistency($this->owner)['valid']);
        $this->assertNotEmpty(app(PartnerStatementService::class)->getMovementTimeline($this->owner));
        $this->assertSame(4, HistoricalExternalIdentity::query()->count());
        $this->assertSame(1, HistoricalReconciliation::query()->where('kind', HistoricalReconciliation::KindPaymentRecognition)->count());
    }

    public function test_combined_chain_reimport_is_fully_idempotent(): void
    {
        $this->runBatch([$this->opening()]);
        $this->runBatch([$this->expense(), $this->charge()]);
        $this->runBatch([$this->collection()]);

        foreach ([
            [$this->opening()],
            [$this->expense(), $this->charge()],
            [$this->collection()],
        ] as $batch) {
            $result = $this->runBatch($batch);
            $this->assertTrue($result['committed']);
            foreach ($result['rows'] as $row) {
                $this->assertSame('skipped', $row['status']);
            }
        }

        $this->assertSame(4, HistoricalExternalIdentity::query()->count());
        $this->assertSame(150.0, app(PartnerBalanceService::class)->getPendingBalance($this->owner));
        $this->assertSame(30.0, app(CashBalanceService::class)->getTotalIncome());
    }

    public function test_closed_period_blocks_new_chain_row_and_keeps_state_intact(): void
    {
        $this->runBatch([$this->opening()]);
        $this->runBatch([$this->expense(), $this->charge()]);
        $this->runBatch([$this->collection()]);

        app(MonthlyClosingService::class)->closePeriod(2020, 4);

        $blocked = $this->collection();
        $blocked['external_id'] = 'P2';
        $blocked['collection_date'] = '2020-04-20';
        $blocked['allocations'] = [['charge_external_id' => 'C1', 'amount' => '10']];

        $result = $this->runBatch([$blocked]);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('cerrado', $result['rows'][0]['message']);

        $this->assertSame(4, HistoricalExternalIdentity::query()->count());
        $this->assertSame(150.0, app(PartnerBalanceService::class)->getPendingBalance($this->owner));
        $this->assertSame(30.0, app(CashBalanceService::class)->getTotalIncome());
        $this->assertSame('30.00', (string) PartnerCharge::query()->where('charge_type', 'distribution')->firstOrFail()->paid_amount);
    }
}
