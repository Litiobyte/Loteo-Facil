<?php

namespace App\Domain\Statements\Services;

use App\Domain\Balances\Services\PartnerBalanceService;
use App\Models\PartnerCharge;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Propietario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PartnerStatementService
{
    public function __construct(
        private readonly PartnerBalanceService $balanceService
    ) {}

    /**
     * Generate comprehensive financial statement for a propietario.
     *
     * @return array{
     *     owner: array,
     *     lots: Collection,
     *     summary: array,
     *     charges: Collection,
     *     payments: Collection,
     *     allocations: Collection,
     *     timeline: Collection
     * }
     */
    public function generateStatement(
        Propietario $propietario,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): array {
        return [
            'owner' => $this->getOwnerInfo($propietario),
            'lots' => $this->getLotsInfo($propietario),
            'summary' => $this->balanceService->getBalanceSummary($propietario),
            'charges' => $this->getChargesDetail($propietario, $from, $to),
            'payments' => $this->getPaymentsDetail($propietario, $from, $to),
            'allocations' => $this->getAllocationsDetail($propietario, $from, $to),
            'timeline' => $this->getMovementTimeline($propietario, $from, $to),
        ];
    }

    /**
     * Get detailed charge information.
     *
     * @return Collection<int, PartnerCharge>
     */
    public function getChargesDetail(
        Propietario $propietario,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): Collection {
        $query = PartnerCharge::query()
            ->with(['expense.category', 'allocations'])
            ->where('propietario_id', $propietario->id)
            ->orderBy('due_date', 'asc')
            ->orderBy('created_at', 'desc');

        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $query->get();
    }

    /**
     * Get detailed payment information.
     *
     * @return Collection<int, Payment>
     */
    public function getPaymentsDetail(
        Propietario $propietario,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): Collection {
        $query = Payment::query()
            ->with('allocations.charge')
            ->where('propietario_id', $propietario->id)
            ->orderBy('payment_date', 'desc')
            ->orderBy('created_at', 'desc');

        if ($from) {
            $query->whereDate('payment_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('payment_date', '<=', $to);
        }

        return $query->get();
    }

    /**
     * Get detailed allocation information.
     *
     * @return Collection<int, PaymentAllocation>
     */
    public function getAllocationsDetail(
        Propietario $propietario,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): Collection {
        $query = PaymentAllocation::query()
            ->with(['payment', 'charge.expense.category'])
            ->whereHas('payment', fn ($q) => $q->where('propietario_id', $propietario->id))
            ->orderBy('allocated_at', 'desc');

        if ($from) {
            $query->whereDate('allocated_at', '>=', $from);
        }

        if ($to) {
            $query->whereDate('allocated_at', '<=', $to);
        }

        return $query->get();
    }

    /**
     * Get chronological timeline of all financial movements.
     *
     * @return Collection<int, array{
     *     date: Carbon,
     *     type: string,
     *     description: string,
     *     amount: float,
     *     balance_impact: float,
     *     related_id: int,
     *     related_type: string
     * }>
     */
    public function getMovementTimeline(
        Propietario $propietario,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): Collection {
        $movements = new Collection;

        // Add charges
        $charges = $this->getChargesDetail($propietario, $from, $to);
        foreach ($charges as $charge) {
            $movements->push([
                'date' => $charge->created_at,
                'type' => 'charge',
                'description' => $charge->description ?? "Cobro #{$charge->id}",
                'amount' => (float) $charge->amount,
                'balance_impact' => (float) $charge->amount, // Increases debt
                'related_id' => $charge->id,
                'related_type' => 'PartnerCharge',
                'status' => $charge->status->value,
            ]);
        }

        // Add payments
        $payments = $this->getPaymentsDetail($propietario, $from, $to);
        foreach ($payments as $payment) {
            $movements->push([
                'date' => $payment->payment_date,
                'type' => 'payment',
                'description' => "Pago {$payment->payment_method->getLabel()} - Ref: ".($payment->reference ?? 'N/A'),
                'amount' => (float) $payment->amount,
                'balance_impact' => -(float) $payment->amount, // Reduces debt
                'related_id' => $payment->id,
                'related_type' => 'Payment',
                'status' => $payment->status->value,
            ]);
        }

        // Add allocations
        $allocations = $this->getAllocationsDetail($propietario, $from, $to);
        foreach ($allocations as $allocation) {
            $movements->push([
                'date' => $allocation->allocated_at,
                'type' => 'allocation',
                'description' => sprintf(
                    'Aplicación de pago #%d a cobro #%d',
                    $allocation->payment_id,
                    $allocation->partner_charge_id
                ),
                'amount' => (float) $allocation->amount,
                'balance_impact' => 0, // Neutral, just application
                'related_id' => $allocation->id,
                'related_type' => 'PaymentAllocation',
                'status' => 'applied',
            ]);
        }

        // Sort by date descending (newest first)
        return $movements->sortByDesc('date')->values();
    }

    /**
     * Get owner basic information.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     rut: string,
     *     email: string|null,
     *     phone: string|null
     * }
     */
    private function getOwnerInfo(Propietario $propietario): array
    {
        return [
            'id' => $propietario->id,
            'name' => $propietario->nombre_completo,
            'rut' => $propietario->rut,
            'email' => $propietario->email,
            'phone' => $propietario->telefono,
        ];
    }

    /**
     * Get lots information with hectares.
     *
     * @return Collection<int, array{
     *     lot_code: string,
     *     square_meters: float,
     *     hectares: float,
     *     status: string
     * }>
     */
    private function getLotsInfo(Propietario $propietario): Collection
    {
        return $propietario->lotes()
            ->wherePivot('status', 'active')
            ->get()
            ->map(fn ($lote) => [
                'lot_code' => $lote->codigo,
                'square_meters' => (float) $lote->metros_cuadrados,
                'hectares' => round((float) $lote->metros_cuadrados / 10000, 4),
                'status' => $lote->pivot->status,
            ]);
    }
}
