<?php

namespace App\Domain\Imports\Services;

use App\Domain\Accounting\Services\HistoricalPeriodGuard;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Imports\Enums\HistoricalAdjustmentCategory;
use App\Models\Collection;
use App\Models\HistoricalAdjustment;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class HistoricalOpeningService
{
    public function __construct(
        private readonly HistoricalPeriodGuard $guard,
        private readonly HistoricalAdjustmentService $adjustments,
    ) {}

    /**
     * @param  array{source: string, reference?: ?string, observations?: ?string, from_import?: bool}  $metadata
     */
    public function create(Propietario $owner, User $authorizer, string $signedAmount, string $cutoff, array $metadata): PartnerCharge|HistoricalAdjustment|null
    {
        $this->adjustments->authorize($authorizer);
        $this->adjustments->dates($cutoff, 'day');
        if ($signedAmount === '0' || $signedAmount === '0.00' || $signedAmount === '0.0') {
            return null;
        }
        $isCredit = str_starts_with($signedAmount, '-');
        $cents = $this->adjustments->cents($isCredit ? substr($signedAmount, 1) : $signedAmount);
        if (trim($metadata['source'] ?? '') === '') {
            throw new DomainException('Debe indicar la fuente histórica.');
        }
        $this->guard->validate($cutoff);

        return DB::transaction(function () use ($owner, $authorizer, $cutoff, $metadata, $isCredit, $cents): PartnerCharge|HistoricalAdjustment {
            $this->guard->lockAndValidate($cutoff);
            Propietario::query()->lockForUpdate()->findOrFail($owner->id);
            if (PartnerCharge::query()->where('propietario_id', $owner->id)->exists()
                || Collection::query()->where('propietario_id', $owner->id)->exists()
                || HistoricalAdjustment::query()->where('propietario_id', $owner->id)->exists()) {
                throw new DomainException('No se puede establecer una apertura sobre movimientos existentes.');
            }
            if (! $isCredit) {
                return PartnerCharge::query()->create([
                    'propietario_id' => $owner->id,
                    'expense_id' => null,
                    'charge_type' => 'opening_balance',
                    'amount' => $cents / 100,
                    'imputation_date' => $cutoff,
                    'description' => 'Saldo inicial deudor',
                    'calculation_type' => ExpenseDistributionType::Manual,
                    'calculation_notes' => json_encode(['source' => $metadata['source'], 'reference' => $metadata['reference'] ?? null, 'observations' => $metadata['observations'] ?? null, 'concept' => $metadata['concept'] ?? null, 'information_source' => $metadata['information_source'] ?? null, 'authorized_by' => $authorizer->id, 'from_import' => $metadata['from_import'] ?? false], JSON_THROW_ON_ERROR),
                ]);
            }

            return HistoricalAdjustment::query()->create([
                'propietario_id' => $owner->id,
                'category' => HistoricalAdjustmentCategory::CreditOpening,
                'amount' => $cents / 100,
                'direction' => 'credit',
                'effective_date' => $cutoff,
                'effective_year' => (int) substr($cutoff, 0, 4),
                'effective_month' => (int) substr($cutoff, 5, 2),
                'date_precision' => 'day',
                'registered_at' => now(),
                'source' => trim($metadata['source']),
                'reference' => $metadata['reference'] ?? null,
                'observations' => array_key_exists('information_source', $metadata)
                    ? json_encode(['concept' => $metadata['concept'] ?? null, 'information_source' => $metadata['information_source'], 'observations' => $metadata['observations'] ?? null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                    : ($metadata['observations'] ?? null),
                'authorized_by' => $authorizer->id,
                'from_import' => $metadata['from_import'] ?? false,
                'evidence_status' => 'recognized',
            ]);
        }, 3);
    }
}
