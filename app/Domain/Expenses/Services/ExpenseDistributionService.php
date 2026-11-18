<?php

namespace App\Domain\Expenses\Services;

use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Models\CommunitySetting;
use App\Models\Expense;
use App\Models\Propietario;
use App\Models\ShareHolding;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExpenseDistributionService
{
    /**
     * @return array<int, array{
     *     propietario_id: int,
     *     amount: float,
     *     calculation_type: string,
     *     partner_hectares_at_moment: float|null,
     *     total_hectares_at_moment: float|null,
     *     percentage_applied: float|null,
     *     calculation_notes: string|null
     * }>
     */
    public function calculateDistribution(Expense $expense): array
    {
        if ($expense->status !== ExpenseStatus::Registered) {
            throw new DomainException('Solo se puede distribuir un gasto en estado registrado.');
        }

        if ($expense->distribution_type === ExpenseDistributionType::Manual) {
            throw new DomainException('La distribución manual aún no está implementada.');
        }

        $settings = CommunitySetting::current();
        if (! array_key_exists($expense->distribution_type->value, ExpenseDistributionType::availableOptions())) {
            throw new DomainException('El criterio no está habilitado en la modalidad actual.');
        }
        if (in_array($expense->distribution_type, [ExpenseDistributionType::ProportionalByShares, ExpenseDistributionType::ProportionalByEquivalentArea], true)
            || ($expense->distribution_type === ExpenseDistributionType::EqualByPartner && $settings->usesShares())) {
            return $this->calculateShareDistribution($expense, $settings);
        }

        $owners = $this->activeOwnersWithHectares();

        if ($owners->isEmpty()) {
            throw new DomainException('No hay propietarios activos con lotes activos para distribuir el gasto.');
        }

        $distribution = match ($expense->distribution_type) {
            ExpenseDistributionType::EqualByPartner => $this->calculateEqualDistribution($expense, $owners),
            ExpenseDistributionType::ProportionalByHectares => $this->calculateProportionalDistribution($expense, $owners),
            default => throw new DomainException('Tipo de distribución no soportado.'),
        };

        foreach ($distribution as &$row) {
            $equal = $expense->distribution_type === ExpenseDistributionType::EqualByPartner;
            $row['distribution_snapshot'] = [
                'mode' => $settings->mode, 'type' => $expense->distribution_type->value,
                'unit' => $equal ? 'owner' : 'hectare',
                'base' => $equal ? '1' : (string) $row['partner_hectares_at_moment'],
                'total_base' => $equal ? (string) count($distribution) : (string) $row['total_hectares_at_moment'],
                'amount' => (string) $row['amount'], 'expense_id' => $expense->id,
                'rounding' => 'legacy_last_owner',
            ];
        }
        unset($row);

        return $distribution;
    }

    /**
     * @param  Collection<int, object{propietario_id:int,total_square_meters:float|int|string}>  $owners
     * @return array<int, array{propietario_id:int, amount:float, calculation_type:string, partner_hectares_at_moment:float|null, total_hectares_at_moment:float|null, percentage_applied:float|null, calculation_notes:string|null}>
     */
    private function calculateEqualDistribution(Expense $expense, Collection $owners): array
    {
        $totalAmount = round((float) $expense->amount, 2);
        $count = $owners->count();
        $amounts = $this->distributeRoundedAmounts($totalAmount, array_fill(0, $count, 1.0));
        $totalHectares = round($owners->sum(fn (object $owner): float => (float) $owner->total_square_meters / 10000), 4);
        $percentage = round(100 / $count, 2);

        $distribution = [];

        foreach ($owners->values() as $index => $owner) {
            $partnerHectares = round((float) $owner->total_square_meters / 10000, 4);

            $distribution[] = [
                'propietario_id' => (int) $owner->propietario_id,
                'amount' => $amounts[$index],
                'calculation_type' => ExpenseDistributionType::EqualByPartner->value,
                'partner_hectares_at_moment' => $partnerHectares,
                'total_hectares_at_moment' => $totalHectares,
                'percentage_applied' => $percentage,
                'calculation_notes' => 'Distribución igualitaria por propietario activo.',
            ];
        }

        $this->assertDistributedTotal($distribution, $totalAmount);

        return $distribution;
    }

    /**
     * @param  Collection<int, object{propietario_id:int,total_square_meters:float|int|string}>  $owners
     * @return array<int, array{propietario_id:int, amount:float, calculation_type:string, partner_hectares_at_moment:float|null, total_hectares_at_moment:float|null, percentage_applied:float|null, calculation_notes:string|null}>
     */
    private function calculateProportionalDistribution(Expense $expense, Collection $owners): array
    {
        $totalAmount = round((float) $expense->amount, 2);
        $totalHectares = round($owners->sum(fn (object $owner): float => (float) $owner->total_square_meters / 10000), 4);

        if ($totalHectares <= 0.0) {
            throw new DomainException('No se puede distribuir proporcionalmente sin hectáreas activas totales.');
        }

        $weights = $owners
            ->map(fn (object $owner): float => round((float) $owner->total_square_meters / 10000, 4))
            ->all();

        $amounts = $this->distributeRoundedAmounts($totalAmount, $weights);
        $distribution = [];

        foreach ($owners->values() as $index => $owner) {
            $partnerHectares = round((float) $owner->total_square_meters / 10000, 4);
            $percentage = $totalHectares > 0
                ? round(($partnerHectares / $totalHectares) * 100, 2)
                : 0.0;

            $distribution[] = [
                'propietario_id' => (int) $owner->propietario_id,
                'amount' => $amounts[$index],
                'calculation_type' => ExpenseDistributionType::ProportionalByHectares->value,
                'partner_hectares_at_moment' => $partnerHectares,
                'total_hectares_at_moment' => $totalHectares,
                'percentage_applied' => $percentage,
                'calculation_notes' => 'Distribución proporcional por hectáreas activas.',
            ];
        }

        $this->assertDistributedTotal($distribution, $totalAmount);

        return $distribution;
    }

    /**
     * @param  array<int, float>  $weights
     * @return array<int, float>
     */
    private function distributeRoundedAmounts(float $totalAmount, array $weights): array
    {
        if ($weights === []) {
            throw new DomainException('No hay ponderadores para distribuir el gasto.');
        }

        $totalWeight = array_sum($weights);

        if ($totalWeight <= 0) {
            throw new DomainException('No es posible distribuir con ponderadores totales en cero.');
        }

        $amounts = [];
        $allocated = 0.0;
        $lastIndex = count($weights) - 1;

        foreach ($weights as $index => $weight) {
            if ($index === $lastIndex) {
                $amount = round($totalAmount - $allocated, 2);
            } else {
                $amount = round($totalAmount * ($weight / $totalWeight), 2);
                $allocated += $amount;
            }

            $amounts[] = $amount;
        }

        return $amounts;
    }

    /**
     * @param  array<int, array{amount: float}>  $distribution
     */
    private function assertDistributedTotal(array $distribution, float $expectedTotal): void
    {
        $actualTotal = array_reduce(
            $distribution,
            fn (float $carry, array $row): float => $carry + $row['amount'],
            0.0,
        );

        if (abs($expectedTotal - $actualTotal) > 0.01) {
            throw new DomainException('La suma distribuida no coincide con el monto del gasto.');
        }
    }

    /**
     * @return Collection<int, object{propietario_id:int,total_square_meters:float|int|string}>
     */
    private function activeOwnersWithHectares(): Collection
    {
        return DB::table('lote_propietario')
            ->join('lotes', 'lotes.id', '=', 'lote_propietario.lote_id')
            ->selectRaw('lote_propietario.propietario_id, COALESCE(SUM(lotes.metros_cuadrados), 0) as total_square_meters')
            ->where('lote_propietario.status', 'active')
            ->groupBy('lote_propietario.propietario_id')
            ->orderBy('lote_propietario.propietario_id')
            ->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function calculateShareDistribution(Expense $expense, CommunitySetting $settings): array
    {
        $equal = $expense->distribution_type === ExpenseDistributionType::EqualByPartner;
        $byArea = $expense->distribution_type === ExpenseDistributionType::ProportionalByEquivalentArea;
        $owners = [];
        foreach (ShareHolding::query()->where('status', 'active')->where('quantity', '>', 0)->orderBy('id')->get() as $holding) {
            $id = $holding->propietario_id;
            $owners[$id] ??= ['quantity' => BigDecimal::zero(), 'area' => BigDecimal::zero(), 'holdings' => []];
            $owners[$id]['quantity'] = $owners[$id]['quantity']->plus($holding->quantity);
            $owners[$id]['area'] = $owners[$id]['area']->plus($holding->equivalentSquareMeters());
            $owners[$id]['holdings'][] = $holding->only(['id', 'quantity', 'square_meters_per_share']);
        }
        if ($equal) {
            $ids = $settings->mode === 'shares'
                ? Propietario::query()->orderBy('id')->pluck('id')
                : $this->activeOwnersWithHectares()->pluck('propietario_id')->merge(array_keys($owners))->unique();
            foreach ($ids as $id) {
                $owners[$id] ??= ['quantity' => BigDecimal::zero(), 'area' => BigDecimal::zero(), 'holdings' => []];
            }
        }
        ksort($owners);
        if ($owners === []) {
            throw new DomainException('No hay propietarios elegibles para distribuir el gasto.');
        }
        $totalWeight = BigDecimal::zero();
        foreach ($owners as &$owner) {
            $owner['weight'] = $equal ? BigDecimal::one() : ($byArea ? $owner['area'] : $owner['quantity']);
            $totalWeight = $totalWeight->plus($owner['weight']);
        }
        unset($owner);
        if ($totalWeight->isZero()) {
            throw new DomainException('No hay unidades elegibles para distribuir el gasto.');
        }
        $cents = BigDecimal::of($expense->amount)->multipliedBy(100)->toScale(0, RoundingMode::UNNECESSARY);
        $allocated = BigDecimal::zero();
        foreach ($owners as $id => &$owner) {
            $exact = $cents->multipliedBy($owner['weight']);
            $owner['cents'] = $exact->dividedBy($totalWeight, 0, RoundingMode::DOWN);
            $owner['remainder'] = $exact->minus($owner['cents']->multipliedBy($totalWeight));
            $allocated = $allocated->plus($owner['cents']);
        }
        unset($owner);
        $order = array_keys($owners);
        usort($order, fn (int $a, int $b): int => $owners[$b]['remainder']->compareTo($owners[$a]['remainder']) ?: ($a <=> $b));
        $remaining = $cents->minus($allocated)->toInt();
        for ($i = 0; $i < $remaining; $i++) {
            $owners[$order[$i]]['cents'] = $owners[$order[$i]]['cents']->plus(1);
        }
        $rows = [];
        foreach ($owners as $id => $owner) {
            if ($owner['cents']->isZero()) {
                continue;
            }
            $amount = (string) $owner['cents']->dividedBy(100, 2);
            $snapshot = [
                'mode' => $settings->mode, 'type' => $expense->distribution_type->value,
                'unit' => $equal ? 'owner' : ($byArea ? 'm2' : 'share'),
                'base' => (string) $owner['weight'], 'total_base' => (string) $totalWeight,
                'quantity' => (string) $owner['quantity'], 'equivalent_square_meters' => (string) $owner['area'],
                'holdings' => $owner['holdings'], 'amount' => $amount, 'expense_id' => $expense->id,
                'rounding' => 'largest_remainder_owner_id',
            ];
            $rows[] = [
                'propietario_id' => (int) $id, 'amount' => $amount,
                'calculation_type' => $expense->distribution_type->value,
                'partner_hectares_at_moment' => null, 'total_hectares_at_moment' => null,
                'percentage_applied' => (string) $owner['weight']->multipliedBy(100)->dividedBy($totalWeight, 2, RoundingMode::HALF_UP),
                'calculation_notes' => 'Base congelada en distribution_snapshot. Redondeo por mayor residuo.',
                'distribution_snapshot' => $snapshot,
            ];
        }

        return $rows;
    }
}
