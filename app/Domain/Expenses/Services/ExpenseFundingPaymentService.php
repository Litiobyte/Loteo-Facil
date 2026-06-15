<?php

namespace App\Domain\Expenses\Services;

use App\Domain\Balances\Services\CashBalanceService;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseFundingPayment;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseFundingPaymentService
{
    public function __construct(
        private readonly CashBalanceService $cashBalanceService
    ) {}

    public function register(Expense $expense, float $amount, string $paymentDate, ?string $notes = null, bool $allowNegativeCash = false): ExpenseFundingPayment
    {
        $roundedAmount = round($amount, 2);

        if ($roundedAmount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'El monto a pagar debe ser mayor a 0.',
            ]);
        }

        return DB::transaction(function () use ($expense, $roundedAmount, $paymentDate, $notes, $allowNegativeCash): ExpenseFundingPayment {
            /** @var Expense $lockedExpense */
            $lockedExpense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            $this->ensureLegacyFundingSeeded($lockedExpense);

            if ($lockedExpense->status !== ExpenseStatus::Distributed) {
                throw new DomainException('Solo se puede registrar pago de caja para gastos distribuidos.');
            }

            $pendingAmount = round((float) $lockedExpense->amount - (float) $lockedExpense->funded_amount, 2);

            if ($roundedAmount > $pendingAmount) {
                throw ValidationException::withMessages([
                    'amount' => 'El monto excede el saldo pendiente del gasto.',
                ]);
            }

            $projectedCash = $this->cashBalanceService->getProjectedCashAfterFunding($roundedAmount);

            if ($projectedCash < 0 && ! $allowNegativeCash) {
                throw ValidationException::withMessages([
                    'confirm_negative_cash' => 'La caja proyectada quedará negativa. Debe confirmar explícitamente para continuar.',
                ]);
            }

            $payment = ExpenseFundingPayment::query()->create([
                'expense_id' => $lockedExpense->id,
                'amount' => $roundedAmount,
                'payment_date' => $paymentDate,
                'notes' => $notes,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            $this->refreshExpenseFundingStatus($lockedExpense);

            return $payment->fresh() ?? $payment;
        });
    }

    public function update(ExpenseFundingPayment $payment, float $amount, string $paymentDate, ?string $notes = null, bool $allowNegativeCash = false): ExpenseFundingPayment
    {
        $roundedAmount = round($amount, 2);

        if ($roundedAmount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'El monto a pagar debe ser mayor a 0.',
            ]);
        }

        return DB::transaction(function () use ($payment, $roundedAmount, $paymentDate, $notes, $allowNegativeCash): ExpenseFundingPayment {
            /** @var ExpenseFundingPayment $lockedCollection */
            $lockedCollection = ExpenseFundingPayment::query()
                ->with('expense')
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if ($lockedCollection->is_void) {
                throw new DomainException('No se puede editar un egreso anulado.');
            }

            $expense = Expense::query()->lockForUpdate()->findOrFail($lockedCollection->expense_id);

            $this->ensureLegacyFundingSeeded($expense);

            if (! in_array($expense->status, [ExpenseStatus::Distributed, ExpenseStatus::Paid], true)) {
                throw new DomainException('No se puede editar egresos de gastos cancelados o no distribuidos.');
            }

            $activeCollectionsSum = (float) ExpenseFundingPayment::query()
                ->where('expense_id', $expense->id)
                ->where('is_void', false)
                ->where('id', '!=', $lockedCollection->id)
                ->sum('amount');

            $projectedFunded = round($activeCollectionsSum + $roundedAmount, 2);

            if ($projectedFunded > round((float) $expense->amount, 2)) {
                throw ValidationException::withMessages([
                    'amount' => 'El monto excede el saldo pendiente del gasto.',
                ]);
            }

            $netIncrease = round($roundedAmount - (float) $lockedCollection->amount, 2);
            $projectedCash = $this->cashBalanceService->getProjectedCashAfterFunding(max($netIncrease, 0));

            if ($projectedCash < 0 && ! $allowNegativeCash) {
                throw ValidationException::withMessages([
                    'confirm_negative_cash' => 'La caja proyectada quedará negativa. Debe confirmar explícitamente para continuar.',
                ]);
            }

            $lockedCollection->update([
                'amount' => $roundedAmount,
                'payment_date' => $paymentDate,
                'notes' => $notes,
                'updated_by' => auth()->id(),
            ]);

            $this->refreshExpenseFundingStatus($expense);

            return $lockedCollection->fresh() ?? $lockedCollection;
        });
    }

    public function void(ExpenseFundingPayment $payment, string $reason): ExpenseFundingPayment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'void_reason' => 'Debe indicar el motivo de anulación.',
            ]);
        }

        return DB::transaction(function () use ($payment, $reason): ExpenseFundingPayment {
            /** @var ExpenseFundingPayment $lockedCollection */
            $lockedCollection = ExpenseFundingPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedCollection->is_void) {
                throw new DomainException('El egreso ya está anulado.');
            }

            $expense = Expense::query()->lockForUpdate()->findOrFail($lockedCollection->expense_id);

            $this->ensureLegacyFundingSeeded($expense);

            $lockedCollection->update([
                'is_void' => true,
                'voided_at' => now(),
                'voided_by' => auth()->id(),
                'void_reason' => trim($reason),
                'updated_by' => auth()->id(),
            ]);

            $this->refreshExpenseFundingStatus($expense);

            return $lockedCollection->fresh() ?? $lockedCollection;
        });
    }

    private function refreshExpenseFundingStatus(Expense $expense): void
    {
        $fundedAmount = round((float) ExpenseFundingPayment::query()
            ->where('expense_id', $expense->id)
            ->where('is_void', false)
            ->sum('amount'), 2);

        $expenseAmount = round((float) $expense->amount, 2);

        $attributes = [
            'funded_amount' => $fundedAmount,
            'status' => $fundedAmount >= $expenseAmount ? ExpenseStatus::Paid : ExpenseStatus::Distributed,
            'paid_at' => $fundedAmount >= $expenseAmount ? now() : null,
        ];

        $expense->update($attributes);
    }

    private function ensureLegacyFundingSeeded(Expense $expense): void
    {
        $existingCollections = ExpenseFundingPayment::query()
            ->where('expense_id', $expense->id)
            ->exists();

        if ($existingCollections || (float) $expense->funded_amount <= 0) {
            return;
        }

        ExpenseFundingPayment::query()->create([
            'expense_id' => $expense->id,
            'amount' => (float) $expense->funded_amount,
            'payment_date' => $expense->paid_at?->toDateString() ?? $expense->expense_date->toDateString(),
            'notes' => 'Ajuste histórico inicial previo a trazabilidad de egresos.',
            'created_by' => $expense->created_by,
            'updated_by' => $expense->created_by,
        ]);
    }
}
