<?php

namespace Database\Factories;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Models\Collection;
use App\Models\CollectionAllocation;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollectionAllocation>
 */
class CollectionAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'collection_id' => function (): int {
                $propietario = Propietario::factory()->create();

                return Collection::factory()
                    ->for($propietario, 'propietario')
                    ->create([
                        'amount' => 120000,
                        'applied_amount' => 0,
                        'unapplied_amount' => 120000,
                    ])->id;
            },
            'partner_charge_id' => function (array $attributes): int {
                $payment = Collection::query()->findOrFail($attributes['collection_id']);

                return PartnerCharge::factory()
                    ->for($payment->propietario, 'propietario')
                    ->create([
                        'amount' => 120000,
                        'paid_amount' => 0,
                        'remaining_amount' => 120000,
                        'status' => ChargeStatus::Pending->value,
                        'calculation_type' => ExpenseDistributionType::Manual->value,
                    ])->id;
            },
            'amount' => 60000,
            'allocated_at' => now(),
            'created_by' => fake()->boolean(70) ? User::factory() : null,
        ];
    }
}
