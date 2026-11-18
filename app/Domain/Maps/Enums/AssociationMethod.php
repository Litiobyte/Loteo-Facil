<?php

namespace App\Domain\Maps\Enums;

enum AssociationMethod: string
{
    case Auto = 'auto';
    case Manual = 'manual';
    case Carried = 'carried';
}
