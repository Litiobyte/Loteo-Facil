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
        $hectareas = fake()->randomElement([1, 3, 5, 6]);
        $metrosCuadrados = $hectareas * 10000;

        return [
            'codigo' => strtoupper(fake()->unique()->bothify('LT-####')),
            'estado' => fake()->randomElement(['disponible', 'vendido', 'reservado']),
            'hectareas' => $hectareas,
            'metros_cuadrados' => $metrosCuadrados,
            'etapa_id' => Etapa::query()->inRandomOrder()->value('id'),
            'valor_lote' => fake()->numberBetween(5_000_000, 120_000_000),
            'notas' => fake()->optional()->sentence(),
        ];
    }
}
