<?php

namespace Database\Factories;

use App\Models\Etapa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Etapa>
 */
class EtapaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'numero' => $this->faker->unique()->numberBetween(1, 100),
            'nombre' => $this->faker->words(2, true),
        ];
    }
}
