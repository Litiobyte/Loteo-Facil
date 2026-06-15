<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Collections\Enums\CollectionStatus;
use App\Models\AccountingPeriod;
use App\Models\AccountingPeriodSnapshot;
use App\Models\Collection;
use App\Models\CollectionAllocation;
use App\Models\PartnerCharge;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonthlyClosingService
{
    public function closePeriod(int $year, int $month, ?int $userId = null): AccountingPeriod
    {
        $this->guardValidMonth($month);

        return DB::transaction(function () use ($year, $month, $userId): AccountingPeriod {
            $period = AccountingPeriod::query()
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if ($period === null) {
                $periodDates = $this->getPeriodDates($year, $month);

                $period = AccountingPeriod::query()->create([
                    'year' => $year,
                    'month' => $month,
                    'period_start' => $periodDates['start']->toDateString(),
                    'period_end' => $periodDates['end']->toDateString(),
                    'status' => AccountingPeriodStatus::Open,
                ]);
            }

            if ($period->status === AccountingPeriodStatus::Closed) {
                throw new DomainException('El período ya está cerrado.');
            }

            $consistency = $this->validateGlobalConsistency();

            if (! $consistency['valid']) {
                throw new DomainException('No se puede cerrar el período porque existen inconsistencias: '.implode(' | ', $consistency['errors']));
            }

            $snapshotData = $this->buildSnapshotData($period);

            AccountingPeriodSnapshot::query()->updateOrCreate(
                ['accounting_period_id' => $period->id],
                $snapshotData,
            );

            $period->update([
                'status' => AccountingPeriodStatus::Closed,
                'close_folio' => $this->generateCloseFolio($period),
                'closed_at' => now(),
                'closed_by' => $userId,
                'reopened_at' => null,
                'reopened_by' => null,
                'reopen_reason' => null,
            ]);

            return $period->fresh(['snapshot']) ?? $period;
        });
    }

    public function reopenPeriod(AccountingPeriod $period, string $reason, ?int $userId = null): AccountingPeriod
    {
        if (trim($reason) === '') {
            throw new DomainException('Debe indicar un motivo para reabrir el período.');
        }

        if ($period->status === AccountingPeriodStatus::Open) {
            throw new DomainException('El período ya está abierto.');
        }

        $period->update([
            'status' => AccountingPeriodStatus::Open,
            'reopened_at' => now(),
            'reopened_by' => $userId,
            'reopen_reason' => trim($reason),
        ]);

        return $period->fresh(['snapshot']) ?? $period;
    }

    public function isDateInClosedPeriod(CarbonInterface|string|null $date): bool
    {
        if ($date === null) {
            return false;
        }

        $targetDate = $date instanceof CarbonInterface
            ? Carbon::instance($date)
            : Carbon::parse($date);

        return AccountingPeriod::query()
            ->where('status', AccountingPeriodStatus::Closed)
            ->whereDate('period_start', '<=', $targetDate->toDateString())
            ->whereDate('period_end', '>=', $targetDate->toDateString())
            ->exists();
    }

    public function exportClosedPeriodCsv(AccountingPeriod $period): StreamedResponse
    {
        if ($period->status !== AccountingPeriodStatus::Closed) {
            throw new DomainException('Solo se puede exportar CSV de períodos cerrados.');
        }

        $period->loadMissing('snapshot');

        $fileName = sprintf('cierre-%s-%04d-%02d.csv', $period->close_folio ?? 'sin-folio', $period->year, $period->month);
        $rows = $this->buildExportRows($period);

        return response()->streamDownload(function () use ($period, $rows): void {
            $handle = fopen('php://output', 'w');

            if (! $handle) {
                return;
            }

            fputcsv($handle, [
                'Folio',
                'Periodo',
                'Tipo movimiento',
                'Fecha',
                'Propietario',
                'Referencia',
                'Debe',
                'Haber',
                'Saldo acumulado',
                'Estado',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $period->close_folio,
                    sprintf('%04d-%02d', $period->year, $period->month),
                    $row['movement_type'],
                    $row['movement_date'],
                    $row['owner_name'],
                    $row['reference'],
                    $row['debit'],
                    $row['credit'],
                    $row['running_balance'],
                    $row['status'],
                ]);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateGlobalConsistency(): array
    {
        $errors = [];

        $charges = PartnerCharge::query()
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->selectRaw('COALESCE(SUM(paid_amount), 0) as total_paid')
            ->selectRaw('COALESCE(SUM(remaining_amount), 0) as total_remaining')
            ->first();

        $collections = Collection::query()
            ->where('status', '!=', CollectionStatus::Cancelled->value)
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->selectRaw('COALESCE(SUM(applied_amount), 0) as total_applied')
            ->selectRaw('COALESCE(SUM(unapplied_amount), 0) as total_unapplied')
            ->first();

        $totalAllocated = (float) CollectionAllocation::query()->sum('amount');

        $chargesTotal = round((float) ($charges?->total_amount ?? 0), 2);
        $chargesExplained = round((float) ($charges?->total_paid ?? 0) + (float) ($charges?->total_remaining ?? 0), 2);

        if (abs($chargesTotal - $chargesExplained) > 0.01) {
            $errors[] = 'Inconsistencia global en cobros: amount != paid + remaining.';
        }

        $collectionsTotal = round((float) ($collections?->total_amount ?? 0), 2);
        $collectionsExplained = round((float) ($collections?->total_applied ?? 0) + (float) ($collections?->total_unapplied ?? 0), 2);

        if (abs($collectionsTotal - $collectionsExplained) > 0.01) {
            $errors[] = 'Inconsistencia global en pagos: amount != applied + unapplied.';
        }

        $collectionsApplied = round((float) ($collections?->total_applied ?? 0), 2);
        $allocated = round($totalAllocated, 2);

        if (abs($collectionsApplied - $allocated) > 0.01) {
            $errors[] = 'Inconsistencia global entre pagos aplicados y asignaciones registradas.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    private function getPeriodDates(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();

        return [
            'start' => $start,
            'end' => $start->copy()->endOfMonth(),
        ];
    }

    private function guardValidMonth(int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw new DomainException('El mes debe estar entre 1 y 12.');
        }
    }

    private function generateCloseFolio(AccountingPeriod $period): string
    {
        $sequence = AccountingPeriod::query()->whereNotNull('close_folio')->count() + 1;

        return sprintf('CIERRE-%04d%02d-%04d', $period->year, $period->month, $sequence);
    }

    /**
     * @return array<string, float|array<string, float>>
     */
    private function buildSnapshotData(AccountingPeriod $period): array
    {
        $start = Carbon::parse($period->period_start)->startOfDay();
        $end = Carbon::parse($period->period_end)->endOfDay();

        $totalCharges = (float) PartnerCharge::query()
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $totalCollections = (float) Collection::query()
            ->where('status', '!=', CollectionStatus::Cancelled->value)
            ->whereBetween('collection_date', [$start->toDateString(), $end->toDateString()])
            ->sum('amount');

        $totalAllocations = (float) CollectionAllocation::query()
            ->whereBetween('allocated_at', [$start, $end])
            ->sum('amount');

        $pendingBalance = (float) PartnerCharge::query()
            ->whereIn('status', [ChargeStatus::Pending->value, ChargeStatus::Partial->value])
            ->sum('remaining_amount');

        $creditBalance = (float) Collection::query()
            ->whereIn('status', [CollectionStatus::PendingApplication->value, CollectionStatus::PartiallyApplied->value])
            ->sum('unapplied_amount');

        $aging = $this->calculateAgingBuckets();

        return [
            'total_charges' => round($totalCharges, 2),
            'total_collections' => round($totalCollections, 2),
            'total_allocations' => round($totalAllocations, 2),
            'pending_balance' => round($pendingBalance, 2),
            'credit_balance' => round($creditBalance, 2),
            'overdue_1_30' => $aging['overdue_1_30'],
            'overdue_31_60' => $aging['overdue_31_60'],
            'overdue_61_90' => $aging['overdue_61_90'],
            'overdue_90_plus' => $aging['overdue_90_plus'],
            'metadata' => [
                'closed_period' => sprintf('%04d-%02d', $period->year, $period->month),
                'closed_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array{overdue_1_30: float, overdue_31_60: float, overdue_61_90: float, overdue_90_plus: float}
     */
    private function calculateAgingBuckets(): array
    {
        $buckets = [
            'overdue_1_30' => 0.0,
            'overdue_31_60' => 0.0,
            'overdue_61_90' => 0.0,
            'overdue_90_plus' => 0.0,
        ];

        $charges = PartnerCharge::query()
            ->whereNotNull('due_date')
            ->whereIn('status', [ChargeStatus::Pending->value, ChargeStatus::Partial->value])
            ->get(['remaining_amount', 'due_date']);

        foreach ($charges as $charge) {
            if (! $charge->due_date || $charge->due_date->isToday() || $charge->due_date->isFuture()) {
                continue;
            }

            $daysOverdue = $charge->due_date->startOfDay()->diffInDays(now()->startOfDay());
            $amount = round((float) $charge->getRawOriginal('remaining_amount'), 2);

            if ($daysOverdue <= 30) {
                $buckets['overdue_1_30'] += $amount;
            } elseif ($daysOverdue <= 60) {
                $buckets['overdue_31_60'] += $amount;
            } elseif ($daysOverdue <= 90) {
                $buckets['overdue_61_90'] += $amount;
            } else {
                $buckets['overdue_90_plus'] += $amount;
            }
        }

        return [
            'overdue_1_30' => round($buckets['overdue_1_30'], 2),
            'overdue_31_60' => round($buckets['overdue_31_60'], 2),
            'overdue_61_90' => round($buckets['overdue_61_90'], 2),
            'overdue_90_plus' => round($buckets['overdue_90_plus'], 2),
        ];
    }

    /**
     * @return SupportCollection<int, array{movement_type: string, movement_date: string, owner_name: string, reference: string, debit: float, credit: float, running_balance: float, status: string}>
     */
    private function buildExportRows(AccountingPeriod $period): SupportCollection
    {
        $start = Carbon::parse($period->period_start)->startOfDay();
        $end = Carbon::parse($period->period_end)->endOfDay();

        $rows = collect();

        $charges = PartnerCharge::query()
            ->with('propietario')
            ->whereBetween('created_at', [$start, $end])
            ->get();

        foreach ($charges as $charge) {
            $rows->push([
                'movement_type' => 'Cobro emitido',
                'movement_date' => $charge->created_at?->format('Y-m-d H:i:s') ?? '-',
                'owner_name' => $charge->propietario?->nombre_completo ?? '-',
                'reference' => 'Cobro #'.$charge->id,
                'debit' => round((float) $charge->amount, 2),
                'credit' => 0.0,
                'status' => $charge->status->value,
                'sort_key' => $charge->created_at?->timestamp ?? 0,
            ]);
        }

        $collections = Collection::query()
            ->with('propietario')
            ->whereBetween('collection_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', CollectionStatus::Cancelled->value)
            ->get();

        foreach ($collections as $payment) {
            $rows->push([
                'movement_type' => 'Recaudacion recibido',
                'movement_date' => $payment->collection_date?->format('Y-m-d') ?? '-',
                'owner_name' => $payment->propietario?->nombre_completo ?? '-',
                'reference' => 'Recaudacion #'.$payment->id.($payment->reference ? ' - '.$payment->reference : ''),
                'debit' => 0.0,
                'credit' => round((float) $payment->amount, 2),
                'status' => $payment->status->value,
                'sort_key' => $payment->collection_date?->timestamp ?? 0,
            ]);
        }

        $allocations = CollectionAllocation::query()
            ->with(['payment.propietario', 'charge'])
            ->whereBetween('allocated_at', [$start, $end])
            ->get();

        foreach ($allocations as $allocation) {
            $rows->push([
                'movement_type' => 'Aplicación de pago',
                'movement_date' => $allocation->allocated_at?->format('Y-m-d H:i:s') ?? '-',
                'owner_name' => $allocation->payment?->propietario?->nombre_completo ?? '-',
                'reference' => sprintf('Asignación #%d (Recaudacion #%d -> Cobro #%d)', $allocation->id, $allocation->collection_id, $allocation->partner_charge_id),
                'debit' => 0.0,
                'credit' => 0.0,
                'status' => 'applied',
                'sort_key' => $allocation->allocated_at?->timestamp ?? 0,
            ]);
        }

        $sorted = $rows
            ->sortBy([
                ['sort_key', 'asc'],
                ['movement_type', 'asc'],
            ])
            ->values();

        $runningBalance = 0.0;

        return $sorted->map(function (array $row) use (&$runningBalance): array {
            $runningBalance = round($runningBalance + (float) $row['debit'] - (float) $row['credit'], 2);

            return [
                'movement_type' => $row['movement_type'],
                'movement_date' => $row['movement_date'],
                'owner_name' => $row['owner_name'],
                'reference' => $row['reference'],
                'debit' => round((float) $row['debit'], 2),
                'credit' => round((float) $row['credit'], 2),
                'running_balance' => $runningBalance,
                'status' => $row['status'],
            ];
        });
    }
}
