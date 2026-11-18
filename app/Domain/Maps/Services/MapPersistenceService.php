<?php

namespace App\Domain\Maps\Services;

use App\Domain\Maps\Enums\AssociationMatch;
use App\Domain\Maps\Enums\AssociationMethod;
use App\Models\Lote;
use App\Models\Map;
use App\Models\MapGeometry;
use App\Models\MapImport;
use App\Models\MapLayer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Crea capas por defecto, aplica decisiones de revision y publica importaciones
 * conservando asociaciones confirmadas cuando los identificadores coinciden.
 */
class MapPersistenceService
{
    public const DEFAULT_LAYERS = [
        ['slug' => 'lotes', 'name' => 'Lotes y parcelas', 'color' => '#f59e0b', 'is_public' => true],
        ['slug' => 'caminos', 'name' => 'Caminos y accesos', 'color' => '#8b5cf6', 'is_public' => true],
        ['slug' => 'servidumbre_transito', 'name' => 'Servidumbres de tránsito', 'color' => '#06b6d4', 'is_public' => true],
        ['slug' => 'servidumbre_servicios', 'name' => 'Servidumbres de servicios', 'color' => '#10b981', 'is_public' => true],
        ['slug' => 'areas_comunes', 'name' => 'Áreas comunes', 'color' => '#3b82f6', 'is_public' => true],
        ['slug' => 'areas_verdes', 'name' => 'Áreas verdes', 'color' => '#22c55e', 'is_public' => true],
        ['slug' => 'rio', 'name' => 'Río', 'color' => '#0ea5e9', 'is_public' => true],
        ['slug' => 'infraestructura', 'name' => 'Infraestructura', 'color' => '#6b7280', 'is_public' => false],
        ['slug' => 'otras', 'name' => 'Otras capas', 'color' => '#e11d48', 'is_public' => false],
    ];

    public function ensureDefaultLayers(int $etapaId): void
    {
        foreach (self::DEFAULT_LAYERS as $index => $layer) {
            MapLayer::query()->firstOrCreate(
                ['etapa_id' => $etapaId, 'slug' => $layer['slug']],
                [
                    'name' => $layer['name'],
                    'color' => $layer['color'],
                    'opacity' => 60,
                    'sort_order' => $index,
                    'is_visible' => true,
                    'is_public' => $layer['is_public'],
                ],
            );
        }
    }

    public function layer(int $etapaId, string $slug): MapLayer
    {
        $layer = MapLayer::query()->where('etapa_id', $etapaId)->where('slug', $slug)->first();

        if ($layer === null) {
            $this->ensureDefaultLayers($etapaId);
            $layer = MapLayer::query()->where('etapa_id', $etapaId)->where('slug', $slug)->firstOrFail();
        }

        return $layer;
    }

    /**
     * Aplica las decisiones de la pantalla de revision y marca la importacion lista para publicar.
     *
     * @param  array<int, array{confirm: bool|int|string, lote_id?: int|string|null, layer_id?: int|string}>  $decisions  indexado por geometry id
     */
    public function applyReview(MapImport $import, array $decisions): void
    {
        foreach ($decisions as &$decision) {
            if (array_key_exists('lote_id', $decision) && $decision['lote_id'] === '') {
                $decision['lote_id'] = null;
            }
        }
        unset($decision);

        Validator::make(['decisions' => $decisions], [
            'decisions.*.confirm' => ['required', 'boolean'],
            'decisions.*.lote_id' => [
                'nullable',
                'integer',
                Rule::exists('lotes', 'id')->where('etapa_id', $import->etapa_id),
            ],
            'decisions.*.layer_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('map_layers', 'id')->where('etapa_id', $import->etapa_id),
            ],
        ])->validate();

        DB::transaction(function () use ($import, $decisions): void {
            foreach ($import->geometries as $geometry) {
                $decision = $decisions[$geometry->id] ?? null;

                if ($decision === null) {
                    continue;
                }

                if (array_key_exists('layer_id', $decision) && (int) $decision['layer_id'] !== $geometry->map_layer_id) {
                    $geometry->map_layer_id = (int) $decision['layer_id'];
                    $geometry->association_method = AssociationMethod::Manual->value;
                }

                if (array_key_exists('lote_id', $decision)) {
                    $geometry->lote_id = $decision['lote_id'] === null ? null : (int) $decision['lote_id'];

                    if ($geometry->lote_id === null) {
                        $geometry->association_status = AssociationMatch::None->value;
                        $geometry->match_confidence = null;
                    } elseif ($geometry->isDirty('lote_id') || $geometry->match() !== AssociationMatch::Exact) {
                        $geometry->association_status = 'exact';
                        $geometry->association_method = AssociationMethod::Manual->value;
                        $geometry->match_confidence = 100;
                    }
                }

                $geometry->is_confirmed = in_array($decision['confirm'], [true, 1, '1'], true);
                $geometry->save();
            }

            $import->update(['status' => 'reviewing', 'summary' => array_merge($import->summary ?? [], ['reviewed' => true])]);
        });
    }

    /**
     * Publica una importacion revisada: se convierte en la version visible del mapa.
     * Conserva asociaciones del mapa publicado anterior por identificador normalizado.
     */
    public function publish(MapImport $import): void
    {
        $map = Map::query()->firstOrCreate(['etapa_id' => $import->etapa_id]);
        $previousImportId = $map->published_import_id;

        if ($previousImportId !== null && $previousImportId !== $import->id) {
            $previous = MapImport::query()->find($previousImportId);
            $carried = $this->carryOverAssociations($import, $previous);

            $import->update(['summary' => array_merge($import->summary ?? [], ['carried_associations' => $carried])]);
        }

        $map->update(['published_import_id' => $import->id]);

        // Versiones anteriores quedan en historial, no se publican
        MapImport::query()
            ->where('etapa_id', $import->etapa_id)
            ->where('id', '!=', $import->id)
            ->where('status', 'published')
            ->update(['status' => 'discarded']);

        $import->update(['status' => 'published']);
    }

    /**
     * Copia lote_id de geometrias publicadas anteriores cuando el identificador normalizado coincide.
     */
    private function carryOverAssociations(MapImport $new, ?MapImport $previous): int
    {
        if ($previous === null) {
            return 0;
        }

        $carried = 0;

        $previousMap = $previous->geometries
            ->filter(fn (MapGeometry $g): bool => $g->lote_id !== null)
            ->keyBy(fn (MapGeometry $g): string => (string) $g->normalized_identifier);

        foreach ($new->geometries as $geometry) {
            if ($geometry->lote_id !== null || $geometry->normalized_identifier === null) {
                continue;
            }

            $old = $previousMap->get((string) $geometry->normalized_identifier);

            if ($old !== null && $old->lote_id !== null) {
                $geometry->update([
                    'lote_id' => $old->lote_id,
                    'association_status' => 'exact',
                    'association_method' => AssociationMethod::Carried->value,
                    'match_confidence' => 100,
                ]);
                $carried++;
            }
        }

        return $carried;
    }

    /**
     * GeoJSON del mapa publicado para un panel dado (public o admin).
     *
     * @return array<string, mixed>
     */
    public function publishedGeoJson(int $etapaId, bool $adminView): array
    {
        $map = Map::query()->where('etapa_id', $etapaId)->first();

        if ($map === null || $map->published_import_id === null) {
            return ['type' => 'FeatureCollection', 'features' => []];
        }

        $layers = MapLayer::query()->where('etapa_id', $etapaId)
            ->when(! $adminView, fn ($q) => $q->where('is_public', true))
            ->get()->keyBy('id');

        $geometries = MapGeometry::query()
            ->with(['lote.propietarios.user'])
            ->whereIn('map_layer_id', $layers->keys())
            ->where('map_import_id', $map->published_import_id)
            ->get();

        // Asignaciones activas por lote para evitar N consultas de propiedad
        $loteIds = $geometries->pluck('lote_id')->filter()->unique()->values();

        if (! $adminView && $loteIds->isNotEmpty()) {
            $ownLoteIds = DB::table('lote_propietario')
                ->where('propietario_id', Auth::user()?->propietario?->id)
                ->where('status', 'active')
                ->whereIn('lote_id', $loteIds)
                ->pluck('lote_id')
                ->flip();
        } else {
            $ownLoteIds = collect();
        }

        $features = [];

        foreach ($geometries as $geometry) {
            /** @var MapLayer $layer */
            $layer = $layers[$geometry->map_layer_id];
            $lote = $geometry->lote;

            $isOwn = $lote !== null && $ownLoteIds->has($lote->id);

            $features[] = [
                'type' => 'Feature',
                'id' => $geometry->id,
                'geometry' => $geometry->geometry,
                'properties' => [
                    'name' => $geometry->properties['name'] ?? null,
                    'layer_id' => $layer->id,
                    'layer_slug' => $layer->slug,
                    'layer_name' => $layer->name,
                    'color' => $layer->color,
                    'opacity' => $layer->opacity / 100,
                    'lote_id' => $lote?->id,
                    'lote_code' => $lote?->codigo,
                    'association_status' => $geometry->association_status,
                    'is_own' => $isOwn,
                ],
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * Ubicación publicada de un lote para detalle/vista compartida.
     * Usa la versión publicada de la etapa del lote; nunca propuestas sin confirmar.
     *
     * @return array{status: string, feature: array<string, mixed>|null, etapa_id: ?int}
     */
    public function loteFeature(int $loteId, bool $adminView): array
    {
        $lote = Lote::query()->find($loteId);

        if ($lote === null) {
            return ['status' => 'lote_inexistente', 'feature' => null, 'etapa_id' => null];
        }

        $etapaId = $lote->etapa_id;
        $map = Map::query()->where('etapa_id', $etapaId)->first();

        if ($map === null || $map->published_import_id === null) {
            return ['status' => 'sin_mapa', 'feature' => null, 'etapa_id' => $etapaId];
        }

        $layers = MapLayer::query()->where('etapa_id', $etapaId)
            ->when(! $adminView, fn ($q) => $q->where('is_public', true))
            ->get()->keyBy('id');

        $query = MapGeometry::query()
            ->whereIn('map_layer_id', $layers->keys())
            ->where('map_import_id', $map->published_import_id)
            ->where('lote_id', $loteId);

        if (! $adminView) {
            $query->where('is_confirmed', true);
        }

        $matches = $query->get();

        if ($matches->count() > 1) {
            return ['status' => 'duplicado', 'feature' => null, 'etapa_id' => $etapaId];
        }

        if ($matches->isEmpty()) {
            return ['status' => 'sin_asociacion', 'feature' => null, 'etapa_id' => $etapaId];
        }

        /** @var MapGeometry $geometry */
        $geometry = $matches->first();
        /** @var MapLayer $layer */
        $layer = $layers[$geometry->map_layer_id];

        return [
            'status' => 'ok',
            'etapa_id' => $etapaId,
            'feature' => [
                'type' => 'Feature',
                'id' => $geometry->id,
                'geometry' => $geometry->geometry,
                'properties' => [
                    'name' => $lote->codigo,
                    'layer_id' => $layer->id,
                    'layer_slug' => $layer->slug,
                    'layer_name' => $layer->name,
                    'color' => $layer->color,
                    'opacity' => $layer->opacity / 100,
                    'lote_id' => $loteId,
                    'lote_code' => $lote->codigo,
                    'association_status' => $geometry->association_status,
                    'is_own' => true,
                ],
            ],
        ];
    }
}
