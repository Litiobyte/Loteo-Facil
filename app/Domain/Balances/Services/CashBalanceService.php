<?php

namespace App\Domain\Balances\Services;

use App\Domain\Collections\Enums\CollectionStatus;
use App\Models\Collection;
use App\Models\ExpenseFundingPayment;

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
        return round((float) Collection::query()
            ->where('status', '!=', CollectionStatus::Cancelled->value)
            ->sum('amount'), 2);
    }

    public function getTotalFundedExpenses(): float
    {
        return round((float) ExpenseFundingPayment::query()
            ->where('is_void', false)
            ->sum('amount'), 2);
    }
}
