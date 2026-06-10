<?php

namespace App\Domain\Balances\Services;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Models\ExpenseFundingPayment;
use App\Models\Payment;

class CashBalanceService
{
    public function getAvailableCash(): float
    {
        return round($this->getTotalIncome() - $this->getTotalFundedExpenses(), 2);
    }

    public function getProjectedCashAfterFunding(float $fundingAmount): float
    {
        return round($this->getAvailableCash() - round($fundingAmount, 2), 2);
    }

    public function getTotalIncome(): float
    {
        return round((float) Payment::query()
            ->where('status', '!=', PaymentStatus::Cancelled->value)
            ->sum('amount'), 2);
    }

    public function getTotalFundedExpenses(): float
    {
        return round((float) ExpenseFundingPayment::query()
            ->where('is_void', false)
            ->sum('amount'), 2);
    }
}
