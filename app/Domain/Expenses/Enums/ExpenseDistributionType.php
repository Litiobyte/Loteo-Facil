<?php

namespace App\Domain\Expenses\Enums;

use App\Models\CommunitySetting;

enum ExpenseDistributionType: string
{
    case EqualByPartner = 'equal_by_partner';
    case ProportionalByHectares = 'proportional_by_hectares';
    case Manual = 'manual';
    case ProportionalByShares = 'proportional_by_shares';
    case ProportionalByEquivalentArea = 'proportional_by_equivalent_area';

    public function getLabel(): string
    {
        return match ($this) {
            self::EqualByPartner => 'Partes iguales',
            self::ProportionalByHectares => 'Proporcional por hectáreas',
            self::Manual => 'Manual',
            self::ProportionalByShares => 'Proporcional por acciones',
            self::ProportionalByEquivalentArea => 'Proporcional por superficie equivalente',
        };
    }

    /** @return array<string, string> */
    public static function availableOptions(): array
    {
        $settings = CommunitySetting::current();
        $options = [self::EqualByPartner->value => self::EqualByPartner->getLabel()];
        if ($settings->usesLots()) {
            $options[self::ProportionalByHectares->value] = self::ProportionalByHectares->getLabel();
        }
        if ($settings->usesShares()) {
            $options[self::ProportionalByShares->value] = self::ProportionalByShares->getLabel();
            $options[self::ProportionalByEquivalentArea->value] = self::ProportionalByEquivalentArea->getLabel();
        }
        $options[self::Manual->value] = self::Manual->getLabel();

        return $options;
    }
}
