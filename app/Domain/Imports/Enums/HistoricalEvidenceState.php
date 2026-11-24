<?php

namespace App\Domain\Imports\Enums;

/**
 * Estados de evidencia histórica, independientes de los estados financieros:
 * un movimiento puede estar conciliado matemáticamente y carecer de respaldo documental.
 */
enum HistoricalEvidenceState: string
{
    case Documented = 'documented';
    case Recognized = 'recognized';
    case PendingVerification = 'pending_verification';
    case Reconciled = 'reconciled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Documented => 'Documentado',
            self::Recognized => 'Reconocido',
            self::PendingVerification => 'Pendiente de verificación',
            self::Reconciled => 'Conciliado',
        };
    }
}
