<?php

namespace Tests\Feature;

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use App\Domain\Accounting\Services\HistoricalPeriodGuard;
use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Models\AccountingPeriod;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class HistoricalPeriodGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_period_is_allowed_and_locked_as_open_without_snapshot(): void
    {
        $guard = app(HistoricalPeriodGuard::class);
        $guard->validate('2020-02-29');

        DB::transaction(fn () => $guard->lockAndValidate('2020-02-29'));

        $period = AccountingPeriod::query()->where('year', 2020)->where('month', 2)->firstOrFail();
        $this->assertSame(AccountingPeriodStatus::Open, $period->status);
        $this->assertSame('2020-02-29', $period->period_end->toDateString());
        $this->assertDatabaseCount('accounting_period_snapshots', 0);
        app(MonthlyClosingService::class)->closePeriod(2020, 2);
        $this->assertSame(AccountingPeriodStatus::Closed, $period->fresh()->status);
    }

    public function test_closed_period_is_rejected_in_preflight_and_transaction(): void
    {
        app(MonthlyClosingService::class)->closePeriod(2020, 2);
        $guard = app(HistoricalPeriodGuard::class);

        try {
            $guard->validate('2020-02-29');
            $this->fail('La validación previa debe rechazar el período cerrado.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->expectException(DomainException::class);
        DB::transaction(fn () => $guard->lockAndValidate('2020-02-01'));
    }

    public function test_lock_requires_a_transaction(): void
    {
        // RefreshDatabase opens an outer transaction; temporarily use a separate connection level assertion.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $this->expectException(LogicException::class);
        app(HistoricalPeriodGuard::class)->lockAndValidate('2020-01-01');
    }

    public function test_open_row_is_rolled_back_with_the_movement_transaction(): void
    {
        try {
            DB::transaction(function (): void {
                app(HistoricalPeriodGuard::class)->lockAndValidate('2021-03-01');
                throw new DomainException('rollback');
            });
        } catch (DomainException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        $this->assertDatabaseMissing('accounting_periods', ['year' => 2021, 'month' => 3]);
    }
}
