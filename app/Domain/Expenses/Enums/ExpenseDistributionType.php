<?php

namespace App\Domain\Expenses\Enums;

enum ExpenseDistributionType: string
{
    case EqualByPartner = 'equal_by_partner';
    case ProportionalByHectares = 'proportional_by_hectares';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::EqualByPartner => 'Partes iguales',
            self::ProportionalByHectares => 'Proporcional por hectáreas',
            self::Manual => 'Manual',
        };
    }
}
