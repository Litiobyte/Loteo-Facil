<?php

namespace App\Domain\Imports\Enums;

enum HistoricalAdjustmentCategory: string
{
    case DebtCorrection = 'debt_correction';
    case DebtForgiveness = 'debt_forgiveness';
    case CreditOpening = 'credit_opening';
    case UnexplainedDifference = 'unexplained_difference';
}
