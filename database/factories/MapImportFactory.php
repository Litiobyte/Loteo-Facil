<?php

namespace Database\Factories;

use App\Models\Etapa;
use App\Models\MapImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MapImport>
 */
class MapImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'etapa_id' => Etapa::factory(),
            'filename' => 'loteo.kmz',
            'original_path' => fn (): string => 'map-imports/'.uniqid('import-', true).'.kmz',
            'status' => 'reviewing',
            'summary' => [],
            'uploaded_by' => User::factory(),
        ];
    }
}
