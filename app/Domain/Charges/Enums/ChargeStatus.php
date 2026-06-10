<?php

namespace App\Domain\Charges\Enums;

enum ChargeStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
