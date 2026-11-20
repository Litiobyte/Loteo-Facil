<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'rut' => '12.345.678-5',
            'service' => fake()->jobTitle(),
            'phone' => '+56912345678',
            'email' => fake()->safeEmail(),
        ];
    }
}
