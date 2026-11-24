<?php

namespace App\Domain\Imports\Services;

use App\Domain\Imports\Enums\HistoricalAdjustmentCategory;
use App\Models\HistoricalAdjustment;
use App\Models\HistoricalExternalIdentity;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\Validator;

class FinancialOpeningImportService
{
    public function __construct(private readonly FinancialImportValueParser $parser, private readonly HistoricalOpeningService $openings) {}

    public function normalize(array $data): array
    {
        Validator::make($data, [
            'source' => ['required', 'string', 'max:100'],
            'external_id' => ['required', 'string', 'max:191'],
            'propietario_id' => ['required', 'integer', 'exists:propietarios,id'],
            'cutoff_date' => ['required', 'string'],
            'opening_debt' => ['required', 'string'],
            'opening_credit' => ['required', 'string'],
            'information_source' => ['required', 'string'],
            'concept' => ['nullable', 'string'],
            'reference' => ['nullable', 'string', 'max:255'],
            'observations' => ['nullable', 'string'],
        ])->validate();
        foreach (['source', 'external_id', 'information_source'] as $key) {
            $data[$key] = trim($data[$key]);
            if ($data[$key] === '') {
                throw new DomainException('La fuente y la identidad no pueden estar vacías.');
            }
        }
        foreach (['opening_debt', 'opening_credit'] as $key) {
            $data[$key] = $this->parser->amount($data[$key]);
            if (BigDecimal::of($data[$key])->isLessThan(0)) {
                throw new DomainException('Los saldos iniciales no pueden ser negativos.');
            }
        }
        if (BigDecimal::of($data['opening_debt'])->isGreaterThan(0) && BigDecimal::of($data['opening_credit'])->isGreaterThan(0)) {
            throw new DomainException('Indique deuda o crédito inicial, no ambos.');
        }
        $data['effective_date'] = $this->parser->date($data['cutoff_date']);
        $data['amount'] = BigDecimal::of($data['opening_credit'])->isGreaterThan(0)
            ? '-'.$data['opening_credit'] : $data['opening_debt'];

        return $data;
    }

    public function create(array $data, User $actor): PartnerCharge|HistoricalAdjustment|null
    {
        return $this->openings->create(Propietario::query()->findOrFail($data['propietario_id']), $actor, $data['amount'], $data['effective_date'], [
            'source' => $data['source'], 'reference' => $data['reference'] ?? null,
            'observations' => $data['observations'] ?? null, 'concept' => $data['concept'] ?? null,
            'information_source' => $data['information_source'], 'from_import' => true,
        ]);
    }

    public function validateExisting(HistoricalExternalIdentity $identity, array $data): void
    {
        $movement = match ($identity->identifiable_type) {
            (new PartnerCharge)->getMorphClass() => PartnerCharge::query()->lockForUpdate()->find($identity->identifiable_id),
            (new HistoricalAdjustment)->getMorphClass() => HistoricalAdjustment::query()->lockForUpdate()->find($identity->identifiable_id),
            default => null,
        };
        $credit = $movement instanceof HistoricalAdjustment && $movement->category === HistoricalAdjustmentCategory::CreditOpening;
        $debt = $movement instanceof PartnerCharge && $movement->charge_type === 'opening_balance';
        $metadata = $debt ? json_decode((string) $movement->calculation_notes, true) : ($credit ? json_decode((string) $movement->observations, true) : null);
        if (! $movement || ! ($credit || $debt) || ! is_array($metadata)
            || (int) $movement->propietario_id !== (int) $data['propietario_id']
            || ! BigDecimal::of($credit ? '-'.$movement->amount : $movement->amount)->isEqualTo($data['amount'])
            || ($credit ? $movement->effective_date : $movement->imputation_date)?->toDateString() !== $data['effective_date']
            || ($metadata['concept'] ?? null) !== ($data['concept'] ?? null)
            || ($metadata['information_source'] ?? null) !== $data['information_source']
            || ($metadata['observations'] ?? null) !== ($data['observations'] ?? null)
            || ($credit ? $movement->source : ($metadata['source'] ?? null)) !== $data['source']
            || ($credit ? $movement->reference : ($metadata['reference'] ?? null)) !== ($data['reference'] ?? null)) {
            throw new DomainException('La identidad externa corresponde a contenido distinto o vínculo inválido; requiere revisión manual.');
        }
    }
}
