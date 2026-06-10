<?php

namespace Database\Factories;

use App\Models\AccountingPeriod;
use App\Models\AccountingPeriodSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingPeriodSnapshot>
 */
class AccountingPeriodSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'accounting_period_id' => AccountingPeriod::factory(),
            'total_charges' => fake()->randomFloat(2, 0, 5000000),
            'total_payments' => fake()->randomFloat(2, 0, 5000000),
            'total_allocations' => fake()->randomFloat(2, 0, 5000000),
            'pending_balance' => fake()->randomFloat(2, 0, 5000000),
            'credit_balance' => fake()->randomFloat(2, 0, 5000000),
            'overdue_1_30' => fake()->randomFloat(2, 0, 2000000),
            'overdue_31_60' => fake()->randomFloat(2, 0, 2000000),
            'overdue_61_90' => fake()->randomFloat(2, 0, 2000000),
            'overdue_90_plus' => fake()->randomFloat(2, 0, 2000000),
            'metadata' => [
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }
}
