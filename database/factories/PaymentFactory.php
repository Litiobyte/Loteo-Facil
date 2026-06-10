<?php

namespace Database\Factories;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = (float) fake()->numberBetween(50_000, 2_000_000);

        return [
            'propietario_id' => Propietario::factory(),
            'amount' => $amount,
            'applied_amount' => 0,
            'unapplied_amount' => $amount,
            'payment_date' => fake()->dateTimeBetween('-6 months', 'today'),
            'payment_method' => fake()->randomElement(PaymentMethod::cases())->value,
            'reference' => fake()->optional()->bothify('REF-######'),
            'notes' => fake()->optional()->sentence(),
            'status' => PaymentStatus::PendingApplication->value,
            'created_by' => fake()->boolean(70) ? User::factory() : null,
        ];
    }

    public function pendingApplication(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::PendingApplication->value,
            'applied_amount' => 0,
            'unapplied_amount' => $attributes['amount'],
        ]);
    }

    public function partiallyApplied(): static
    {
        return $this->state(function (array $attributes): array {
            $amount = (float) $attributes['amount'];
            $applied = round($amount * 0.4, 2);

            return [
                'status' => PaymentStatus::PartiallyApplied->value,
                'applied_amount' => $applied,
                'unapplied_amount' => round($amount - $applied, 2),
            ];
        });
    }

    public function fullyApplied(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::FullyApplied->value,
            'applied_amount' => $attributes['amount'],
            'unapplied_amount' => 0,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Cancelled->value,
        ]);
    }
}
