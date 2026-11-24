<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

class HistoricalPeriodGuard
{
    public function __construct(private readonly MonthlyClosingService $closingService) {}

    public function validate(CarbonInterface|string $date): void
    {
        if ($this->closingService->isDateInClosedPeriod($date)) {
            throw new DomainException('La fecha efectiva pertenece a un período cerrado.');
        }
    }

    /**
     * Must be called inside the transaction which records the historical movement.
     * The open row also provides a lock target when the month did not exist yet.
     */
    public function lockAndValidate(CarbonInterface|string $date): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La validación con bloqueo requiere una transacción activa.');
        }

        $target = $date instanceof CarbonInterface ? Carbon::instance($date) : Carbon::parse($date);
        $start = $target->copy()->startOfMonth()->startOfDay();
        $now = now();

        DB::table('accounting_periods')->insertOrIgnore([
            'year' => $start->year,
            'month' => $start->month,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'status' => AccountingPeriodStatus::Open->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $period = AccountingPeriod::query()
            ->where('year', $start->year)
            ->where('month', $start->month)
            ->lockForUpdate()
            ->firstOrFail();

        if ($period->status === AccountingPeriodStatus::Closed) {
            throw new DomainException('La fecha efectiva pertenece a un período cerrado.');
        }

        $this->validate($target);
    }
}
