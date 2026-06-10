<?php

namespace Database\Factories;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerCharge>
 */
class PartnerChargeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = (float) fake()->numberBetween(25_000, 1_200_000);

        return [
            'propietario_id' => Propietario::factory(),
            'expense_id' => Expense::factory()->state([
                'expense_category_id' => ExpenseCategory::factory(),
                'status' => ExpenseStatus::Distributed->value,
                'distribution_type' => ExpenseDistributionType::Manual->value,
            ]),
            'amount' => $amount,
            'paid_amount' => 0,
            'remaining_amount' => $amount,
            'status' => ChargeStatus::Pending->value,
            'due_date' => fake()->optional()->dateTimeBetween('today', '+60 days'),
            'description' => fake()->optional()->sentence(),
            'calculation_type' => ExpenseDistributionType::Manual->value,
            'partner_hectares_at_moment' => fake()->randomFloat(4, 0.5, 30),
            'total_hectares_at_moment' => fake()->randomFloat(4, 10, 500),
            'percentage_applied' => fake()->randomFloat(2, 0.1, 100),
            'calculation_notes' => fake()->optional()->sentence(),
        ];
    }
}
