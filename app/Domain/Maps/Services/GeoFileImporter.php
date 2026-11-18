<?php

namespace App\Domain\Maps\Services;

use App\Domain\Maps\Enums\AssociationMatch;
use App\Models\MapImport;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Importa KMZ/KML/GeoJSON a geometrias GeoJSON (EPSG:4326), clasificando por capas
 * y proponiendo asociaciones con lotes. Conserva el archivo original en disco local.
 */
class GeoFileImporter
{
    private const MAX_KML_BYTES = 52428800; // 50MB descomprimido

    private const MAX_ZIP_ENTRIES = 2000;

    /** slug => palabras clave para clasificar carpetas/nombres */
    private const LAYER_KEYWORDS = [
        'caminos' => ['camino', 'acceso', 'via', 'ruta', 'sendero'],
        'servidumbre_transito' => ['servidumbre', 'transito', 'paso'],
        'servidumbre_servicios' => ['servidumbre', 'servicio', 'agua', 'luz', 'electrico', 'cloaca', 'sanitari'],
        'areas_comunes' => ['area comun', 'areas comunes'],
        'areas_verdes' => ['area verde', 'areas verdes', 'verde', 'parque', 'plaza'],
        'rio' => ['rio', 'estero', 'canal', 'quebrada'],
        'infraestructura' => ['infraestructura', 'red', 'estanque', 'caseta', 'bodega'],
    ];

    public function __construct(
        private LoteAssociationService $association,
        private MapPersistenceService $persistence,
        private MapIdentifierNormalizer $normalizer,
    ) {}

    public function importFromFile(string $absolutePath, string $originalName, int $etapaId, int $userId): MapImport
    {
        $this->assertValidFile($absolutePath, $originalName);

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $features = match ($extension) {
            'kmz' => $this->parseKmz($absolutePath),
            'kml' => $this->parseKml((string) file_get_contents($absolutePath)),
            'geojson', 'json' => $this->parseGeoJson((string) file_get_contents($absolutePath)),
            default => throw ValidationException::withMessages([
                'file' => 'Formato no soportado. Use KMZ, KML o GeoJSON.',
            ]),
        };

        if ($features === []) {
            throw ValidationException::withMessages([
                'file' => 'El archivo no contiene geometrías válidas.',
            ]);
        }

        $import = MapImport::query()->create([
            'etapa_id' => $etapaId,
            'filename' => $originalName,
            'original_path' => $this->storeOriginal($absolutePath, $originalName),
            'status' => 'reviewing',
            'uploaded_by' => $userId,
        ]);

        $this->persistence->ensureDefaultLayers($etapaId);

        $this->createGeometries($import, $features);

        return $import->refresh();
    }

    private function assertValidFile(string $absolutePath, string $originalName): void
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (! in_array($extension, config('map.allowed_extensions'), true)) {
            throw ValidationException::withMessages([
                'file' => 'Formato no soportado. Use KMZ, KML o GeoJSON.',
            ]);
        }

        $sizeKb = (int) ceil((float) filesize($absolutePath) / 1024);

        if ($sizeKb > (int) config('map.max_upload_kb')) {
            throw ValidationException::withMessages([
                'file' => 'El archivo excede el tamaño máximo permitido.',
            ]);
        }
    }

    private function storeOriginal(string $absolutePath, string $originalName): string
    {
        $name = now()->format('YmdHis').'-'.pathinfo($originalName, PATHINFO_FILENAME).'.'.strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return Storage::disk('local')->putFileAs('map-imports', new File($absolutePath), $name);
    }

    /**
     * @return array<int, array{type: string, coordinates: array, name: ?string, description: ?string, source_folder: ?string, area_m2: ?float}>
     */
    private function parseKmz(string $absolutePath): array
    {
        $zip = new \ZipArchive;
        $result = $zip->open($absolutePath);

        if ($result !== true) {
            throw ValidationException::withMessages([
                'file' => 'El archivo KMZ está dañado o es inválido.',
            ]);
        }

        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            throw ValidationException::withMessages(['file' => 'El archivo KMZ contiene demasiados elementos.']);
        }

        $kmlName = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            if (strtolower($stat['name']) === 'doc.kml' || (str_ends_with(strtolower($stat['name']), '.kml') && $kmlName === null)) {
                if ($stat['size'] > self::MAX_KML_BYTES) {
                    $zip->close();
                    throw ValidationException::withMessages(['file' => 'El KML dentro del KMZ es demasiado grande.']);
                }

                $kmlName = $stat['name'];

                if (strtolower($stat['name']) === 'doc.kml') {
                    break;
                }
            }
        }

        if ($kmlName === null) {
            $zip->close();
            throw ValidationException::withMessages(['file' => 'El KMZ no contiene un archivo KML.']);
        }

        $kml = (string) $zip->getFromName($kmlName);
        $zip->close();

        return $this->parseKml($kml);
    }

    /**
     * @return array<int, array{type: string, coordinates: array, name: ?string, description: ?string, source_folder: ?string, area_m2: ?float}>
     */
    private function parseKml(string $kml): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($kml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw ValidationException::withMessages(['file' => 'El KML está malformado.']);
        }

        $features = [];
        $this->walkKml($xml, [], $features);

        return $features;
    }

    /**
     * @param  array<int, string>  $folders
     * @param  array<int, array{type: string, coordinates: array, name: ?string, description: ?string, source_folder: ?string, area_m2: ?float}>  $features
     */
    private function walkKml(\SimpleXMLElement $node, array $folders, array &$features): void
    {
        foreach ($node->children() as $child) {
            $tag = $child->getName();

            if ($tag === 'Folder' || $tag === 'Document') {
                $name = trim((string) ($child->name[0] ?? ''));
                $this->walkKml($child, $name !== '' ? [...$folders, $name] : $folders, $features);

                continue;
            }

            if ($tag === 'Placemark') {
                $this->addKmlPlacemark($child, implode(' / ', $folders) ?: null, $features);
            }
        }
    }

    /**
     * @param  array<int, array{type: string, coordinates: array, name: ?string, description: ?string, source_folder: ?string, area_m2: ?float}>  $features
     */
    private function addKmlPlacemark(\SimpleXMLElement $placemark, ?string $folder, array &$features): void
    {
        $name = trim((string) ($placemark->name[0] ?? '')) ?: null;
        $description = trim((string) ($placemark->description[0] ?? '')) ?: null;
        $extended = [];

        $extendedData = $placemark->ExtendedData;

        if ($extendedData instanceof \SimpleXMLElement) {
            foreach ($extendedData->children() ?? [] as $data) {
                if ($data->getName() === 'Data') {
                    $key = (string) $data['name'];
                    $value = trim((string) ($data->value[0] ?? ''));

                    if ($key !== '' && $value !== '') {
                        $extended[$key] = $value;
                    }
                }
            }
        }

        foreach ($this->extractKmlGeometries($placemark) as $geometry) {
            $features[] = [
                'type' => $geometry['type'],
                'coordinates' => $geometry['coordinates'],
                'name' => $name,
                'description' => $description,
                'source_folder' => $folder,
                'extra' => $extended,
                'area_m2' => $geometry['type'] === 'Polygon' ? $this->polygonAreaM2($geometry['coordinates'][0]) : null,
            ];
        }
    }

    /**
     * @return array<int, array{type: string, coordinates: array}>
     */
    private function extractKmlGeometries(\SimpleXMLElement $node): array
    {
        $geometries = [];

        foreach ($node->children() as $child) {
            $tag = $child->getName();

            if ($tag === 'Point') {
                $geometries[] = ['type' => 'Point', 'coordinates' => $this->parseCoordinates((string) $child->coordinates[0])[0]];
            } elseif ($tag === 'LineString') {
                $geometries[] = ['type' => 'LineString', 'coordinates' => $this->parseCoordinates((string) $child->coordinates[0])];
            } elseif ($tag === 'Polygon') {
                $outer = $child->outerBoundaryIs->LinearRing->coordinates[0] ?? null;
                $ring = $outer !== null ? $this->parseCoordinates((string) $outer) : [];
                $geometries[] = ['type' => 'Polygon', 'coordinates' => [$ring]];
            } elseif ($tag === 'MultiGeometry') {
                $geometries = [...$geometries, ...$this->extractKmlGeometries($child)];
            }
        }

        return $geometries;
    }

    /**
     * @return array<int, array{float, float}>
     */
    private function parseCoordinates(string $raw): array
    {
        $points = [];

        foreach (preg_split('/\s+/', trim($raw)) ?: [] as $tuple) {
            if ($tuple === '') {
                continue;
            }

            $parts = explode(',', $tuple);

            if (count($parts) < 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
                continue;
            }

            $points[] = [(float) $parts[0], (float) $parts[1]];
        }

        return $points;
    }

    /**
     * @return array<int, array{type: string, coordinates: array, name: ?string, description: ?string, source_folder: ?string, area_m2: ?float}>
     */
    private function parseGeoJson(string $raw): array
    {
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            throw ValidationException::withMessages(['file' => 'El archivo GeoJSON está malformado.']);
        }

        $features = [];

        $featureList = match ($data['type'] ?? null) {
            'FeatureCollection' => $data['features'] ?? [],
            'Feature' => [$data],
            'Point', 'LineString', 'Polygon', 'MultiPoint', 'MultiLineString', 'MultiPolygon', 'GeometryCollection' => [
                ['type' => 'Feature', 'geometry' => $data, 'properties' => []],
            ],
            default => throw ValidationException::withMessages(['file' => 'El archivo no es un GeoJSON válido.']),
        };

        foreach ($featureList as $feature) {
            $geometry = $feature['geometry'] ?? null;

            if (! is_array($geometry) || ($geometry['type'] ?? null) === null) {
                continue;
            }

            $this->collectGeoJsonGeometry($geometry, $feature['properties'] ?? [], null, $features);
        }

        return $features;
    }

    /**
     * @param  array<int, array{type: string, coordinates: array, name: ?string, description: ?string, source_folder: ?string, area_m2: ?float}>  $features
     */
    private function collectGeoJsonGeometry(array $geometry, array $properties, ?string $folder, array &$features): void
    {
        $name = $properties['identifier'] ?? $properties['name'] ?? null;
        $name = is_string($name) && trim($name) !== '' ? trim($name) : null;

        switch ($geometry['type']) {
            case 'Point':
                $features[] = ['type' => 'Point', 'coordinates' => $geometry['coordinates'], 'name' => $name, 'description' => $properties['description'] ?? null, 'source_folder' => $folder, 'extra' => $properties, 'area_m2' => null];

                break;

            case 'LineString':
                $features[] = ['type' => 'LineString', 'coordinates' => $geometry['coordinates'], 'name' => $name, 'description' => $properties['description'] ?? null, 'source_folder' => $folder, 'extra' => $properties, 'area_m2' => null];

                break;

            case 'Polygon':
                $features[] = ['type' => 'Polygon', 'coordinates' => $geometry['coordinates'], 'name' => $name, 'description' => $properties['description'] ?? null, 'source_folder' => $folder, 'extra' => $properties, 'area_m2' => $this->polygonAreaM2($geometry['coordinates'][0])];

                break;

            case 'MultiPoint':
            case 'MultiLineString':
            case 'MultiPolygon':
                foreach ($geometry['coordinates'] as $part) {
                    $this->collectGeoJsonGeometry(['type' => mb_substr($geometry['type'], 5), 'coordinates' => $part], $properties, $folder, $features);
                }

                break;

            case 'GeometryCollection':
                foreach ($geometry['geometries'] ?? [] as $nested) {
                    $this->collectGeoJsonGeometry($nested, $properties, $folder, $features);
                }

                break;
        }
    }

    /**
     * @param  array<int, array{type: string, coordinates: array, name: ?string, description: ?string, source_folder: ?string, extra?: array, area_m2: ?float}>  $features
     */
    private function createGeometries(MapImport $import, array $features): void
    {
        // Clasificacion por capa
        $classified = [];

        foreach ($features as $index => $feature) {
            $classified[$index] = [
                'feature' => $feature,
                'layer_slug' => $this->classifyLayer($feature),
                'identifier' => $this->normalizer->normalize($feature['name'] ?? null),
            ];
        }

        // Propuestas de asociacion para elementos que parecen lotes
        $lotItems = [];

        foreach ($classified as $index => $entry) {
            if ($entry['layer_slug'] === 'lotes') {
                $lotItems[$index] = [
                    'identifier' => $entry['feature']['name'] ?? null,
                    'name' => $entry['feature']['name'] ?? null,
                    'area_m2' => $entry['feature']['area_m2'],
                ];
            }
        }

        $proposals = $this->association->propose($lotItems, $import->etapa_id);
        $seenIdentifiers = [];

        $summary = ['total' => count($classified), 'by_layer' => [], 'by_match' => [], 'warnings' => 0];

        foreach ($classified as $index => $entry) {
            $feature = $entry['feature'];
            $layer = $this->persistence->layer($import->etapa_id, $entry['layer_slug']);
            $isLote = $entry['layer_slug'] === 'lotes';
            $proposal = $isLote ? $proposals[$index] : null;

            $properties = [
                'name' => $feature['name'],
                'description' => $feature['description'],
                'identifier' => $feature['name'],
                'source_folder' => $feature['source_folder'],
                'warnings' => [],
            ];

            if ($isLote && $proposal !== null) {
                $properties['warnings'] = $proposal['warnings'];
                $summary['warnings'] += count($proposal['warnings']);
            }

            $properties['extra'] = $feature['extra'] ?? [];

            $match = $proposal['match'] ?? null;
            $isExact = $isLote && $proposal !== null && $match === AssociationMatch::Exact;

            if ($isLote && $proposal !== null) {
                $slug = $match->value;
                $summary['by_match'][$slug] = ($summary['by_match'][$slug] ?? 0) + 1;
            }

            $summary['by_layer'][$layer->slug] = ($summary['by_layer'][$layer->slug] ?? 0) + 1;

            $import->geometries()->create([
                'map_layer_id' => $layer->id,
                'lote_id' => $isExact ? $proposal['lote_id'] : null,
                'geometry' => ['type' => $feature['type'], 'coordinates' => $feature['coordinates']],
                'properties' => $properties,
                'normalized_identifier' => $entry['identifier'],
                'association_status' => $isLote && $proposal !== null ? $match->value : 'none',
                'association_method' => 'auto',
                'is_confirmed' => $isExact,
                'match_confidence' => $isLote && $proposal !== null ? $proposal['confidence'] : null,
                'area_m2' => $feature['area_m2'],
            ]);
        }

        $import->update(['summary' => $summary]);
    }

    /**
     * @param  array{type: string, name: ?string, source_folder: ?string}  $feature
     */
    private function classifyLayer(array $feature): string
    {
        $text = mb_strtolower(trim(($feature['source_folder'] ?? '').' '.($feature['name'] ?? '')));
        $hasLotePrefix = preg_match('/\b(lote|parcela|lot|sitio|predio)\b/u', $text) === 1;

        foreach (self::LAYER_KEYWORDS as $slug => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    if ($slug === 'servidumbre_transito' && str_contains($text, 'servicio')) {
                        continue 2;
                    }

                    if ($slug === 'servidumbre_servicios' && str_contains($text, 'transito')) {
                        continue 2;
                    }

                    return $slug;
                }
            }
        }

        return $hasLotePrefix || preg_match('/^\s*\d+([-_ ]\d+)*[a-z]?\s*$/u', $text) === 1 ? 'lotes' : 'otras';
    }

    /**
     * Area aproximada (metros cuadrados) de un anillo exterior WGS84.
     *
     * @param  array<int, array{float, float}>  $ring
     */
    private function polygonAreaM2(array $ring): ?float
    {
        if (count($ring) < 3) {
            return null;
        }

        $lat0 = deg2rad($ring[0][1]);
        $scale = cos($lat0) * 111319.49; // metros por grado de longitud en esa latitud
        $meterPerDegLat = 110574.0;

        $area = 0.0;

        for ($i = 0, $n = count($ring); $i < $n; $i++) {
            [$x1, $y1] = [$ring[$i][0] * $scale, $ring[$i][1] * $meterPerDegLat];
            $j = ($i + 1) % $n;
            [$x2, $y2] = [$ring[$j][0] * $scale, $ring[$j][1] * $meterPerDegLat];

            $area += $x1 * $y2 - $x2 * $y1;
        }

        return round(abs($area) / 2.0, 2);
    }
}
