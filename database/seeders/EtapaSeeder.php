<?php

namespace Database\Seeders;

use App\Models\Etapa;
use Illuminate\Database\Seeder;

class EtapaSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([0, 1, 2, 3] as $numero) {
            Etapa::query()->firstOrCreate(
                ['numero' => $numero],
                ['nombre' => 'Etapa '.$numero],
            );
        }
    }
}
