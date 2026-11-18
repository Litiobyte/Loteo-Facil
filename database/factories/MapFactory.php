<?php

namespace Database\Factories;

use App\Models\Etapa;
use App\Models\Map;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Map>
 */
class MapFactory extends Factory
{
    public function definition(): array
    {
        return [
            'etapa_id' => Etapa::factory(),
            'published_import_id' => null,
        ];
    }
}
