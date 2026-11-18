<?php

namespace Tests\Feature;

use App\Domain\Maps\Services\GeoFileImporter;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class GeoFileImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_kmz_placemark_without_extended_data(): void
    {
        Storage::fake('local');

        $etapa = Etapa::query()->create(['numero' => 1, 'nombre' => 'Prueba']);
        $lote = Lote::factory()->create(['codigo' => '6-2', 'etapa_id' => $etapa->id]);
        $user = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'map-kmz-');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
            $zip->addFromString('doc.kml', <<<'KML'
                <?xml version="1.0" encoding="UTF-8"?>
                <kml xmlns="http://www.opengis.net/kml/2.2">
                    <Document><Folder><name>Lotes</name>
                        <Placemark>
                            <name>Lote 6-2</name>
                            <Polygon><outerBoundaryIs><LinearRing><coordinates>
                                -70,-33 -70.001,-33 -70.001,-33.001 -70,-33.001 -70,-33
                            </coordinates></LinearRing></outerBoundaryIs></Polygon>
                        </Placemark>
                    </Folder></Document>
                </kml>
                KML);
            $zip->close();

            $import = app(GeoFileImporter::class)->importFromFile($path, 'lotes.kmz', $etapa->id, $user->id);
            $geometry = $import->geometries()->firstOrFail();

            $this->assertSame('Polygon', $geometry->geometry['type']);
            $this->assertSame('Lote 6-2', $geometry->properties['name']);
            $this->assertSame($lote->id, $geometry->lote_id);
            $this->assertSame('exact', $geometry->association_status);
            $this->assertTrue(Storage::disk('local')->exists($import->original_path));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
