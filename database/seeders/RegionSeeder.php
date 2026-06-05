<?php

namespace Database\Seeders;

use App\Models\Comuna;
use App\Models\Region;
use Illuminate\Database\Seeder;

class RegionSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'Metropolitana de Santiago' => ['Santiago', 'Providencia', 'Las Condes', 'Maipu', 'Puente Alto'],
            'Valparaiso' => ['Valparaiso', 'Vina del Mar', 'Quilpue', 'Villa Alemana'],
            'Biobio' => ['Concepcion', 'Talcahuano', 'San Pedro de la Paz', 'Chiguayante'],
        ];

        foreach ($data as $regionNombre => $comunas) {
            $region = Region::query()->firstOrCreate(['nombre' => $regionNombre]);

            foreach ($comunas as $comunaNombre) {
                Comuna::query()->firstOrCreate([
                    'region_id' => $region->id,
                    'nombre' => $comunaNombre,
                ]);
            }
        }
    }
}
