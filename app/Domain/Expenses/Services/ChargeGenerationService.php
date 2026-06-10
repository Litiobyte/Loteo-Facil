<?php

namespace App\Domain\Expenses\Services;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\PartnerCharge;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChargeGenerationService
{
    public function __construct(
        private readonly ExpenseDistributionService $distributionService
    ) {}

    /**
     * Genera cobros para un gasto y actualiza su estado a Distributed.
     *
     * @return Collection<int, PartnerCharge>
     *
     * @throws DomainException
     */
    public function generateChargesFromExpense(Expense $expense): Collection
    {
        if ($expense->status !== ExpenseStatus::Registered) {
            throw new DomainException('Solo se puede generar cobros para un gasto en estado registrado.');
        }

        // Verificar si ya existen cobros para este gasto (idempotencia)
        $existingCharges = PartnerCharge::byExpense($expense->id)->count();
        if ($existingCharges > 0) {
            throw new DomainException('Este gasto ya tiene cobros generados.');
        }

        return DB::transaction(function () use ($expense): Collection {
            // Calcular distribución
            $distribution = $this->distributionService->calculateDistribution($expense);

            if ($distribution === []) {
                throw new DomainException('No se pudo calcular la distribución del gasto.');
            }

            $charges = new Collection;

            // Crear cobros
            foreach ($distribution as $row) {
                $charge = PartnerCharge::create([
                    'propietario_id' => $row['propietario_id'],
                    'expense_id' => $expense->id,
                    'amount' => $row['amount'],
                    'paid_amount' => 0,
                    'status' => ChargeStatus::Pending,
                    'due_date' => $expense->due_date,
                    'description' => $this->buildChargeDescription($expense),
                    'calculation_type' => $row['calculation_type'],
                    'partner_hectares_at_moment' => $row['partner_hectares_at_moment'],
                    'total_hectares_at_moment' => $row['total_hectares_at_moment'],
                    'percentage_applied' => $row['percentage_applied'],
                    'calculation_notes' => $row['calculation_notes'],
                ]);

                $charges->push($charge);
            }

            // Marcar el gasto como distribuido
            $expense->update([
                'status' => ExpenseStatus::Distributed,
                'distributed_at' => now(),
            ]);

            return $charges;
        });
    }

    private function buildChargeDescription(Expense $expense): string
    {
        $categoryName = $expense->category?->name ?? 'Sin categoría';
        $date = $expense->expense_date?->format('Y-m') ?? now()->format('Y-m');

        return "Cobro de {$categoryName} - {$date}";
    }
}
