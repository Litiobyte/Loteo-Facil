<?php

namespace Tests\Feature;

use App\Domain\Maps\Services\MapPersistenceService;
use App\Models\Lote;
use App\Models\Propietario;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitialDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_generate_lote_prefix_60_lotes_and_45_propietarios(): void
    {
        $this->seed(DatabaseSeeder::class);

        $lotes = Lote::query()->orderBy('id')->get();

        $this->assertCount(60, $lotes);
        $this->assertSame('LOTE 0', $lotes->first()->codigo);
        $this->assertSame('LOTE 59', $lotes->last()->codigo);
        $propietariosConRol = Propietario::query()
            ->whereHas('user.roles', fn ($query) => $query->where('name', 'propietario'))
            ->count();

        // 45 del seeder + 1 propietario demo de DatabaseSeeder (propietario@loteofacil.cl)
        $this->assertSame(46, $propietariosConRol);

        app(MapPersistenceService::class)->ensureDefaultLayers(1);

        $this->assertDatabaseHas('map_layers', ['etapa_id' => 1, 'slug' => 'rio', 'is_public' => true]);
    }
}
