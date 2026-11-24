<?php

namespace Tests\Feature;

use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Collections\Services\CollectionApplicationService;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Services\ChargeGenerationService;
use App\Domain\Imports\Services\FinancialChargeImportService;
use App\Domain\Imports\Services\FinancialImportService;
use App\Domain\Imports\Services\HistoricalOpeningService;
use App\Domain\Imports\Services\ImportDefinition;
use App\Domain\Statements\Services\PartnerStatementService;
use App\Models\Collection;
use App\Models\DataImport;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\HistoricalExternalIdentity;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinancialChargeImportTest extends TestCase
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

    private function batch(array $rows): DataImport
    {
        $batch = DataImport::query()->create([
            'type' => 'financial', 'community_setting_id' => 1, 'user_id' => $this->actor->id,
            'original_name' => 'charges.csv', 'path' => 'unused', 'headers' => [], 'status' => 'preview', 'total' => count($rows),
        ]);
        foreach ($rows as $index => $row) {
            $batch->rows()->create(['number' => $index + 2, 'raw' => [], 'data' => $row]);
        }

        return $batch;
    }

    private function charge(array $data = []): array
    {
        return array_replace([
            'source' => 'ledger', 'movement_type' => 'expense_charge', 'external_id' => 'C1',
            'propietario_id' => $this->owner->id, 'amount' => '75,50',
            'origin_date' => '2020-01-10', 'due_date' => '2020-02-10',
            'mode' => 'linked', 'expense_external_id' => 'E1',
        ], $data);
    }

    private function expense(array $data = []): array
    {
        return array_replace([
            'source' => 'ledger', 'movement_type' => 'expense', 'external_id' => 'E1',
            'title' => 'Gasto', 'expense_category_id' => $this->category->id,
            'expense_date' => '2020-01-10', 'due_date' => '2020-02-10',
            'amount' => '100', 'distribution_type' => 'equal_by_partner', 'mode' => 'register_only',
        ], $data);
    }

    private function execute(array $rows): array
    {
        return app(FinancialImportService::class)->execute($this->batch($rows), $this->actor);
    }

    public function test_linked_charge_is_exact_historical_and_idempotent(): void
    {
        $this->assertTrue($this->execute([$this->expense()])['committed']);
        $row = $this->charge(['reported_paid' => '20', 'reported_remaining' => '55,50']);
        $batch = $this->batch([$row]);
        $result = app(FinancialImportService::class)->execute($batch, $this->actor);
        $this->assertTrue($result['committed']);
        $this->assertStringContainsString('Advertencia', $result['rows'][0]['message']);
        $this->assertStringContainsString('Advertencia', $batch->rows()->firstOrFail()->changes['result_message']['after']);
        $charge = PartnerCharge::query()->firstOrFail();
        $this->assertSame('75.50', $charge->amount);
        $this->assertSame(ChargeStatus::Pending, $charge->status);
        $this->assertSame('0.00', $charge->paid_amount);
        $this->assertDatabaseCount('collections', 0);
        $this->assertDatabaseCount('collection_allocations', 0);
        $timeline = app(PartnerStatementService::class)->getMovementTimeline($this->owner, Carbon::parse('2020-02-01'), Carbon::parse('2020-02-29'));
        $this->assertSame('2020-02-10', $timeline->first()['date']->toDateString());
        $this->assertSame('skipped', $this->execute([$row])['rows'][0]['status']);
        $this->assertSame('skipped', $this->execute([$this->expense()])['rows'][0]['status']);
        $this->assertDatabaseCount('partner_charges', 1);
    }

    public function test_existing_stage_two_identity_skips_or_conflicts(): void
    {
        $this->assertTrue($this->execute([$this->expense(['mode' => 'explicit', 'charges' => [
            ['external_id' => 'C1', 'propietario_id' => $this->owner->id, 'amount' => '75,50'],
        ]])])['committed']);
        $this->assertSame('skipped', $this->execute([$this->charge()])['rows'][0]['status']);
        $batch = $this->batch([$this->charge(['amount' => '75,51'])]);
        $result = app(FinancialImportService::class)->execute($batch, $this->actor);
        $this->assertFalse($result['committed']);
        $this->assertSame('conflict', $result['rows'][0]['status']);
        $this->assertSame('conflict', $batch->rows()->firstOrFail()->result);
        $this->assertDatabaseCount('partner_charges', 1);
    }

    public function test_backing_is_historical_and_not_distributed_automatically(): void
    {
        $row = $this->charge(['mode' => 'backing', 'expense_category_id' => $this->category->id, 'title' => 'Respaldo']);
        $this->assertTrue($this->execute([$row])['committed']);
        $expense = Expense::query()->firstOrFail();
        $this->assertTrue($expense->historical_distribution_blocked);
        $this->assertFalse($expense->is_recurring);
        $this->assertSame('register_only', json_decode($expense->notes, true)['mode']);
        $this->assertSame('skipped', $this->execute([$row])['rows'][0]['status']);
        try {
            app(ChargeGenerationService::class)->generateChargesFromExpense($expense);
            $this->fail('El respaldo no admite distribución ordinaria.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('explícita', $exception->getMessage());
        }
        $this->assertDatabaseCount('partner_charges', 1);
    }

    public function test_opening_delegates_and_prevents_overlaps(): void
    {
        $row = $this->charge(['mode' => 'opening', 'due_date' => null, 'expense_external_id' => null]);
        $this->assertTrue($this->execute([$row])['committed']);
        $this->assertSame('opening_balance', PartnerCharge::query()->firstOrFail()->charge_type);
        $this->assertSame('skipped', $this->execute([$row])['rows'][0]['status']);
        $result = $this->execute([$this->charge(['external_id' => 'C2', 'mode' => 'opening', 'due_date' => null, 'expense_external_id' => null])]);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('apertura', $result['rows'][0]['message']);
        $this->assertDatabaseCount('partner_charges', 1);
        $other = Propietario::factory()->create();
        app(HistoricalOpeningService::class)->create($other, $this->actor, '30.00', '2020-01-10', ['source' => 'old']);
        $result = $this->execute([$this->charge(['external_id' => 'C3', 'propietario_id' => $other->id, 'mode' => 'opening', 'due_date' => null, 'expense_external_id' => null])]);
        $this->assertFalse($result['committed']);
        $this->assertDatabaseCount('partner_charges', 2);
    }

    public function test_closed_period_rejects_entire_batch(): void
    {
        $this->assertTrue($this->execute([$this->expense()])['committed']);
        app(MonthlyClosingService::class)->closePeriod(2020, 2);
        $result = $this->execute([$this->charge(['external_id' => 'C2', 'due_date' => null]), $this->charge()]);
        $this->assertFalse($result['committed']);
        $this->assertDatabaseCount('partner_charges', 0);
    }

    public function test_conflict_after_first_row_rolls_back_financial_writes(): void
    {
        $this->assertTrue($this->execute([$this->expense(), $this->charge()])['committed']);
        $other = Propietario::factory()->create();
        $batch = $this->batch([$this->charge(['external_id' => 'C2', 'propietario_id' => $other->id, 'amount' => '24,50']), $this->charge(['amount' => '80'])]);
        $result = app(FinancialImportService::class)->execute($batch, $this->actor);
        $this->assertFalse($result['committed']);
        $this->assertSame(['failed', 'conflict'], array_column($result['rows'], 'status'));
        $this->assertSame(['failed', 'conflict'], $batch->rows()->orderBy('number')->pluck('result')->all());
        $this->assertDatabaseCount('partner_charges', 1);
    }

    public function test_effective_partial_and_full_applications_remain_authoritative(): void
    {
        $this->assertTrue($this->execute([$this->expense(), $this->charge()])['committed']);
        $charge = PartnerCharge::query()->firstOrFail();
        foreach ([['30', ChargeStatus::Partial, '45,50'], ['45,50', ChargeStatus::Paid, '0']] as [$amount, $status, $remaining]) {
            $payment = Collection::factory()->create(['propietario_id' => $this->owner->id, 'amount' => str_replace(',', '.', $amount), 'applied_amount' => 0, 'unapplied_amount' => str_replace(',', '.', $amount)]);
            app(CollectionApplicationService::class)->applyCollectionAutomatically($payment);
            $this->assertSame($status, $charge->fresh()->status);
            $result = $this->execute([$this->charge(['reported_remaining' => $remaining])]);
            $this->assertTrue($result['committed']);
            $this->assertSame('skipped', $result['rows'][0]['status']);
            $this->assertStringNotContainsString('Advertencia', $result['rows'][0]['message']);
        }
    }

    public function test_template_is_available(): void
    {
        $this->assertArrayHasKey('reported_paid', app(ImportDefinition::class)->fields('financial_charges'));
    }

    public function test_direct_service_creation_also_registers_identity_in_transaction(): void
    {
        $data = $this->charge(['mode' => 'opening', 'due_date' => null, 'expense_external_id' => null]);
        $charge = DB::transaction(fn () => app(FinancialChargeImportService::class)->create($data, $this->actor, '1'));
        $this->assertDatabaseHas('historical_external_identities', [
            'movement_type' => 'expense_charge', 'external_id' => 'C1', 'identifiable_id' => $charge->id,
        ]);
    }

    public function test_conflicts_cover_owner_origin_due_date_and_expense(): void
    {
        $this->assertTrue($this->execute([$this->expense(), $this->expense(['external_id' => 'E2']), $this->charge()])['committed']);
        $other = Propietario::factory()->create();
        foreach ([
            ['propietario_id' => $other->id],
            ['origin_date' => '2020-01-11'],
            ['due_date' => '2020-02-11'],
            ['expense_external_id' => 'E2'],
        ] as $changes) {
            $result = $this->execute([$this->charge($changes)]);
            $this->assertFalse($result['committed']);
            $this->assertSame('conflict', $result['rows'][0]['status']);
        }
        $this->assertDatabaseCount('partner_charges', 1);
    }

    public function test_cutoff_rejects_a_linked_charge_at_opening_date(): void
    {
        $this->assertTrue($this->execute([$this->expense()])['committed']);
        app(HistoricalOpeningService::class)->create($this->owner, $this->actor, '30.00', '2020-02-10', ['source' => 'old']);
        $result = $this->execute([$this->charge()]);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('corte', $result['rows'][0]['message']);
        $this->assertDatabaseCount('partner_charges', 1);
    }

    public function test_non_super_admin_cannot_import(): void
    {
        $this->expectException(HttpException::class);
        app(FinancialImportService::class)->execute($this->batch([$this->charge()]), User::factory()->create());
    }

    public function test_unblocked_registered_expense_cannot_be_linked(): void
    {
        $expense = Expense::factory()->create(['expense_date' => '2020-01-10', 'due_date' => '2020-02-10']);
        $result = $this->execute([$this->charge(['expense_external_id' => null, 'expense_id' => $expense->id])]);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('distribución automática', $result['rows'][0]['message']);
        $this->assertDatabaseCount('partner_charges', 0);
    }

    public function test_stage_two_and_three_charges_cannot_exceed_expense_and_batch_rolls_back(): void
    {
        $this->assertTrue($this->execute([
            $this->expense(['mode' => 'explicit', 'charges' => [
                ['external_id' => 'C1', 'propietario_id' => $this->owner->id, 'amount' => '75'],
            ]]),
            $this->expense(['external_id' => 'E2']),
        ])['committed']);
        $other = Propietario::factory()->create();
        $batch = $this->batch([
            $this->charge(['external_id' => 'C2', 'expense_external_id' => 'E2', 'propietario_id' => $other->id, 'amount' => '20']),
            $this->charge(['external_id' => 'C3', 'propietario_id' => $other->id, 'amount' => '75']),
        ]);
        $result = app(FinancialImportService::class)->execute($batch, $this->actor);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('exceden', $result['rows'][1]['message']);
        $this->assertSame(['failed', 'failed'], $batch->rows()->orderBy('number')->pluck('result')->all());
        $this->assertDatabaseCount('partner_charges', 1);
        $this->assertDatabaseMissing('historical_external_identities', ['movement_type' => 'expense_charge', 'external_id' => 'C2']);
        $this->assertDatabaseMissing('historical_external_identities', ['movement_type' => 'expense_charge', 'external_id' => 'C3']);
    }

    public function test_multiple_linked_charges_allow_exact_total_but_not_a_cent_more(): void
    {
        $this->assertTrue($this->execute([$this->expense()])['committed']);
        $second = Propietario::factory()->create();
        $third = Propietario::factory()->create();
        $result = $this->execute([
            $this->charge(['amount' => '40,01']),
            $this->charge(['external_id' => 'C2', 'propietario_id' => $second->id, 'amount' => '59,99']),
        ]);
        $this->assertTrue($result['committed']);
        $result = $this->execute([$this->charge(['external_id' => 'C3', 'propietario_id' => $third->id, 'amount' => '0,01'])]);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('exceden', $result['rows'][0]['message']);
        $this->assertDatabaseCount('partner_charges', 2);
        $this->assertDatabaseMissing('historical_external_identities', ['movement_type' => 'expense_charge', 'external_id' => 'C3']);
    }

    public function test_cancelled_and_consolidated_charges_count_at_their_full_original_amount(): void
    {
        foreach ([ChargeStatus::Cancelled, ChargeStatus::Consolidated] as $status) {
            $expense = Expense::factory()->create([
                'status' => ExpenseStatus::Distributed, 'amount' => '100.00',
                'expense_date' => '2020-01-10', 'due_date' => '2020-02-10',
            ]);
            PartnerCharge::factory()->create([
                'expense_id' => $expense->id, 'amount' => '40.00', 'paid_amount' => '10.00',
                'status' => $status, 'due_date' => '2020-02-10',
            ]);
            $result = $this->execute([$this->charge([
                'external_id' => 'C-'.$status->value, 'expense_external_id' => null,
                'expense_id' => $expense->id, 'amount' => '60,01',
            ])]);
            $this->assertFalse($result['committed']);
            $this->assertStringContainsString('exceden', $result['rows'][0]['message']);
        }
        $this->assertDatabaseCount('partner_charges', 2);
    }

    public function test_cancelled_expense_rejected_by_id_and_external_identity_with_batch_rollback(): void
    {
        $this->assertTrue($this->execute([$this->expense()])['committed']);
        $expense = Expense::factory()->create([
            'status' => ExpenseStatus::Cancelled, 'amount' => '100.00',
            'expense_date' => '2020-01-10', 'due_date' => '2020-02-10',
        ]);
        HistoricalExternalIdentity::query()->create([
            'community_scope' => '1', 'source' => 'ledger', 'movement_type' => 'expense',
            'external_id' => 'E-cancelled', 'identifiable_type' => $expense->getMorphClass(), 'identifiable_id' => $expense->id,
        ]);
        foreach ([
            ['expense_external_id' => null, 'expense_id' => $expense->id],
            ['expense_external_id' => 'E-cancelled', 'expense_id' => null],
        ] as $reference) {
            $batch = $this->batch([
                $this->charge(['external_id' => 'C-good', 'amount' => '20']),
                $this->charge(array_replace(['external_id' => 'C-bad', 'amount' => '20'], $reference)),
            ]);
            $result = app(FinancialImportService::class)->execute($batch, $this->actor);
            $this->assertFalse($result['committed']);
            $this->assertStringContainsString('cancelado', $result['rows'][1]['message']);
            $this->assertSame(['failed', 'failed'], $batch->rows()->orderBy('number')->pluck('result')->all());
            $this->assertDatabaseCount('partner_charges', 0);
            $this->assertDatabaseMissing('historical_external_identities', ['movement_type' => 'expense_charge', 'external_id' => 'C-good']);
            $this->assertDatabaseMissing('historical_external_identities', ['movement_type' => 'expense_charge', 'external_id' => 'C-bad']);
        }
    }

    public function test_reimporting_backing_with_changed_category_is_a_conflict(): void
    {
        $row = $this->charge(['mode' => 'backing', 'expense_category_id' => $this->category->id, 'title' => 'Respaldo']);
        $this->assertTrue($this->execute([$row])['committed']);
        $otherCategory = ExpenseCategory::factory()->create();
        $batch = $this->batch([array_replace($row, ['expense_category_id' => $otherCategory->id])]);
        $result = app(FinancialImportService::class)->execute($batch, $this->actor);
        $this->assertFalse($result['committed']);
        $this->assertSame('conflict', $result['rows'][0]['status']);
        $this->assertSame('conflict', $batch->rows()->firstOrFail()->result);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('partner_charges', 1);
    }
}
