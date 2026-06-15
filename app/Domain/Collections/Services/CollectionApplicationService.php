<?php

namespace App\Domain\Collections\Services;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Collections\Enums\CollectionStatus;
use App\Models\Collection;
use App\Models\CollectionAllocation;
use App\Models\PartnerCharge;
use DomainException;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class CollectionApplicationService
{
    /**
     * Apply payment automatically to oldest unpaid charges (FIFO).
     *
     * @return SupportCollection<int, CollectionAllocation>
     *
     * @throws DomainException
     */
    public function applyCollectionAutomatically(Collection $payment): SupportCollection
    {
        $this->validateCollectionCanBeApplied($payment);

        $availableCharges = $this->getAvailableCharges($payment);

        if ($availableCharges->isEmpty()) {
            throw new DomainException('No hay cobros pendientes para aplicar este pago.');
        }

        return DB::transaction(function () use ($payment, $availableCharges): SupportCollection {
            $allocations = new SupportCollection;
            $remainingCollection = round((float) $payment->unapplied_amount, 2);

            foreach ($availableCharges as $charge) {
                if ($remainingCollection <= 0) {
                    break;
                }

                $chargeRemaining = round((float) $charge->remaining_amount, 2);
                $allocationAmount = min($remainingCollection, $chargeRemaining);

                if ($allocationAmount <= 0) {
                    continue;
                }

                $allocation = $this->createAllocation($payment, $charge, $allocationAmount);
                $this->updateChargeFromAllocation($charge, $allocationAmount);

                $allocations->push($allocation);
                $remainingCollection = round($remainingCollection - $allocationAmount, 2);
            }

            $this->updateCollectionFromAllocations($payment);

            return $allocations;
        });
    }

    /**
     * Apply payment manually to specific charges.
     *
     * @param  array<int, array{charge_id: int, amount: float}>  $chargeAllocations
     * @return SupportCollection<int, CollectionAllocation>
     *
     * @throws DomainException
     */
    public function applyCollectionManually(Collection $payment, array $chargeAllocations): SupportCollection
    {
        $this->validateCollectionCanBeApplied($payment);

        if (empty($chargeAllocations)) {
            throw new DomainException('Debe especificar al menos un cobro para aplicar el pago.');
        }

        $this->validateManualAllocations($payment, $chargeAllocations);

        return DB::transaction(function () use ($payment, $chargeAllocations): SupportCollection {
            $allocations = new SupportCollection;

            foreach ($chargeAllocations as $item) {
                $charge = PartnerCharge::query()->lockForUpdate()->findOrFail($item['charge_id']);
                $amount = round((float) $item['amount'], 2);

                if ($charge->propietario_id !== $payment->propietario_id) {
                    throw new DomainException("El cobro #{$charge->id} no pertenece al propietario del pago.");
                }

                if ($amount > (float) $charge->remaining_amount) {
                    throw new DomainException("El monto para el cobro #{$charge->id} excede su saldo pendiente.");
                }

                $allocation = $this->createAllocation($payment, $charge, $amount);
                $this->updateChargeFromAllocation($charge, $amount);

                $allocations->push($allocation);
            }

            $this->updateCollectionFromAllocations($payment);

            return $allocations;
        });
    }

    /**
     * Reverse/cancel a payment allocation.
     * Updates charge and payment states.
     *
     * @throws DomainException
     */
    public function reverseAllocation(CollectionAllocation $allocation): void
    {
        DB::transaction(function () use ($allocation): void {
            $payment = Collection::query()->lockForUpdate()->findOrFail($allocation->collection_id);
            $charge = PartnerCharge::query()->lockForUpdate()->findOrFail($allocation->partner_charge_id);

            if ($payment->status === CollectionStatus::Cancelled) {
                throw new DomainException('No se puede revertir una aplicación de un pago cancelado.');
            }

            $allocationAmount = round((float) $allocation->amount, 2);

            // Delete allocation record first
            $allocation->delete();

            // Revert charge state
            $charge->paid_amount = round((float) $charge->paid_amount - $allocationAmount, 2);
            $charge->remaining_amount = round((float) $charge->amount - (float) $charge->paid_amount, 2);
            $charge->status = $this->calculateChargeStatus($charge);
            $charge->save();

            // Revert payment state - update amounts first, then recalculate status
            $newAppliedAmount = round((float) $payment->applied_amount - $allocationAmount, 2);
            $newUnappliedAmount = round((float) $payment->amount - $newAppliedAmount, 2);
            $newStatus = $this->calculateCollectionStatusFromAmounts($payment->amount, $newAppliedAmount, $newUnappliedAmount);

            // Use updateQuietly to bypass model validation during reversal
            $payment->updateQuietly([
                'applied_amount' => $newAppliedAmount,
                'unapplied_amount' => $newUnappliedAmount,
                'status' => $newStatus,
            ]);
        });
    }

    /**
     * Get available charges for a payment (unpaid charges for same propietario).
     * Ordered by due_date ASC, created_at ASC (oldest first - FIFO).
     *
     * @return SupportCollection<int, PartnerCharge>
     */
    public function getAvailableCharges(Collection $payment): SupportCollection
    {
        return PartnerCharge::query()
            ->where('propietario_id', $payment->propietario_id)
            ->unpaid()
            ->orderByRaw('due_date ASC NULLS LAST')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Calculate how a payment would be distributed automatically.
     * Does NOT save, just returns preview.
     *
     * @return array{
     *     allocations: array<int, array{charge_id: int, charge_description: string, charge_remaining: float, allocation_amount: float}>,
     *     total_allocated: float,
     *     remaining_credit: float
     * }
     */
    public function previewAutomaticAllocation(Collection $payment): array
    {
        $availableCharges = $this->getAvailableCharges($payment);
        $remainingCollection = round((float) $payment->unapplied_amount, 2);
        $allocations = [];
        $totalAllocated = 0.0;

        foreach ($availableCharges as $charge) {
            if ($remainingCollection <= 0) {
                break;
            }

            $chargeRemaining = round((float) $charge->remaining_amount, 2);
            $allocationAmount = min($remainingCollection, $chargeRemaining);

            if ($allocationAmount <= 0) {
                continue;
            }

            $allocations[] = [
                'charge_id' => $charge->id,
                'charge_description' => $charge->description ?? "Cobro #{$charge->id}",
                'charge_remaining' => $chargeRemaining,
                'allocation_amount' => $allocationAmount,
            ];

            $totalAllocated = round($totalAllocated + $allocationAmount, 2);
            $remainingCollection = round($remainingCollection - $allocationAmount, 2);
        }

        return [
            'allocations' => $allocations,
            'total_allocated' => $totalAllocated,
            'remaining_credit' => $remainingCollection,
        ];
    }

    /**
     * Validate that payment can be applied.
     *
     * @throws DomainException
     */
    private function validateCollectionCanBeApplied(Collection $payment): void
    {
        if ($payment->status === CollectionStatus::Cancelled) {
            throw new DomainException('No se puede aplicar un pago cancelado.');
        }

        if ($payment->status === CollectionStatus::FullyApplied) {
            throw new DomainException('Este pago ya está completamente aplicado.');
        }

        if ((float) $payment->unapplied_amount <= 0) {
            throw new DomainException('No hay saldo disponible para aplicar en este pago.');
        }
    }

    /**
     * Validate manual allocations before applying.
     *
     * @param  array<int, array{charge_id: int, amount: float}>  $chargeAllocations
     *
     * @throws DomainException
     */
    private function validateManualAllocations(Collection $payment, array $chargeAllocations): void
    {
        $totalRequested = 0.0;

        foreach ($chargeAllocations as $item) {
            if (! isset($item['charge_id'], $item['amount'])) {
                throw new DomainException('Cada asignación debe tener charge_id y amount.');
            }

            $amount = (float) $item['amount'];

            if ($amount <= 0) {
                throw new DomainException('El monto de cada asignación debe ser mayor a cero.');
            }

            $totalRequested = round($totalRequested + $amount, 2);
        }

        if ($totalRequested > (float) $payment->unapplied_amount) {
            throw new DomainException('La suma de las asignaciones excede el saldo disponible del pago.');
        }
    }

    /**
     * Create a payment allocation record.
     */
    private function createAllocation(Collection $payment, PartnerCharge $charge, float $amount): CollectionAllocation
    {
        return CollectionAllocation::create([
            'collection_id' => $payment->id,
            'partner_charge_id' => $charge->id,
            'amount' => round($amount, 2),
            'allocated_at' => now(),
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Update charge state after allocation is applied.
     */
    private function updateChargeFromAllocation(PartnerCharge $charge, float $allocationAmount): void
    {
        $charge->paid_amount = round((float) $charge->paid_amount + $allocationAmount, 2);
        $charge->remaining_amount = round((float) $charge->amount - (float) $charge->paid_amount, 2);
        $charge->status = $this->calculateChargeStatus($charge);
        $charge->save();
    }

    /**
     * Update payment state after allocations are applied.
     */
    private function updateCollectionFromAllocations(Collection $payment): void
    {
        $totalApplied = CollectionAllocation::query()
            ->where('collection_id', $payment->id)
            ->sum('amount');

        $payment->applied_amount = round((float) $totalApplied, 2);
        $payment->unapplied_amount = round((float) $payment->amount - (float) $payment->applied_amount, 2);
        $payment->status = $this->calculateCollectionStatus($payment);
        $payment->save();
    }

    /**
     * Calculate charge status based on paid_amount and remaining_amount.
     */
    private function calculateChargeStatus(PartnerCharge $charge): ChargeStatus
    {
        $remaining = round((float) $charge->remaining_amount, 2);
        $paid = round((float) $charge->paid_amount, 2);

        if ($remaining <= 0.0) {
            return ChargeStatus::Paid;
        }

        if ($paid > 0.0) {
            return ChargeStatus::Partial;
        }

        return ChargeStatus::Pending;
    }

    /**
     * Calculate payment status based on applied_amount and unapplied_amount.
     */
    private function calculateCollectionStatus(Collection $payment): CollectionStatus
    {
        $unapplied = round((float) $payment->unapplied_amount, 2);
        $applied = round((float) $payment->applied_amount, 2);

        return $this->calculateCollectionStatusFromAmounts($payment->amount, $applied, $unapplied);
    }

    /**
     * Calculate payment status from amounts (for use during reversal).
     */
    private function calculateCollectionStatusFromAmounts(float $total, float $applied, float $unapplied): CollectionStatus
    {
        $unapplied = round($unapplied, 2);
        $applied = round($applied, 2);

        if ($unapplied <= 0.0 && $applied > 0.0) {
            return CollectionStatus::FullyApplied;
        }

        if ($applied > 0.0) {
            return CollectionStatus::PartiallyApplied;
        }

        return CollectionStatus::PendingApplication;
    }
}
