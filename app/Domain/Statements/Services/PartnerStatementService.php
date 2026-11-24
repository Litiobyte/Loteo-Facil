<?php

namespace App\Domain\Statements\Services;

use App\Domain\Balances\Services\PartnerBalanceService;
use App\Models\Collection as CollectionModel;
use App\Models\CollectionAllocation;
use App\Models\HistoricalAdjustment;
use App\Models\HistoricalAdjustmentAllocation;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

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
     *     lots: SupportCollection,
     *     summary: array,
     *     charges: SupportCollection,
     *     collections: SupportCollection,
     *     allocations: SupportCollection,
     *     timeline: SupportCollection
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
            'collections' => $this->getCollectionsDetail($propietario, $from, $to),
            'allocations' => $this->getAllocationsDetail($propietario, $from, $to),
            'timeline' => $this->getMovementTimeline($propietario, $from, $to),
        ];
    }

    /**
     * Get detailed charge information.
     *
     * @return SupportCollection<int, PartnerCharge>
     */
    public function getChargesDetail(
        Propietario $propietario,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): SupportCollection {
        $query = PartnerCharge::query()
            ->with(['expense.category', 'allocations'])
            ->where('propietario_id', $propietario->id)
            ->orderBy('due_date', 'asc')
            ->orderBy('created_at', 'desc');

        if ($from) {
            $query->where(function ($query) use ($from): void {
                $query->whereDate('imputation_date', '>=', $from)
                    ->orWhere(fn ($q) => $q->whereNull('imputation_date')->whereHas('expense', fn ($expense) => $expense->whereDate('due_date', '>=', $from)))
                    ->orWhere(fn ($q) => $q->whereNull('imputation_date')
                        ->where(fn ($q) => $q->whereDoesntHave('expense')->orWhereHas('expense', fn ($expense) => $expense->whereNull('due_date')))
                        ->whereDate('created_at', '>=', $from));
            });
        }

        if ($to) {
            $query->where(function ($query) use ($to): void {
                $query->whereDate('imputation_date', '<=', $to)
                    ->orWhere(fn ($q) => $q->whereNull('imputation_date')->whereHas('expense', fn ($expense) => $expense->whereDate('due_date', '<=', $to)))
                    ->orWhere(fn ($q) => $q->whereNull('imputation_date')
                        ->where(fn ($q) => $q->whereDoesntHave('expense')->orWhereHas('expense', fn ($expense) => $expense->whereNull('due_date')))
                        ->whereDate('created_at', '<=', $to));
            });
        }

        return $query->get();
    }

    /**
     * Get detailed payment information.
     *
     * @return SupportCollection<int, CollectionModel>
     */
    public function getCollectionsDetail(
        Propietario $propietario,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): SupportCollection {
        $query = CollectionModel::query()
            ->with('allocations.charge')
            ->where('propietario_id', $propietario->id)
            ->orderBy('collection_date', 'desc')
            ->orderBy('created_at', 'desc');

        if ($from) {
            $query->whereDate('collection_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('collection_date', '<=', $to);
        }

        return $query->get();
    }

    /**
     * Get detailed allocation information.
     *
     * @return SupportCollection<int, CollectionAllocation>
     */
    public function getAllocationsDetail(
        Propietario $propietario,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): SupportCollection {
        $query = CollectionAllocation::query()
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
     * @return SupportCollection<int, array{
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
    ): SupportCollection {
        $movements = new SupportCollection;

        // Add charges
        $charges = $this->getChargesDetail($propietario, $from, $to);
        foreach ($charges as $charge) {
            $movements->push([
                'date' => $charge->imputation_date ?? $charge->expense?->due_date ?? $charge->created_at,
                'date_precision' => 'day',
                'type' => 'charge',
                'description' => $charge->description ?? "Cobro #{$charge->id}",
                'amount' => (float) $charge->amount,
                'balance_impact' => $charge->charge_type === 'balance_consolidation' ? 0.0 : (float) $charge->amount,
                'related_id' => $charge->id,
                'related_type' => 'PartnerCharge',
                'status' => $charge->status->value,
            ]);
        }

        $adjustments = HistoricalAdjustment::query()->where('propietario_id', $propietario->id)->get();
        foreach ($adjustments as $adjustment) {
            $start = $adjustment->effective_date?->copy()
                ?? Carbon::create($adjustment->effective_year, $adjustment->effective_month ?? 1, 1)->startOfDay();
            $end = match ($adjustment->date_precision) {
                'year' => $start->copy()->endOfYear(),
                'month' => $start->copy()->endOfMonth(),
                default => $start->copy()->endOfDay(),
            };
            if (($from && $end->lt($from->copy()->startOfDay())) || ($to && $start->gt($to->copy()->endOfDay()))) {
                continue;
            }
            $movements->push([
                'date' => $adjustment->effective_date,
                'date_precision' => $adjustment->date_precision,
                'date_label' => match ($adjustment->date_precision) {
                    'month' => sprintf('%04d-%02d', $adjustment->effective_year, $adjustment->effective_month),
                    'year' => (string) $adjustment->effective_year,
                    default => $adjustment->effective_date?->format('d/m/Y') ?? 'Desconocida',
                },
                'type' => 'historical_adjustment',
                'description' => $adjustment->category->value,
                'amount' => (float) $adjustment->amount,
                'balance_impact' => $adjustment->category->value === 'unexplained_difference' ? 0.0 : -(float) $adjustment->amount,
                'related_id' => $adjustment->id,
                'related_type' => 'HistoricalAdjustment',
                'status' => $adjustment->evidence_status,
            ]);
        }

        // Add collections
        $collections = $this->getCollectionsDetail($propietario, $from, $to);
        foreach ($collections as $payment) {
            $movements->push([
                'date' => $payment->collection_date,
                'type' => 'payment',
                'description' => "Recaudacion {$payment->collection_method->getLabel()} - Ref: ".($payment->reference ?? 'N/A'),
                'amount' => (float) $payment->amount,
                'balance_impact' => -(float) $payment->amount, // Reduces debt
                'related_id' => $payment->id,
                'related_type' => 'Collection',
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
                    $allocation->collection_id,
                    $allocation->partner_charge_id
                ),
                'amount' => (float) $allocation->amount,
                'balance_impact' => 0, // Neutral, just application
                'related_id' => $allocation->id,
                'related_type' => 'CollectionAllocation',
                'status' => 'applied',
            ]);
        }

        $historicalAllocations = HistoricalAdjustmentAllocation::query()
            ->whereHas('adjustment', fn ($query) => $query->where('propietario_id', $propietario->id)->where('category', 'credit_opening'))
            ->when($from, fn ($query) => $query->whereDate('effective_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('effective_date', '<=', $to))
            ->get();
        foreach ($historicalAllocations as $allocation) {
            $movements->push([
                'date' => $allocation->effective_date,
                'date_precision' => 'day',
                'type' => 'allocation',
                'description' => sprintf('Aplicación de crédito histórico #%d a cobro #%d', $allocation->historical_adjustment_id, $allocation->partner_charge_id),
                'amount' => (float) $allocation->amount,
                'balance_impact' => 0,
                'related_id' => $allocation->id,
                'related_type' => 'HistoricalAdjustmentAllocation',
                'credit_adjustment_id' => $allocation->historical_adjustment_id,
                'charge_id' => $allocation->partner_charge_id,
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
     * @return SupportCollection<int, array{
     *     lot_code: string,
     *     square_meters: float,
     *     hectares: float,
     *     status: string
     * }>
     */
    private function getLotsInfo(Propietario $propietario): SupportCollection
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
