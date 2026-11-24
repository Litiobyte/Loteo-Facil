<?php

namespace Tests\Feature;

use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Domain\Balances\Services\CashBalanceService;
use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Collections\Services\CollectionApplicationService;
use App\Domain\Imports\Services\FinancialImportService;
use App\Domain\Imports\Services\HistoricalOpeningService;
use App\Domain\Statements\Services\PartnerStatementService;
use App\Models\Collection;
use App\Models\CollectionAllocation;
use App\Models\DataImport;
use App\Models\HistoricalExternalIdentity;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinancialCollectionImportTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Propietario $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('super_admin', 'web');
        $this->actor = User::factory()->create();
        $this->actor->assignRole('super_admin');
        $this->owner = Propietario::factory()->create();
    }

    private function row(array $changes = []): array
    {
        return array_replace(['source' => 'ledger', 'movement_type' => 'collection', 'external_id' => 'P1',
            'mode' => 'automatic', 'propietario_id' => $this->owner->id, 'amount' => '150',
            'collection_date' => '2020-03-15', 'collection_method' => 'transferencia', 'reference' => 'ref'], $changes);
    }

    private function runBatch(array $rows): array
    {
        $batch = DataImport::query()->create(['type' => 'financial', 'community_setting_id' => 1, 'user_id' => $this->actor->id,
            'original_name' => 'collections.csv', 'path' => 'unused', 'headers' => [], 'status' => 'preview', 'total' => count($rows)]);
        foreach ($rows as $index => $row) {
            $batch->rows()->create(['number' => $index + 2, 'raw' => [], 'data' => $row]);
        }

        return app(FinancialImportService::class)->execute($batch, $this->actor);
    }

    private function charge(string $due, int $amount = 100): PartnerCharge
    {
        return PartnerCharge::factory()->create(['propietario_id' => $this->owner->id, 'amount' => $amount,
            'paid_amount' => 0, 'due_date' => $due, 'imputation_date' => $due]);
    }

    public function test_fifo_multiple_charges_date_cash_and_idempotence(): void
    {
        $first = $this->charge('2020-01-01');
        $second = $this->charge('2020-02-01');
        $result = $this->runBatch([$this->row()]);
        $this->assertTrue($result['committed'], json_encode($result));
        $this->assertSame(0.0, $first->refresh()->remaining_amount);
        $this->assertSame(50.0, $second->refresh()->remaining_amount);
        $payment = Collection::firstOrFail();
        $this->assertSame('ref', $payment->reference);
        $this->assertSame('transferencia', $payment->collection_method->value);
        $this->assertSame('2020-03-15', $payment->allocations()->firstOrFail()->allocated_at->toDateString());
        $this->assertSame(150.0, app(CashBalanceService::class)->getTotalIncome());
        $this->assertSame('skipped', $this->runBatch([$this->row()])['rows'][0]['status']);
        $this->assertSame('conflict', $this->runBatch([$this->row(['amount' => '151'])])['rows'][0]['status']);
        $this->assertDatabaseCount('collections', 1);
    }

    public function test_manual_partial_and_excess_rollback_and_unapplied(): void
    {
        $charge = $this->charge('2020-01-01');
        $manual = $this->row(['mode' => 'manual', 'amount' => '40', 'allocations' => [['charge_id' => $charge->id, 'amount' => '40']]]);
        $this->assertTrue($this->runBatch([$manual])['committed']);
        $this->assertSame(60.0, $charge->refresh()->remaining_amount);
        $bad = $this->row(['external_id' => 'P2', 'mode' => 'manual', 'amount' => '70', 'allocations' => [['charge_id' => $charge->id, 'amount' => '70']]]);
        $this->assertFalse($this->runBatch([$bad])['committed']);
        $this->assertDatabaseCount('collections', 1);
        $free = $this->row(['external_id' => 'P3', 'mode' => 'unapplied']);
        $this->assertTrue($this->runBatch([$free])['committed']);
        $this->assertSame('150.00', Collection::orderByDesc('id')->firstOrFail()->unapplied_amount);
        $this->assertSame(60.0, $charge->refresh()->remaining_amount);
    }

    public function test_batch_rolls_back_first_payment_and_allocation_when_second_row_fails_during_execution(): void
    {
        $charge = $this->charge('2020-01-01');
        $createdAllocations = [];
        $createdIdentities = [];
        Event::listen('eloquent.created: '.CollectionAllocation::class, function (CollectionAllocation $allocation) use (&$createdAllocations): void {
            $createdAllocations[] = [$allocation->partner_charge_id, $allocation->amount];
        });
        Event::listen('eloquent.created: '.HistoricalExternalIdentity::class, function (HistoricalExternalIdentity $identity) use (&$createdIdentities): void {
            $createdIdentities[] = $identity->external_id;
        });

        $result = $this->runBatch([
            $this->row(['amount' => '40']),
            $this->row(['external_id' => 'P2', 'mode' => 'manual', 'amount' => '70', 'allocations' => [
                ['charge_id' => $charge->id, 'amount' => '70'],
            ]]),
        ]);

        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('excede', $result['rows'][1]['message']);
        $this->assertSame([[$charge->id, '40.00']], $createdAllocations);
        $this->assertSame(['P1'], $createdIdentities);
        $charge->refresh();
        $this->assertSame('100.00', $charge->amount);
        $this->assertSame('0.00', $charge->paid_amount);
        $this->assertSame(100.0, $charge->remaining_amount);
        $this->assertSame(ChargeStatus::Pending, $charge->status);
        $this->assertDatabaseCount('collections', 0);
        $this->assertDatabaseCount('collection_allocations', 0);
        $this->assertDatabaseMissing('historical_external_identities', ['source' => 'ledger', 'movement_type' => 'collection', 'external_id' => 'P1']);
        $this->assertDatabaseMissing('historical_external_identities', ['source' => 'ledger', 'movement_type' => 'collection', 'external_id' => 'P2']);
    }

    public function test_application_defaults_to_current_time_and_authenticated_author_in_both_modes(): void
    {
        $applicationTime = Carbon::now()->setTime(12, 34, 56);
        $this->travelTo($applicationTime);
        $this->actingAs($this->actor);
        $first = $this->charge('2020-01-01');
        $second = $this->charge('2020-02-01');
        $automaticPayment = Collection::factory()->create([
            'propietario_id' => $this->owner->id, 'amount' => 100, 'collection_date' => '2020-03-15',
        ]);
        $manualPayment = Collection::factory()->create([
            'propietario_id' => $this->owner->id, 'amount' => 100, 'collection_date' => '2020-03-15',
        ]);

        $service = app(CollectionApplicationService::class);
        $automatic = $service->applyCollectionAutomatically($automaticPayment)->sole();
        $manual = $service->applyCollectionManually($manualPayment, [
            ['charge_id' => $second->id, 'amount' => 100],
        ])->sole();

        $this->assertSame($first->id, $automatic->partner_charge_id);
        $this->assertSame($second->id, $manual->partner_charge_id);
        foreach ([$automatic, $manual] as $allocation) {
            $this->assertSame($applicationTime->format('Y-m-d H:i:s'), $allocation->allocated_at->format('Y-m-d H:i:s'));
            $this->assertSame($this->actor->id, $allocation->created_by);
        }
    }

    public function test_unknown_date_and_automatic_without_debt_fail(): void
    {
        $bad = $this->runBatch([$this->row(['collection_date' => ''])]);
        $this->assertFalse($bad['committed']);
        $this->assertStringContainsString('sin aplicación', $bad['rows'][0]['message']);
        $empty = $this->runBatch([$this->row()]);
        $this->assertFalse($empty['committed']);
        $this->assertStringContainsString('sin aplicación', $empty['rows'][0]['message']);
        $this->assertDatabaseCount('collections', 0);
    }

    public function test_two_payments_for_charge_and_statement_date(): void
    {
        $charge = $this->charge('2020-01-01');
        $this->assertTrue($this->runBatch([$this->row(['amount' => '40'])])['committed']);
        $this->assertTrue($this->runBatch([$this->row(['external_id' => 'P2', 'amount' => '80'])])['committed']);
        $this->assertSame(0.0, $charge->refresh()->remaining_amount);
        $this->assertSame('20.00', Collection::orderByDesc('id')->firstOrFail()->unapplied_amount);
        $statement = app(PartnerStatementService::class)->getCollectionsDetail($this->owner, Carbon::parse('2020-03-01'), Carbon::parse('2020-03-31'));
        $this->assertCount(2, $statement);
        $this->assertCount(2, $charge->allocations);
    }

    public function test_opening_cutoff_and_closed_period_reverse_batch(): void
    {
        app(HistoricalOpeningService::class)->create($this->owner, $this->actor, '100.00', '2020-03-10', ['source' => 'ledger']);
        $cutoff = $this->runBatch([$this->row(['collection_date' => '2020-03-09'])]);
        $this->assertFalse($cutoff['committed']);
        $this->assertStringContainsString('corte', $cutoff['rows'][0]['message']);
        app(MonthlyClosingService::class)->closePeriod(2020, 4);
        $result = $this->runBatch([
            $this->row(['mode' => 'unapplied', 'collection_date' => '2020-03-15']),
            $this->row(['external_id' => 'P2', 'mode' => 'unapplied', 'collection_date' => '2020-04-15']),
        ]);
        $this->assertFalse($result['committed']);
        $this->assertDatabaseCount('collections', 0);
    }

    public function test_manual_cannot_apply_to_other_owner(): void
    {
        $charge = PartnerCharge::factory()->create(['propietario_id' => Propietario::factory()->create()->id, 'amount' => 100, 'paid_amount' => 0]);
        $result = $this->runBatch([$this->row(['mode' => 'manual', 'allocations' => [['charge_id' => $charge->id, 'amount' => '40']]])]);
        $this->assertFalse($result['committed']);
        $this->assertDatabaseCount('collections', 0);
        $this->assertDatabaseCount('collection_allocations', 0);
    }

    public function test_manual_external_identity_and_author_are_preserved(): void
    {
        $charge = $this->charge('2020-01-01');
        HistoricalExternalIdentity::query()->create([
            'community_scope' => '1', 'source' => 'ledger', 'movement_type' => 'expense_charge',
            'external_id' => 'C1', 'identifiable_type' => $charge->getMorphClass(), 'identifiable_id' => $charge->id,
        ]);
        $row = $this->row(['mode' => 'manual', 'amount' => '25', 'allocations' => [
            ['charge_external_id' => 'C1', 'amount' => '25'],
        ]]);
        $result = $this->runBatch([$row]);
        $this->assertTrue($result['committed'], json_encode($result));
        $allocation = Collection::firstOrFail()->allocations()->firstOrFail();
        $this->assertSame($this->actor->id, $allocation->created_by);
        $this->assertSame('2020-03-15', $allocation->allocated_at->toDateString());
        $this->assertSame('skipped', $this->runBatch([$row])['rows'][0]['status']);
    }

    public function test_closed_charge_period_rejects_historical_application(): void
    {
        $this->charge('2020-01-01');
        app(MonthlyClosingService::class)->closePeriod(2020, 1);
        $result = $this->runBatch([$this->row()]);
        $this->assertFalse($result['committed']);
        $this->assertDatabaseCount('collections', 0);
        $this->assertDatabaseCount('collection_allocations', 0);
    }
}
