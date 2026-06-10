<?php

namespace App\Domain\Expenses\Services;

use App\Models\Expense;

class ExpenseFundingService
{
    public function __construct(
        private readonly ExpenseFundingPaymentService $fundingPaymentService
    ) {}

    public function applyFunding(Expense $expense, float $amount, bool $allowNegativeCash = false): Expense
    {
        $this->fundingPaymentService->register(
            expense: $expense,
            amount: $amount,
            paymentDate: now()->toDateString(),
            notes: 'Registro desde acción rápida de gasto',
            allowNegativeCash: $allowNegativeCash,
        );

        return $expense->fresh() ?? $expense;
    }
}
