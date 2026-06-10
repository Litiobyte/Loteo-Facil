<?php

namespace App\Domain\Accounting\Enums;

enum AccountingPeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Abierto',
            self::Closed => 'Cerrado',
        };
    }
}
