<?php

namespace App\Domain\Maps\Enums;

enum AssociationMatch: string
{
    case Exact = 'exact';
    case Probable = 'probable';
    case Ambiguous = 'ambiguous';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Exacta',
            self::Probable => 'Probable',
            self::Ambiguous => 'Ambigua',
            self::None => 'Sin coincidencia',
        };
    }
}
