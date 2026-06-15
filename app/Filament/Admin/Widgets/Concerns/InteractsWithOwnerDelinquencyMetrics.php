<?php

namespace App\Filament\Admin\Widgets\Concerns;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Models\PartnerCharge;
use App\Models\Propietario;

trait InteractsWithOwnerDelinquencyMetrics
{
    /**
     * @return array{active_owners_count: int, delinquent_owners_count: int, owners_on_time_count: int}
     */
    protected function getOwnerDelinquencyMetrics(): array
    {
        $activeOwnerIds = Propietario::query()
            ->join('lote_propietario', 'lote_propietario.propietario_id', '=', 'propietarios.id')
            ->join('lotes', 'lotes.id', '=', 'lote_propietario.lote_id')
            ->where('lote_propietario.status', 'active')
            ->where('lotes.estado', 'vendido')
            ->select('propietarios.id')
            ->distinct()
            ->pluck('id');

        $activeOwnersCount = $activeOwnerIds->count();

        $delinquentOwnersCount = $activeOwnersCount > 0
            ? (int) PartnerCharge::query()
                ->whereIn('propietario_id', $activeOwnerIds)
                ->whereIn('status', [ChargeStatus::Pending->value, ChargeStatus::Partial->value])
                ->distinct('propietario_id')
                ->count('propietario_id')
            : 0;

        return [
            'active_owners_count' => $activeOwnersCount,
            'delinquent_owners_count' => $delinquentOwnersCount,
            'owners_on_time_count' => max(0, $activeOwnersCount - $delinquentOwnersCount),
        ];
    }
}
