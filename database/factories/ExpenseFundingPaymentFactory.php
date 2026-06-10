<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpenseFundingPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseFundingPayment>
 */
class ExpenseFundingPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'expense_id' => Expense::factory(),
            'amount' => fake()->numberBetween(10_000, 800_000),
            'payment_date' => fake()->dateTimeBetween('-6 months', 'today'),
            'notes' => fake()->optional()->sentence(),
            'is_void' => false,
            'voided_at' => null,
            'voided_by' => null,
            'void_reason' => null,
            'created_by' => fake()->boolean(80) ? User::factory() : null,
            'updated_by' => null,
        ];
    }

    public function voided(?User $user = null): static
    {
        return $this->state(fn (): array => [
            'is_void' => true,
            'voided_at' => now(),
            'voided_by' => $user?->id ?? User::factory(),
            'void_reason' => 'Anulado por corrección',
        ]);
    }
}
