<?php

namespace App\Domain\Payments\Enums;

enum PaymentStatus: string
{
    case PendingApplication = 'pending_application';
    case PartiallyApplied = 'partially_applied';
    case FullyApplied = 'fully_applied';
    case Cancelled = 'cancelled';
}
