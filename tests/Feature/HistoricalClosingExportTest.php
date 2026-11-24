<?php

namespace Tests\Feature;

use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Domain\Balances\Services\CashBalanceService;
use App\Domain\Balances\Services\PartnerBalanceService;
use App\Domain\Imports\Enums\HistoricalAdjustmentCategory;
use App\Domain\Imports\Services\HistoricalAdjustmentService;
use App\Domain\Imports\Services\HistoricalOpeningService;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HistoricalClosingExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_classifies_non_monetary_adjustments_and_preserves_snapshot_and_cash(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $owner = Propietario::factory()->create();
        $creditOwner = Propietario::factory()->create();
        $openings = app(HistoricalOpeningService::class);
        $adjustments = app(HistoricalAdjustmentService::class);

        $charge = $openings->create($owner, $admin, '100.00', '2020-01-01', ['source' => 'ledger']);
        $adjustments->create($owner, $admin, HistoricalAdjustmentCategory::DebtCorrection, '20.00', '2020-02-10', 'day', ['source' => 'ledger'], $charge->id);
        $adjustments->create($owner, $admin, HistoricalAdjustmentCategory::DebtForgiveness, '10.00', '2020-02-12', 'day', ['source' => 'ledger'], $charge->id);
        $adjustments->create($owner, $admin, HistoricalAdjustmentCategory::UnexplainedDifference, '40.00', '2020-02', 'month', ['source' => 'ledger']);
        $openings->create($creditOwner, $admin, '-50.00', '2020-02-05', ['source' => 'ledger']);

        $balance = app(PartnerBalanceService::class)->getBalanceSummary($owner);
        $this->assertSame(0.0, $balance['total_applied']);
        $this->assertSame(30.0, $balance['total_non_monetary_applied']);
        $this->assertSame(70.0, $balance['pending_balance']);
        $this->assertSame(0.0, app(CashBalanceService::class)->getTotalIncome());

        $closer = app(MonthlyClosingService::class);
        $period = $closer->closePeriod(2020, 2, $admin->id);
        $snapshotBefore = $period->snapshot->toArray();
        $csv = TestResponse::fromBaseResponse($closer->exportClosedPeriodCsv($period))->streamedContent();
        $lines = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", trim($csv))));
        $byType = collect(array_slice($lines, 1))->keyBy(fn (array $line): string => $line[2]);

        $this->assertSame('20', $byType['Corrección de deuda no monetaria'][7]);
        $this->assertSame('10', $byType['Condonación no monetaria'][7]);
        $this->assertSame('50', $byType['Apertura acreedora no monetaria'][7]);
        $pending = $byType['Diferencia pendiente de verificación'];
        $this->assertSame('2020-02', $pending[3]);
        $this->assertSame('0', $pending[7]);
        $this->assertStringContainsString('40.00', $pending[5]);
        $this->assertSame('pending_verification', $pending[9]);
        $this->assertSame($snapshotBefore, $period->snapshot->fresh()->toArray());
        $this->assertSame(0.0, app(CashBalanceService::class)->getTotalIncome());
    }
}
