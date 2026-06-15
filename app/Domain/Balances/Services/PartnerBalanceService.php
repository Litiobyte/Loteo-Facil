<?php

namespace App\Domain\Balances\Services;

use App\Models\Collection;
use App\Models\PartnerCharge;
use App\Models\Propietario;

class PartnerBalanceService
{
    /**
     * Get pending balance (sum of unpaid charges).
     */
    public function getPendingBalance(Propietario $propietario): float
    {
        $pending = PartnerCharge::query()
            ->where('propietario_id', $propietario->id)
            ->unpaid()
            ->sum('remaining_amount');

        return round((float) $pending, 2);
    }

    /**
     * Get credit balance (sum of unapplied payment amounts).
     */
    public function getCreditBalance(Propietario $propietario): float
    {
        $credit = Collection::query()
            ->where('propietario_id', $propietario->id)
            ->whereIn('status', ['pending_application', 'partially_applied'])
            ->sum('unapplied_amount');

        return round((float) $credit, 2);
    }

    /**
     * Get total charges amount for propietario.
     */
    public function getTotalCharges(Propietario $propietario): float
    {
        $total = PartnerCharge::query()
            ->where('propietario_id', $propietario->id)
            ->sum('amount');

        return round((float) $total, 2);
    }

    /**
     * Get total collections amount for propietario.
     */
    public function getTotalCollections(Propietario $propietario): float
    {
        $total = Collection::query()
            ->where('propietario_id', $propietario->id)
            ->whereNotIn('status', ['cancelled'])
            ->sum('amount');

        return round((float) $total, 2);
    }

    /**
     * Get total applied amount (sum of all payment allocations).
     */
    public function getTotalApplied(Propietario $propietario): float
    {
        $total = Collection::query()
            ->where('propietario_id', $propietario->id)
            ->whereNotIn('status', ['cancelled'])
            ->sum('applied_amount');

        return round((float) $total, 2);
    }

    /**
     * Get comprehensive balance summary for a propietario.
     *
     * @return array{
     *     total_charges: float,
     *     total_collections: float,
     *     total_applied: float,
     *     pending_balance: float,
     *     credit_balance: float,
     *     net_balance: float,
     *     total_hectares: float
     * }
     */
    public function getBalanceSummary(Propietario $propietario): array
    {
        $totalCharges = $this->getTotalCharges($propietario);
        $totalCollections = $this->getTotalCollections($propietario);
        $totalApplied = $this->getTotalApplied($propietario);
        $pendingBalance = $this->getPendingBalance($propietario);
        $creditBalance = $this->getCreditBalance($propietario);
        $totalHectares = $this->getTotalHectares($propietario);

        // Net balance: negative means owes money, positive means has credit
        $netBalance = round($creditBalance - $pendingBalance, 2);

        return [
            'total_charges' => $totalCharges,
            'total_collections' => $totalCollections,
            'total_applied' => $totalApplied,
            'pending_balance' => $pendingBalance,
            'credit_balance' => $creditBalance,
            'net_balance' => $netBalance,
            'total_hectares' => $totalHectares,
        ];
    }

    /**
     * Validate balance consistency for a propietario.
     * Checks that all sums add up correctly.
     *
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateBalanceConsistency(Propietario $propietario): array
    {
        $errors = [];

        // Rule 1: Sum of charge amounts = sum of paid_amount + sum of remaining_amount
        $totalCharges = $this->getTotalCharges($propietario);
        $totalPaid = PartnerCharge::query()
            ->where('propietario_id', $propietario->id)
            ->sum('paid_amount');
        $totalRemaining = PartnerCharge::query()
            ->where('propietario_id', $propietario->id)
            ->sum('remaining_amount');

        $calculatedTotal = round((float) $totalPaid + (float) $totalRemaining, 2);

        if (abs($totalCharges - $calculatedTotal) > 0.01) {
            $errors[] = sprintf(
                'Inconsistencia en cobros: total=%s, pero paid+remaining=%s',
                $totalCharges,
                $calculatedTotal
            );
        }

        // Rule 2: Sum of payment amounts = sum of applied_amount + sum of unapplied_amount
        $totalCollections = $this->getTotalCollections($propietario);
        $totalApplied = $this->getTotalApplied($propietario);
        $totalUnapplied = Collection::query()
            ->where('propietario_id', $propietario->id)
            ->whereNotIn('status', ['cancelled'])
            ->sum('unapplied_amount');

        $calculatedCollectionTotal = round((float) $totalApplied + (float) $totalUnapplied, 2);

        if (abs($totalCollections - $calculatedCollectionTotal) > 0.01) {
            $errors[] = sprintf(
                'Inconsistencia en pagos: total=%s, pero applied+unapplied=%s',
                $totalCollections,
                $calculatedCollectionTotal
            );
        }

        // Rule 3: Total applied should equal total paid on charges
        $chargesTotalPaid = round((float) $totalPaid, 2);
        $collectionsTotalApplied = round((float) $totalApplied, 2);

        if (abs($chargesTotalPaid - $collectionsTotalApplied) > 0.01) {
            $errors[] = sprintf(
                'Inconsistencia entre cobros pagados (%s) y pagos aplicados (%s)',
                $chargesTotalPaid,
                $collectionsTotalApplied
            );
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get total hectares for propietario from active lots.
     */
    private function getTotalHectares(Propietario $propietario): float
    {
        $squareMeters = $propietario->lotes()
            ->wherePivot('status', 'active')
            ->sum('metros_cuadrados');

        return round((float) $squareMeters / 10000, 4);
    }
}
