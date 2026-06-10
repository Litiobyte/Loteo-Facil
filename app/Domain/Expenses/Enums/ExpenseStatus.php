<?php

namespace App\Domain\Expenses\Enums;

enum ExpenseStatus: string
{
    case Registered = 'registered';
    case Distributed = 'distributed';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
