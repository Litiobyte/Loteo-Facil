<?php

namespace Database\Factories;

use App\Models\MapGeometry;
use App\Models\MapImport;
use App\Models\MapLayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MapGeometry>
 */
class MapGeometryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'map_import_id' => MapImport::factory(),
            'map_layer_id' => MapLayer::factory(),
            'lote_id' => null,
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[[-70.6, -33.4], [-70.59, -33.4], [-70.59, -33.39], [-70.6, -33.39], [-70.6, -33.4]]],
            ],
            'properties' => [
                'name' => fake()->unique()->words(2, true),
            ],
            'normalized_identifier' => null,
            'association_status' => 'none',
            'association_method' => 'auto',
            'match_confidence' => null,
            'area_m2' => 1000,
        ];
    }
}
