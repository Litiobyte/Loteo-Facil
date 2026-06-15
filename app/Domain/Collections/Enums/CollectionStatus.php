<?php

namespace App\Domain\Collections\Enums;

enum CollectionStatus: string
{
    case PendingApplication = 'pending_application';
    case PartiallyApplied = 'partially_applied';
    case FullyApplied = 'fully_applied';
    case Cancelled = 'cancelled';
}
