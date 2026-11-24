<?php

namespace Tests\Feature;

use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Domain\Imports\Services\FinancialImportService;
use App\Models\DataImport;
use App\Models\HistoricalExternalIdentity;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinancialImportServiceTest extends TestCase
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

    private function row(array $overrides = []): array
    {
        return array_replace([
            'source' => 'ledger',
            'movement_type' => 'opening_balance',
            'external_id' => 'A1',
            'propietario_id' => $this->owner->id,
            'amount' => '1.234.567,89',
            'effective_date' => '2020-01-31',
        ], $overrides);
    }

    private function batch(array $rows): DataImport
    {
        $batch = DataImport::query()->create([
            'type' => 'financial', 'community_setting_id' => 1, 'user_id' => $this->actor->id,
            'original_name' => 'historico.csv', 'path' => 'unused.csv', 'headers' => [], 'status' => 'preview', 'total' => count($rows),
        ]);
        foreach ($rows as $index => $row) {
            $batch->rows()->create(['number' => $index + 2, 'raw' => [], 'data' => $row]);
        }

        return $batch;
    }

    public function test_failure_rolls_back_the_entire_batch_including_identities(): void
    {
        $batch = $this->batch([$this->row(), $this->row(['external_id' => 'A2'])]);
        $service = app(FinancialImportService::class);
        $this->assertTrue($service->preview($batch, $this->actor)['valid']);
        $result = $service->execute($batch, $this->actor);
        $this->assertFalse($result['committed']);
        $this->assertSame(['failed', 'failed'], array_column($result['rows'], 'status'));
        $this->assertSame(['failed', 'failed'], $batch->rows()->orderBy('number')->pluck('result')->all());
        $this->assertNotEmpty($batch->rows()->orderByDesc('number')->firstOrFail()->errors);
        $this->assertDatabaseCount('partner_charges', 0);
        $this->assertDatabaseCount('historical_external_identities', 0);
    }

    public function test_reimport_skips_existing_identity_without_duplicate(): void
    {
        $batch = $this->batch([$this->row()]);
        $service = app(FinancialImportService::class);
        $result = $service->execute($batch, $this->actor);
        $this->assertTrue($result['committed']);
        $this->assertSame('created', $result['rows'][0]['status']);
        $this->assertSame('created', $batch->rows()->firstOrFail()->result);
        $this->assertDatabaseHas('partner_charges', ['propietario_id' => $this->owner->id, 'amount' => 1234567.89]);
        $this->assertSame('skipped', $service->execute($batch, $this->actor)['rows'][0]['status']);
        $anotherBatch = $this->batch([$this->row()]);
        $this->assertSame('skipped', $service->execute($anotherBatch, $this->actor)['rows'][0]['status']);
        $this->assertDatabaseCount('partner_charges', 1);
        $this->assertDatabaseCount('historical_external_identities', 1);
    }

    public function test_non_super_admin_is_rejected(): void
    {
        $batch = $this->batch([$this->row()]);
        $this->expectException(HttpException::class);
        app(FinancialImportService::class)->execute($batch, User::factory()->create());
    }

    public function test_closed_effective_period_blocks_all_rows(): void
    {
        app(MonthlyClosingService::class)->closePeriod(2020, 2);
        $batch = $this->batch([$this->row(), $this->row(['external_id' => 'A2', 'effective_date' => '2020-02-01'])]);
        $result = app(FinancialImportService::class)->execute($batch, $this->actor);
        $this->assertFalse($result['committed']);
        $this->assertDatabaseCount('partner_charges', 0);
        $this->assertDatabaseCount('historical_external_identities', 0);
    }

    public function test_ambiguous_amount_and_invalid_iso_date_are_rejected(): void
    {
        $service = app(FinancialImportService::class);
        $batch = $this->batch([$this->row(['amount' => '1.234']), $this->row(['external_id' => 'A2', 'effective_date' => '31/01/2020'])]);
        $preview = $service->preview($batch, $this->actor);
        $this->assertFalse($preview['valid']);
        $this->assertSame(['failed', 'failed'], array_column($preview['rows'], 'status'));
        $this->assertFalse($service->execute($batch, $this->actor)['committed']);
    }

    public function test_historical_difference_is_recorded_without_cash(): void
    {
        $batch = $this->batch([$this->row(['movement_type' => 'unexplained_difference', 'amount' => '100.00'])]);
        $result = app(FinancialImportService::class)->execute($batch, $this->actor);
        $this->assertTrue($result['committed']);
        $this->assertSame('created', $result['rows'][0]['status']);
        $this->assertDatabaseHas('historical_adjustments', ['category' => 'unexplained_difference', 'evidence_status' => 'pending_verification']);
        $this->assertDatabaseCount('collections', 0);
    }

    public function test_failed_batch_can_be_corrected_and_retried_as_a_whole(): void
    {
        $batch = $this->batch([$this->row(), $this->row(['external_id' => 'A2'])]);
        $service = app(FinancialImportService::class);
        $first = $service->execute($batch, $this->actor);
        $this->assertFalse($first['committed']);
        $this->assertStringContainsString('movimientos existentes', $first['rows'][1]['message']);
        $secondOwner = Propietario::factory()->create();
        $row = $batch->rows()->orderBy('number')->get()[1];
        $row->update(['data' => $this->row(['external_id' => 'A2', 'propietario_id' => $secondOwner->id])]);
        $second = $service->execute($batch, $this->actor);
        $this->assertTrue($second['committed']);
        $this->assertSame(['created', 'created'], array_column($second['rows'], 'status'));
        $this->assertDatabaseCount('historical_external_identities', 2);
        $this->assertDatabaseCount('partner_charges', 2);
    }

    public function test_period_closed_after_preview_rejects_confirmation(): void
    {
        $batch = $this->batch([$this->row()]);
        $service = app(FinancialImportService::class);
        $this->assertTrue($service->preview($batch, $this->actor)['valid']);
        app(MonthlyClosingService::class)->closePeriod(2020, 1);
        $this->assertFalse($service->execute($batch, $this->actor)['committed']);
        $this->assertDatabaseCount('partner_charges', 0);
        $this->assertDatabaseCount('historical_external_identities', 0);
    }

    public function test_existing_identity_with_different_content_is_rejected_without_changes(): void
    {
        $service = app(FinancialImportService::class);
        $this->assertTrue($service->execute($this->batch([$this->row()]), $this->actor)['committed']);
        $result = $service->execute($this->batch([$this->row(['amount' => '10.00'])]), $this->actor);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('contenido distinto', $result['rows'][0]['message']);
        $this->assertDatabaseCount('partner_charges', 1);
        $this->assertDatabaseHas('partner_charges', ['amount' => 1234567.89]);
    }

    public function test_orphan_identity_is_rejected_without_changes(): void
    {
        HistoricalExternalIdentity::query()->create([
            'community_scope' => '1', 'source' => 'ledger', 'movement_type' => 'opening_balance', 'external_id' => 'A1',
            'identifiable_type' => PartnerCharge::class, 'identifiable_id' => 999999,
        ]);
        $result = app(FinancialImportService::class)->execute($this->batch([$this->row()]), $this->actor);
        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('vínculo inexistente', $result['rows'][0]['message']);
        $this->assertDatabaseCount('partner_charges', 0);
    }

    public function test_retry_selects_only_failed_rows_and_preserves_successful_results(): void
    {
        $batch = $this->batch([$this->row()]);
        $service = app(FinancialImportService::class);
        $this->assertTrue($service->execute($batch, $this->actor)['committed']);
        $secondOwner = Propietario::factory()->create();
        $failed = $batch->rows()->create([
            'number' => 3, 'raw' => [], 'data' => $this->row(['external_id' => 'A2', 'propietario_id' => $secondOwner->id]),
            'result' => 'failed', 'errors' => ['Error anterior.'],
        ]);
        $retry = $service->execute($batch, $this->actor, true);
        $this->assertTrue($retry['committed']);
        $this->assertCount(1, $retry['rows']);
        $this->assertSame($failed->id, $retry['rows'][0]['row_id']);
        $this->assertSame('created', $failed->fresh()->result);
        $this->assertSame('created', $batch->rows()->where('number', 2)->firstOrFail()->result);
        $this->assertDatabaseCount('historical_external_identities', 2);
    }
}
