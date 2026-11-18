<?php

namespace Database\Factories;

use App\Models\Etapa;
use App\Models\MapLayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MapLayer>
 */
class MapLayerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'etapa_id' => Etapa::factory(),
            'slug' => fake()->unique()->slug(),
            'name' => fake()->unique()->words(2, true),
            'color' => '#e11d48',
            'opacity' => 60,
            'sort_order' => 0,
            'is_visible' => true,
            'is_public' => true,
        ];
    }
}
