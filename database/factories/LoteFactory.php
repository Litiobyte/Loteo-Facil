<?php

namespace Database\Factories;

use App\Models\Etapa;
use App\Models\Lote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lote>
 */
class LoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $metrosCuadrados = fake()->numberBetween(1000, 500000);

        return [
            'codigo' => strtoupper(fake()->bothify('LT-####')),
            'estado' => fake()->randomElement(['disponible', 'vendido', 'reservado']),
            'hectareas' => $metrosCuadrados / 10000,
            'metros_cuadrados' => $metrosCuadrados,
            'etapa_id' => Etapa::query()->inRandomOrder()->value('id'),
            'valor_lote' => fake()->numberBetween(5_000_000, 120_000_000),
            'notas' => fake()->optional()->sentence(),
        ];
    }
}
