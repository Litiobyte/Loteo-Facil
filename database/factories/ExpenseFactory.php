<?php

namespace Database\Factories;

use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $expenseDate = fake()->dateTimeBetween('-10 months', 'now');
        $dueDate = fake()->boolean(70)
            ? fake()->dateTimeBetween($expenseDate, '+90 days')
            : null;

        return [
            'expense_category_id' => ExpenseCategory::factory(),
            'supplier_id' => fake()->boolean(70) ? Supplier::factory() : null,
            'title' => fake()->randomElement([
                'Recaudacion de asesoría contable mensual',
                'Inscripción de documentos en conservador',
                'Recaudacion de contribuciones trimestrales',
                'Revisión legal de escrituras',
                'Gastos administrativos del proyecto',
                'Mantención de caminos interiores',
                'Estudio de factibilidad sanitaria',
            ]),
            'description' => fake()->optional()->sentence(),
            'amount' => fake()->numberBetween(180_000, 7_500_000),
            'expense_date' => $expenseDate,
            'due_date' => $dueDate,
            'distribution_type' => fake()->randomElement(ExpenseDistributionType::cases())->value,
            'status' => ExpenseStatus::Registered,
            'funded_amount' => 0,
            'distributed_at' => null,
            'paid_at' => null,
            'created_by' => fake()->boolean(80) ? User::factory() : null,
            'notes' => fake()->optional()->paragraph(),
        ];
    }

    public function registered(): static
    {
        return $this->state(fn (): array => [
            'status' => ExpenseStatus::Registered,
        ]);
    }

    public function distributed(): static
    {
        return $this->state(fn (): array => [
            'status' => ExpenseStatus::Distributed,
            'distributed_at' => now(),
            'paid_at' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => ExpenseStatus::Cancelled,
            'paid_at' => null,
        ]);
    }

    public function paid(): static
    {
        return $this->state(function (array $attributes): array {
            $amount = round((float) ($attributes['amount'] ?? 0), 2);

            return [
                'status' => ExpenseStatus::Paid,
                'funded_amount' => $amount,
                'paid_at' => now(),
            ];
        });
    }
}
