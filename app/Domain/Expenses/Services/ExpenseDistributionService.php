<?php

namespace App\Domain\Expenses\Services;

use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Models\Expense;
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

        $owners = $this->activeOwnersWithHectares();

        if ($owners->isEmpty()) {
            throw new DomainException('No hay propietarios activos con lotes activos para distribuir el gasto.');
        }

        return match ($expense->distribution_type) {
            ExpenseDistributionType::EqualByPartner => $this->calculateEqualDistribution($expense, $owners),
            ExpenseDistributionType::ProportionalByHectares => $this->calculateProportionalDistribution($expense, $owners),
            default => throw new DomainException('Tipo de distribución no soportado.'),
        };
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
}
