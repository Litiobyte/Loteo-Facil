<?php

namespace App\Domain\Payments\Enums;

enum PaymentMethod: string
{
    case Efectivo = 'efectivo';
    case Transferencia = 'transferencia';
    case Cheque = 'cheque';
    case Otro = 'otro';

    public function getLabel(): string
    {
        return match ($this) {
            self::Efectivo => 'Efectivo',
            self::Transferencia => 'Transferencia',
            self::Cheque => 'Cheque',
            self::Otro => 'Otro',
        };
    }
}
