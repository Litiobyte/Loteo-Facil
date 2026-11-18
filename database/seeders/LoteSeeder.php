<?php

namespace Database\Seeders;

use App\Models\Lote;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Seeder;

class LoteSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Generar 60 lotes con codigo "LOTE 0".."LOTE 59" y datos realistas
        Lote::factory()
            ->count(60)
            ->sequence(fn (Sequence $sequence): array => ['codigo' => 'LOTE '.$sequence->index])
            ->create();
    }
}
