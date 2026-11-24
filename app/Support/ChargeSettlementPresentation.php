<?php

namespace App\Support;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Models\CollectionAllocation;
use App\Models\HistoricalAdjustmentAllocation;
use App\Models\PartnerCharge;
use Illuminate\Database\Eloquent\Builder;

/**
 * Proyección de lectura para pantallas y CSV; no modifica saldos ni infiere pagos de paid_amount.
 */
class ChargeSettlementPresentation
{
    public static function withAmounts(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('partner_charges.*');
        }

        return $query->addSelect([
            'collection_applied_amount' => CollectionAllocation::query()
                ->selectRaw('COALESCE(SUM(amount), 0)')
                ->whereColumn('partner_charge_id', 'partner_charges.id')
                ->whereHas('payment', fn (Builder $payments): Builder => $payments->where('status', '!=', 'cancelled')),
            'non_monetary_applied_amount' => HistoricalAdjustmentAllocation::query()
                ->selectRaw('COALESCE(SUM(amount), 0)')
                ->whereColumn('partner_charge_id', 'partner_charges.id'),
        ]);
    }

    public static function statusLabel(PartnerCharge $charge): string
    {
        $adjusted = (float) $charge->non_monetary_applied_amount > 0;
        $paid = (float) $charge->collection_applied_amount > 0;

        return match ($charge->status) {
            ChargeStatus::Pending => 'Pendiente',
            ChargeStatus::Partial => $adjusted ? 'Parcial · con ajustes' : 'Parcial',
            ChargeStatus::Paid => match (true) {
                $adjusted && $paid => 'Saldado con recaudaciones y ajustes',
                $adjusted => 'Ajustado (sin recaudación)',
                $paid => 'Pagado con recaudaciones',
                default => 'Saldado (sin desglose)',
            },
            ChargeStatus::Cancelled => 'Cancelado',
            ChargeStatus::Consolidated => 'Consolidado',
        };
    }
}
