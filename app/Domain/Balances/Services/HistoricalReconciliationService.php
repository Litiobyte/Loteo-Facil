<?php

namespace App\Domain\Balances\Services;

use App\Domain\Accounting\Services\HistoricalPeriodGuard;
use App\Domain\Collections\Services\CollectionApplicationService;
use App\Domain\Imports\Enums\HistoricalAdjustmentCategory;
use App\Domain\Imports\Enums\HistoricalEvidenceState;
use App\Domain\Imports\Services\HistoricalAdjustmentService;
use App\Models\Collection;
use App\Models\HistoricalAdjustment;
use App\Models\HistoricalReconciliation;
use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Conciliación histórica: compara la deuda esperada con la calculada,
 * registra pagos reconocidos sin comprobante (dinero real, sin inventar evidencia)
 * y clasifica diferencias mediante ajustes auditados. Ninguna corrección es automática.
 */
class HistoricalReconciliationService
{
    public function __construct(
        private readonly HistoricalAdjustmentService $adjustments,
        private readonly CollectionApplicationService $applications,
        private readonly HistoricalPeriodGuard $guard,
        private readonly PartnerBalanceService $balances,
    ) {}

    /**
     * Desglose de conciliación por propietario.
     *
     * @return array{total_charges: float, total_applied: float, total_non_monetary_applied: float,
     *     unapplied_collections: float, available_credit: float, calculated_debt: float,
     *     expected_debt: ?float, difference: ?float, difference_sign: ?string,
     *     pending_verification: array{count: int, amount: float},
     *     recognized_collections: array{count: int, amount: float},
     *     unexplained_differences: array{count: int, amount: float},
     *     monthly_unattributed: array{count: int, items: list<array{id: int, category: string, amount: string, precision: string, year: ?int, source: string}}}
     */
    public function breakdown(Propietario $owner, ?string $expectedDebt = null): array
    {
        $calculated = $this->balances->getPendingBalance($owner);
        $difference = null;
        $differenceSign = null;
        if ($expectedDebt !== null) {
            $expected = $this->nonNegativeAmount($expectedDebt);
            $difference = round($calculated - $expected, 2);
            $differenceSign = $difference > 0.009 ? 'deuda_mayor_que_esperada' : ($difference < -0.009 ? 'deuda_menor_que_esperada' : 'balanced');
        }

        $pendingVerification = HistoricalAdjustment::query()
            ->where('propietario_id', $owner->id)
            ->where('evidence_status', HistoricalEvidenceState::PendingVerification->value)
            ->selectRaw('count(*) as count, coalesce(sum(amount), 0) as amount')->first();
        $recognized = Collection::query()
            ->where('propietario_id', $owner->id)
            ->where('evidence_status', HistoricalEvidenceState::Recognized->value)
            ->selectRaw('count(*) as count, coalesce(sum(amount), 0) as amount')->first();
        $unexplained = HistoricalAdjustment::query()
            ->where('propietario_id', $owner->id)
            ->where('category', HistoricalAdjustmentCategory::UnexplainedDifference->value)
            ->selectRaw('count(*) as count, coalesce(sum(amount), 0) as amount')->first();

        $unattributed = HistoricalAdjustment::query()
            ->where('propietario_id', $owner->id)
            ->whereIn('date_precision', ['year', 'unknown'])
            ->orderBy('id')
            ->get(['id', 'category', 'amount', 'date_precision', 'effective_year', 'source']);

        return [
            'total_charges' => $this->balances->getTotalCharges($owner),
            'total_applied' => $this->balances->getTotalApplied($owner),
            'total_non_monetary_applied' => $this->balances->getTotalNonMonetaryApplied($owner),
            'unapplied_collections' => round((float) Collection::query()
                ->where('propietario_id', $owner->id)
                ->whereNotIn('status', ['cancelled'])
                ->sum('unapplied_amount'), 2),
            'available_credit' => $this->balances->getCreditBalance($owner),
            'calculated_debt' => $calculated,
            'expected_debt' => $expectedDebt === null ? null : $this->nonNegativeAmount($expectedDebt),
            'difference' => $difference,
            'difference_sign' => $differenceSign,
            'pending_verification' => ['count' => (int) $pendingVerification->count, 'amount' => round((float) $pendingVerification->amount, 2)],
            'recognized_collections' => ['count' => (int) $recognized->count, 'amount' => round((float) $recognized->amount, 2)],
            'unexplained_differences' => ['count' => (int) $unexplained->count, 'amount' => round((float) $unexplained->amount, 2)],
            'monthly_unattributed' => [
                'count' => $unattributed->count(),
                'items' => $unattributed->map(fn (HistoricalAdjustment $item): array => [
                    'id' => $item->id,
                    'category' => $item->category->value,
                    'amount' => (string) $item->amount,
                    'precision' => (string) $item->date_precision,
                    'year' => $item->effective_year,
                    'source' => (string) $item->source,
                ])->all(),
            ],
        ];
    }

    /**
     * Reconoce un pago histórico sin comprobante (dinero efectivamente recibido según administración).
     * Crea una recaudación real marcada como `recognized` (impacta caja, con evidencia distinguida),
     * la aplica manualmente a los cobros seleccionados con la fecha del pago y deja auditoría.
     *
     * @param  array<int, array{charge_id: int, amount: string|float}>  $allocations
     * @return array{collection: Collection, reconciled: bool, record: HistoricalReconciliation}
     */
    public function recognizeHistoricalPayment(
        Propietario $owner,
        User $authorizer,
        string $amount,
        string $paymentDate,
        array $allocations,
        string $source,
        ?string $reference = null,
        ?string $observations = null,
    ): array {
        $this->adjustments->authorize($authorizer);
        $cents = $this->adjustments->cents($amount);
        $date = $this->exactDate($paymentDate);
        $items = [];
        $sum = 0;
        foreach ($allocations as $item) {
            $chargeId = (int) ($item['charge_id'] ?? 0);
            $chargeAmount = round((float) ($item['amount'] ?? 0), 2);
            if ($chargeId <= 0 || $chargeAmount <= 0) {
                throw new DomainException('Cada aplicación requiere cobro y monto positivos.');
            }
            $items[] = ['charge_id' => $chargeId, 'amount' => $chargeAmount];
            $sum = round($sum + $chargeAmount, 2);
        }
        if ($items === []) {
            throw new DomainException('Indique los cobros cubiertos por el pago reconocido.');
        }
        if (abs($sum - $cents / 100) > 0.009) {
            throw new DomainException('La suma de las aplicaciones debe coincidir con el monto del pago.');
        }
        if (trim($source) === '') {
            throw new DomainException('Debe indicar la fuente del reconocimiento.');
        }

        $this->guard->validate($date);
        $this->adjustments->assertDateAfterOpening($owner->id, $date);

        $payload = DB::transaction(function () use ($owner, $authorizer, $date, $items, $source, $reference, $observations, $cents): array {
            Propietario::query()->lockForUpdate()->findOrFail($owner->id);
            $this->guard->lockAndValidate($date);
            $this->adjustments->assertDateAfterOpening($owner->id, $date);

            /** @var Collection $collection */
            $collection = Collection::query()->create([
                'propietario_id' => $owner->id,
                'amount' => $cents / 100,
                'applied_amount' => 0,
                'unapplied_amount' => $cents / 100,
                'collection_date' => $date,
                'collection_method' => 'otro',
                'reference' => $reference,
                'notes' => json_encode([
                    'recognition' => 'historical_payment',
                    'evidence' => 'recognized',
                    'source' => trim($source),
                    'observations' => $observations,
                ], JSON_THROW_ON_ERROR),
                'status' => 'pending_application',
                'evidence_status' => HistoricalEvidenceState::Recognized->value,
                'created_by' => $authorizer->id,
            ]);

            $applied = $this->applications->applyCollectionManually(
                $collection,
                $items,
                Carbon::parse($date),
                $authorizer->id,
            );

            $record = HistoricalReconciliation::query()->create([
                'propietario_id' => $owner->id,
                'kind' => HistoricalReconciliation::KindPaymentRecognition,
                'cutoff_date' => $date,
                'expected_debt' => null,
                'calculated_debt' => null,
                'difference' => null,
                'final_calculated_debt' => $this->balances->getPendingBalance($owner),
                'status' => HistoricalEvidenceState::Reconciled->value,
                'summary' => [
                    'collection_id' => $collection->id,
                    'allocations' => $applied->map(fn ($allocation): array => [
                        'allocation_id' => $allocation->id,
                        'charge_id' => $allocation->partner_charge_id,
                        'amount' => (string) $allocation->amount,
                    ])->all(),
                ],
                'source' => trim($source),
                'reference' => $reference,
                'observations' => $observations,
                'authorized_by' => $authorizer->id,
            ]);

            return ['collection' => $collection, 'reconciled' => true, 'record' => $record];
        }, 3);

        return $payload;
    }

    /**
     * Concilia la deuda esperada con la calculada. La clasificación de la diferencia
     * es una decisión del administrador, nunca automática.
     *
     * @param  array{category: string, charge_id?: ?int, amount: string, effective_date?: ?string}|null  $classification
     * @return array{record: HistoricalReconciliation, breakdown: array<string, mixed>, adjustment: ?HistoricalAdjustment}
     */
    public function reconcile(
        Propietario $owner,
        User $authorizer,
        string $expectedDebt,
        string $source,
        ?string $cutoffDate = null,
        ?array $classification = null,
        ?string $reference = null,
        ?string $observations = null,
    ): array {
        $this->adjustments->authorize($authorizer);
        $expected = $this->nonNegativeAmount($expectedDebt);
        if (trim($source) === '') {
            throw new DomainException('Debe indicar la fuente del saldo esperado.');
        }

        $adjustment = null;
        $payload = DB::transaction(function () use ($owner, $authorizer, $expected, $source, $cutoffDate, $classification, $reference, $observations, &$adjustment): array {
            Propietario::query()->lockForUpdate()->findOrFail($owner->id);
            $calculated = $this->balances->getPendingBalance($owner);
            $difference = round($calculated - $expected, 2);

            if ($classification !== null) {
                $category = HistoricalAdjustmentCategory::from($classification['category']);
                if (! in_array($category, [HistoricalAdjustmentCategory::DebtCorrection, HistoricalAdjustmentCategory::DebtForgiveness, HistoricalAdjustmentCategory::UnexplainedDifference], true)) {
                    throw new DomainException('Categoría de clasificación no válida para conciliación.');
                }
                $amount = $this->adjustments->cents((string) $classification['amount']);
                if ($amount > (int) round(abs($difference) * 100) + 0) {
                    throw new DomainException('El ajuste no puede exceder la diferencia conciliada.');
                }
                if ($amount > (int) round($calculated * 100)) {
                    throw new DomainException('El ajuste no puede exceder la deuda calculada.');
                }
                $effective = $classification['effective_date'] ?? Carbon::today()->toDateString();
                $adjustment = $this->adjustments->create(
                    $owner,
                    $authorizer,
                    $category,
                    $this->format($amount),
                    $effective,
                    'day',
                    ['source' => trim($source), 'reference' => $reference, 'observations' => $observations, 'from_import' => false],
                    in_array($category, [HistoricalAdjustmentCategory::DebtCorrection, HistoricalAdjustmentCategory::DebtForgiveness], true) ? (int) $classification['charge_id'] : null,
                );
                $adjustment->evidence_status = HistoricalEvidenceState::Reconciled->value;
                $adjustment->save();
            }

            $final = $this->balances->getPendingBalance($owner);
            $status = abs(round($final - $expected, 2)) <= 0.009
                ? HistoricalEvidenceState::Reconciled->value
                : HistoricalEvidenceState::PendingVerification->value;

            $record = HistoricalReconciliation::query()->create([
                'propietario_id' => $owner->id,
                'kind' => HistoricalReconciliation::KindDifferenceClassification,
                'cutoff_date' => $cutoffDate,
                'expected_debt' => $expected,
                'calculated_debt' => $calculated,
                'difference' => $difference,
                'final_calculated_debt' => $final,
                'status' => $status,
                'summary' => [
                    'adjustment_id' => $adjustment?->id,
                    'classification' => $classification === null ? null : $classification['category'],
                ],
                'source' => trim($source),
                'reference' => $reference,
                'observations' => $observations,
                'authorized_by' => $authorizer->id,
            ]);

            return [
                'record' => $record,
                'breakdown' => $this->breakdown($owner, number_format($expected, 2, '.', '')),
                'adjustment' => $adjustment,
            ];
        }, 3);

        return $payload;
    }

    /**
     * Reporte de conciliación por propietario: desglose, historial de conciliaciones
     * y registros incompletos (pendientes de verificación, reconocidos sin comprobante,
     * diferencias no explicadas y ajustes sin imputación mensual).
     *
     * @return array{breakdown: array<string, mixed>, reconciliations: list<array<string, mixed>>, incomplete_records: array<string, mixed>}
     */
    public function report(Propietario $owner, ?string $expectedDebt = null): array
    {
        $view = $this->breakdown($owner, $expectedDebt);
        $reconciliations = HistoricalReconciliation::query()
            ->where('propietario_id', $owner->id)
            ->orderByDesc('id')
            ->get();

        return [
            'breakdown' => $view,
            'reconciliations' => $reconciliations->map(fn (HistoricalReconciliation $record): array => [
                'id' => $record->id,
                'kind' => $record->kind,
                'status' => $record->status,
                'cutoff_date' => $record->cutoff_date?->toDateString(),
                'expected_debt' => $record->expected_debt,
                'calculated_debt' => $record->calculated_debt,
                'difference' => $record->difference,
                'final_calculated_debt' => $record->final_calculated_debt,
                'summary' => $record->summary,
                'source' => $record->source,
                'authorized_by' => $record->authorized_by,
            ])->all(),
            'incomplete_records' => [
                'pending_verification_adjustments' => HistoricalAdjustment::query()
                    ->where('propietario_id', $owner->id)
                    ->where('evidence_status', HistoricalEvidenceState::PendingVerification->value)
                    ->get(['id', 'category', 'amount', 'date_precision', 'source'])
                    ->map(fn (HistoricalAdjustment $item): array => [
                        'id' => $item->id,
                        'category' => $item->category->value,
                        'amount' => (string) $item->amount,
                        'precision' => (string) $item->date_precision,
                        'source' => (string) $item->source,
                    ])->all(),
                'recognized_collections' => Collection::query()
                    ->where('propietario_id', $owner->id)
                    ->where('evidence_status', HistoricalEvidenceState::Recognized->value)
                    ->get(['id', 'amount', 'collection_date', 'reference'])
                    ->map(fn (Collection $item): array => [
                        'id' => $item->id,
                        'amount' => (string) $item->amount,
                        'collection_date' => $item->collection_date?->toDateString(),
                        'reference' => $item->reference,
                    ])->all(),
                'monthly_unattributed' => $view['monthly_unattributed'],
            ],
        ];
    }

    private function exactDate(string $value): string
    {
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            throw new DomainException('La fecha del pago debe ser completa (Y-m-d).');
        }
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new DomainException('La fecha del pago debe ser completa (Y-m-d).');
        }

        return $value;
    }

    private function nonNegativeAmount(string $value): float
    {
        if (! preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new DomainException('El monto debe ser decimal no negativo con hasta dos decimales.');
        }

        return round((float) $value, 2);
    }

    private function format(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
