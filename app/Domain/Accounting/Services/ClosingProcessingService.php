<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Expenses\Services\ChargeGenerationService;
use App\Models\AccountingPeriod;
use App\Models\Expense;
use App\Models\PartnerCharge;
use DomainException;
use Illuminate\Support\Facades\DB;

class ClosingProcessingService
{
    public function __construct(private readonly ChargeGenerationService $generationService) {}

    public function processPending(AccountingPeriod $period): array
    {
        $cutoff = $period->period_end->toDateString();
        $label = sprintf('%04d-%02d', $period->year, $period->month);
        $metadata = [
            'processed_expenses' => [],
            'consolidated' => [],
            'excluded_null_due_date' => Expense::query()->registered()->whereNull('due_date')->pluck('id')->all(),
            'excluded_not_overdue' => Expense::query()->registered()->whereDate('due_date', '>=', $cutoff)->orderBy('id')->get(['id', 'due_date'])->map(fn (Expense $expense): array => ['id' => $expense->id, 'due_date' => $expense->due_date->toDateString()])->all(),
            'imputation_policy' => 'due_date_or_period_end',
        ];
        $expenses = Expense::query()->registered()->where('historical_distribution_blocked', false)->whereNotNull('due_date')
            ->whereDate('due_date', '<', $cutoff)->orderBy('id')->lockForUpdate()->get();
        foreach ($expenses as $expense) {
            try {
                $charges = $this->generationService->generateChargesFromExpense($expense);
            } catch (\Throwable $exception) {
                throw new DomainException("No se pudo distribuir el gasto #{$expense->id} ({$expense->title}): {$exception->getMessage()}", 0, $exception);
            }
            foreach ($charges as $charge) {
                if ($charge->created_at->gt($period->period_end->copy()->endOfDay())) {
                    DB::table('partner_charges')->where('id', $charge->id)->update(['imputation_date' => $cutoff]);
                }
            }
            $metadata['processed_expenses'][] = ['id' => $expense->id, 'title' => $expense->title];
        }

        $retroactive = PartnerCharge::query()->where('charge_type', 'distribution')->whereNull('imputation_date')
            ->where('created_at', '>', $period->period_end->copy()->endOfDay())
            ->whereHas('expense', fn ($query) => $query->whereDate('due_date', '<=', $cutoff))
            ->orderBy('id')->lockForUpdate()->pluck('id');
        if ($retroactive->isNotEmpty()) {
            DB::table('partner_charges')->whereIn('id', $retroactive)->update(['imputation_date' => $cutoff]);
        }

        $sources = PartnerCharge::query()->unpaid()->whereDate('due_date', '<', $cutoff)
            ->orderBy('propietario_id')->orderBy('id')->lockForUpdate()->get()->groupBy('propietario_id');
        foreach ($sources as $ownerId => $charges) {
            $cents = $charges->sum(fn (PartnerCharge $charge): int => (int) round($charge->remaining_amount * 100));
            if ($cents <= 0) {
                continue;
            }
            if (PartnerCharge::query()->where('propietario_id', $ownerId)->where('charge_type', 'balance_consolidation')
                ->where('consolidation_year', $period->year)->where('consolidation_month', $period->month)->exists()) {
                throw new DomainException("Ya existe una consolidación del propietario #{$ownerId} para {$label}; revise los nuevos cobros antes de recerrar.");
            }
            $consolidated = PartnerCharge::query()->create([
                'propietario_id' => $ownerId,
                'charge_type' => 'balance_consolidation',
                'expense_id' => null,
                'amount' => $cents / 100,
                'paid_amount' => 0,
                'status' => ChargeStatus::Pending,
                'due_date' => $cutoff,
                'imputation_date' => $cutoff,
                'description' => 'Saldo '.$period->period_end->copy()->locale('es')->translatedFormat('F Y'),
                'consolidation_year' => $period->year,
                'consolidation_month' => $period->month,
                'calculation_type' => $charges->first()->calculation_type,
                'calculation_notes' => "Consolidación de {$charges->count()} cobros vencidos al cierre {$label}",
            ]);
            foreach ($charges as $charge) {
                $consolidated->consolidationLinks()->create([
                    'source_charge_id' => $charge->id,
                    'transferred_amount' => $charge->remaining_amount,
                ]);
                $charge->update(['status' => ChargeStatus::Consolidated]);
            }
            $metadata['consolidated'][] = ['propietario' => (int) $ownerId, 'monto' => $cents / 100, 'origenes' => $charges->count()];
        }

        return $metadata;
    }
}
