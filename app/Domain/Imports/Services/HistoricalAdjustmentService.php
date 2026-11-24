<?php

namespace App\Domain\Imports\Services;

use App\Domain\Accounting\Services\HistoricalPeriodGuard;
use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Imports\Enums\HistoricalAdjustmentCategory;
use App\Models\CollectionAllocation;
use App\Models\Expense;
use App\Models\HistoricalAdjustment;
use App\Models\HistoricalAdjustmentAllocation;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HistoricalAdjustmentService
{
    public function __construct(private readonly HistoricalPeriodGuard $guard, private readonly MonthlyClosingService $closingService) {}

    /**
     * @param  array{source: string, reference?: ?string, observations?: ?string, from_import?: bool}  $metadata
     */
    public function create(Propietario $owner, User $authorizer, HistoricalAdjustmentCategory $category, string $amount, string $effective, string $precision, array $metadata, ?int $chargeId = null): HistoricalAdjustment
    {
        $this->authorize($authorizer);
        if ($category === HistoricalAdjustmentCategory::CreditOpening) {
            throw new DomainException('La apertura acreedora debe registrarse mediante el servicio de aperturas.');
        }
        $cents = $this->cents($amount);
        $dates = $this->dates($effective, $precision);
        $reduces = in_array($category, [HistoricalAdjustmentCategory::DebtCorrection, HistoricalAdjustmentCategory::DebtForgiveness], true);
        if ($reduces && $precision !== 'day') {
            throw new DomainException('Una aplicación a un cobro requiere fecha exacta.');
        }
        if ($reduces !== ($chargeId !== null)) {
            throw new DomainException('Las correcciones y condonaciones requieren un cobro; las diferencias no se aplican.');
        }
        if (trim($metadata['source'] ?? '') === '') {
            throw new DomainException('Debe indicar la fuente histórica.');
        }
        $charge = $chargeId ? PartnerCharge::query()->with('expense')->findOrFail($chargeId) : null;
        $guardDates = $dates['guard_dates'];
        if ($charge) {
            $guardDates[] = $this->closingService->chargeImputationDate($charge)->toDateString();
        }
        $guardDates = array_values(array_unique($guardDates));
        sort($guardDates);
        foreach ($guardDates as $date) {
            $this->guard->validate($date);
        }

        return DB::transaction(function () use ($owner, $authorizer, $category, $cents, $dates, $metadata, $chargeId, $guardDates, $charge): HistoricalAdjustment {
            foreach ($guardDates as $date) {
                $this->guard->lockAndValidate($date);
            }
            Propietario::query()->lockForUpdate()->findOrFail($owner->id);
            $this->assertAfterOpening($owner->id, $dates['guard_dates'][0]);
            $expenseId = $charge?->expense_id;
            $expense = $expenseId ? Expense::query()->lockForUpdate()->findOrFail($expenseId) : null;
            $charge = $chargeId ? PartnerCharge::query()->lockForUpdate()->findOrFail($chargeId) : null;
            if ($charge && $charge->expense_id !== $expenseId) {
                throw new DomainException('El gasto del cobro cambió; vuelva a intentar.');
            }
            $charge?->setRelation('expense', $expense);
            if ($charge && ! in_array($this->closingService->chargeImputationDate($charge)->toDateString(), $guardDates, true)) {
                throw new DomainException('La fecha del cobro cambió; vuelva a intentar.');
            }
            if ($charge) {
                $this->validateChargeAccounting($charge);
            }
            if ($charge && ($charge->propietario_id !== $owner->id || ! in_array($charge->status, [ChargeStatus::Pending, ChargeStatus::Partial], true) || $cents > $this->cents((string) $charge->remaining_amount))) {
                throw new DomainException('El cobro no pertenece al propietario, no está pendiente o el ajuste excede su saldo.');
            }
            if ($charge && $this->closingService->chargeImputationDate($charge)->toDateString() > end($dates['guard_dates'])) {
                throw new DomainException('El ajuste no puede preceder al cobro.');
            }
            $adjustment = HistoricalAdjustment::query()->create([
                'propietario_id' => $owner->id,
                'category' => $category,
                'amount' => $cents / 100,
                'direction' => $charge ? 'credit' : 'none',
                'effective_date' => $dates['effective_date'],
                'effective_year' => $dates['year'],
                'effective_month' => $dates['month'],
                'date_precision' => $dates['precision'],
                'registered_at' => now(),
                'source' => trim($metadata['source']),
                'reference' => $metadata['reference'] ?? null,
                'observations' => $metadata['observations'] ?? null,
                'authorized_by' => $authorizer->id,
                'from_import' => $metadata['from_import'] ?? false,
                'evidence_status' => $charge ? 'recognized' : 'pending_verification',
            ]);
            if ($charge) {
                $this->applyLocked($adjustment, $charge, $cents, end($dates['guard_dates']), $authorizer);
            }

            return $adjustment;
        }, 3);
    }

    public function applyCredit(HistoricalAdjustment $adjustment, PartnerCharge $charge, string $amount, string $effectiveDate, User $authorizer): HistoricalAdjustmentAllocation
    {
        $this->authorize($authorizer);
        $cents = $this->cents($amount);
        $this->dates($effectiveDate, 'day');
        $charge = PartnerCharge::query()->with('expense')->findOrFail($charge->id);
        $adjustment = HistoricalAdjustment::query()->findOrFail($adjustment->id);
        $datesToGuard = [$effectiveDate, $this->closingService->chargeImputationDate($charge)->toDateString(), $adjustment->effective_date?->toDateString()];
        if (in_array(null, $datesToGuard, true)) {
            throw new DomainException('El crédito requiere una fecha exacta para poder aplicarse.');
        }
        $datesToGuard = array_values(array_unique($datesToGuard));
        sort($datesToGuard);
        foreach ($datesToGuard as $date) {
            $this->guard->validate($date);
        }

        return DB::transaction(function () use ($adjustment, $charge, $cents, $effectiveDate, $authorizer, $datesToGuard): HistoricalAdjustmentAllocation {
            foreach ($datesToGuard as $date) {
                $this->guard->lockAndValidate($date);
            }
            Propietario::query()->lockForUpdate()->findOrFail($adjustment->propietario_id);
            $adjustment = HistoricalAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            $expenseId = $charge->expense_id;
            $expense = $expenseId ? Expense::query()->lockForUpdate()->findOrFail($expenseId) : null;
            $charge = PartnerCharge::query()->lockForUpdate()->findOrFail($charge->id);
            if ($charge->expense_id !== $expenseId) {
                throw new DomainException('El gasto del cobro cambió; vuelva a intentar.');
            }
            $charge->setRelation('expense', $expense);
            $this->assertAfterOpening($adjustment->propietario_id, $effectiveDate);
            if (! in_array($this->closingService->chargeImputationDate($charge)->toDateString(), $datesToGuard, true)
                || ! $adjustment->effective_date
                || ! in_array($adjustment->effective_date->toDateString(), $datesToGuard, true)) {
                throw new DomainException('Las fechas de los movimientos cambiaron; vuelva a intentar.');
            }
            $this->validateChargeAccounting($charge);
            $available = $this->cents((string) $adjustment->amount) - (int) round((float) $adjustment->allocations()->sum('amount') * 100);
            if ($adjustment->category !== HistoricalAdjustmentCategory::CreditOpening || $adjustment->propietario_id !== $charge->propietario_id || ! in_array($charge->status, [ChargeStatus::Pending, ChargeStatus::Partial], true) || $cents > $available || $cents > $this->cents((string) $charge->remaining_amount) || $effectiveDate < $adjustment->effective_date->toDateString() || $effectiveDate < $this->closingService->chargeImputationDate($charge)->toDateString()) {
                throw new DomainException('La aplicación no es válida o excede el crédito o saldo disponible.');
            }

            return $this->applyLocked($adjustment, $charge, $cents, $effectiveDate, $authorizer);
        }, 3);
    }

    public function authorize(User $user): void
    {
        if (! $user->exists || ! $user->hasRole('super_admin')) {
            throw new DomainException('Solo un superadministrador puede registrar movimientos históricos.');
        }
    }

    /**
     * Valida que una fecha sea posterior al corte inclusivo de apertura del propietario.
     */
    public function assertDateAfterOpening(int $ownerId, string $date): void
    {
        $this->assertAfterOpening($ownerId, $date);
    }

    public function cents(string $amount): int
    {
        if (! preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,2})?$/D', $amount)) {
            throw new DomainException('El monto debe ser decimal positivo con hasta dos decimales.');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        if ($cents <= 0) {
            throw new DomainException('El monto debe ser mayor que cero.');
        }

        return $cents;
    }

    /** @return array{effective_date: ?string, year: int, month: ?int, precision: string, guard_dates: list<string>} */
    public function dates(string $value, string $precision): array
    {
        $format = match ($precision) {
            'day' => '!Y-m-d',
            'month' => '!Y-m',
            'year' => '!Y',
            default => throw new DomainException('La fecha desconocida no permite proteger los períodos contables.'),
        };
        try {
            $start = Carbon::createFromFormat($format, $value);
        } catch (\Throwable) {
            throw new DomainException('Fecha histórica inválida.');
        }
        $expected = match ($precision) {
            'day' => 'Y-m-d', 'month' => 'Y-m', default => 'Y'
        };
        if (! $start || $start->format($expected) !== $value) {
            throw new DomainException('Fecha histórica inválida.');
        }
        $months = $precision === 'year' ? 12 : 1;
        $guardDates = [];
        for ($i = 0; $i < $months; $i++) {
            $guardDates[] = $precision === 'day' ? $value : $start->copy()->addMonths($i)->toDateString();
        }

        return ['effective_date' => $precision === 'day' ? $value : null, 'year' => $start->year, 'month' => $precision === 'year' ? null : $start->month, 'precision' => $precision, 'guard_dates' => $guardDates];
    }

    private function applyLocked(HistoricalAdjustment $adjustment, PartnerCharge $charge, int $cents, string $date, User $authorizer): HistoricalAdjustmentAllocation
    {
        $allocation = HistoricalAdjustmentAllocation::query()->create([
            'historical_adjustment_id' => $adjustment->id,
            'partner_charge_id' => $charge->id,
            'amount' => $cents / 100,
            'effective_date' => $date,
            'authorized_by' => $authorizer->id,
        ]);
        $charge->paid_amount = round((float) $charge->paid_amount + $cents / 100, 2);
        $charge->status = (float) $charge->paid_amount >= (float) $charge->amount ? ChargeStatus::Paid : ChargeStatus::Partial;
        $charge->save();

        return $allocation;
    }

    private function validateChargeAccounting(PartnerCharge $charge): void
    {
        $payments = (int) round((float) CollectionAllocation::query()->where('partner_charge_id', $charge->id)->sum('amount') * 100);
        $adjustments = (int) round((float) HistoricalAdjustmentAllocation::query()->where('partner_charge_id', $charge->id)->sum('amount') * 100);
        if ($payments + $adjustments !== (int) round((float) $charge->paid_amount * 100)) {
            throw new DomainException('El cobro presenta una inconsistencia entre su saldo y sus aplicaciones.');
        }
    }

    private function assertAfterOpening(int $ownerId, string $date): void
    {
        $chargeOpening = PartnerCharge::query()->where('propietario_id', $ownerId)
            ->where('charge_type', 'opening_balance')->value('imputation_date');
        $creditOpening = HistoricalAdjustment::query()->where('propietario_id', $ownerId)
            ->where('category', HistoricalAdjustmentCategory::CreditOpening->value)->value('effective_date');
        foreach ([$chargeOpening, $creditOpening] as $cutoff) {
            if ($cutoff && $date <= Carbon::parse($cutoff)->toDateString()) {
                throw new DomainException('El movimiento debe ser posterior al corte inclusivo de apertura.');
            }
        }
    }
}
