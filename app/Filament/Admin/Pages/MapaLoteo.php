<?php

namespace App\Filament\Admin\Pages;

use App\Domain\Maps\Services\GeoFileImporter;
use App\Domain\Maps\Services\MapPersistenceService;
use App\Filament\Resources\Lotes\LoteResource;
use App\Models\CommunitySetting;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\MapImport;
use App\Models\MapLayer;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use UnitEnum;

class MapaLoteo extends Page
{
    public static function shouldRegisterNavigation(): bool
    {
        return CommunitySetting::current()->usesLots() && static::canAccess();
    }

    use WithFileUploads;

    protected static string|UnitEnum|null $navigationGroup = 'Loteo';

    protected static ?string $navigationLabel = 'Mapa del Loteo';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.admin.pages.mapa-loteo';

    public ?int $etapaId = null;

    public ?int $importId = null;

    public $file = null;

    /** @var array<int, array{confirm: bool, lote_id: ?int, layer_id: int}> */
    public array $decisions = [];

    /** @var array<int, array{color: string, opacity: int, is_visible: bool, is_public: bool, name: string}> */
    public array $layerForm = [];

    public string $statusFilter = 'all';

    public ?int $selectedGeometryId = null;

    public array $selectedFeatureInfo = [];

    public function mount(): void
    {
        $this->etapaId = Etapa::query()->orderBy('id')->value('id');
        $this->refreshDecisions();
        $this->refreshLayerForm();
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public function updatedEtapaId(): void
    {
        $this->importId = null;
        $this->selectedGeometryId = null;
        $this->selectedFeatureInfo = [];
        $this->refreshDecisions();
        $this->refreshLayerForm();
    }

    public function updatedFile(): void
    {
        $this->validateOnly('file', [
            'file' => [
                'required',
                'file',
                'extensions:kmz,kml,geojson,json',
                'max:'.(int) config('map.max_upload_kb'),
            ],
        ]);
    }

    public function import(GeoFileImporter $importer): void
    {
        $this->validate([
            'etapaId' => ['required', 'integer', 'exists:etapas,id'],
            'file' => [
                'required',
                'file',
                'extensions:kmz,kml,geojson,json',
                'max:'.(int) config('map.max_upload_kb'),
            ],
        ]);

        /** @var TemporaryUploadedFile $file */
        $file = $this->file;

        $import = $importer->importFromFile($file->getRealPath(), $file->getClientOriginalName(), (int) $this->etapaId, (int) Auth::id());

        $this->importId = $import->id;
        $this->file = null;
        $this->statusFilter = 'all';
        $this->refreshDecisions();
        $this->refreshLayerForm();
    }

    public function confirmAllExact(): void
    {
        MapImport::query()->findOrFail($this->importId)
            ->geometries()
            ->where('association_status', 'exact')
            ->update(['is_confirmed' => true]);

        $this->refreshDecisions();
    }

    public function toggleConfirm(int $geometryId): void
    {
        if (isset($this->decisions[$geometryId])) {
            $this->decisions[$geometryId]['confirm'] = ! $this->decisions[$geometryId]['confirm'];
        }
    }

    public function setLote(int $geometryId, ?int $loteId): void
    {
        if (isset($this->decisions[$geometryId])) {
            $this->decisions[$geometryId]['lote_id'] = $loteId;
        }
    }

    public function setLayer(int $geometryId, int $layerId): void
    {
        if (isset($this->decisions[$geometryId])) {
            $this->decisions[$geometryId]['layer_id'] = $layerId;
        }
    }

    public function applyReview(?MapPersistenceService $persistence = null): void
    {
        $import = MapImport::query()->findOrFail($this->importId);

        app(MapPersistenceService::class)->applyReview($import, $this->decisions);
        $this->refreshDecisions();
    }

    public function publish(): void
    {
        $import = MapImport::query()->findOrFail($this->importId);

        app(MapPersistenceService::class)->applyReview($import, $this->decisions);
        app(MapPersistenceService::class)->publish($import);

        $this->importId = null;
        $this->refreshDecisions();
    }

    public function selectFeature(int $geometryId): void
    {
        $import = MapImport::query()->find($this->importId);
        $geometry = $import?->geometries()->with(['lote.propietarios.user', 'lote.etapa'])->find($geometryId);

        $this->selectedGeometryId = $geometryId;

        if ($geometry === null) {
            $this->selectedFeatureInfo = [];

            return;
        }

        $lote = $geometry->lote;

        if ($lote !== null) {
            $this->selectedFeatureInfo = [
                'kind' => 'lote',
                'codigo' => $lote->codigo,
                'metros_cuadrados' => $lote->metros_cuadrados,
                'estado' => $lote->estado,
                'valor_lote' => $lote->valor_lote,
                'propietarios' => $lote->propietarios->pluck('user.name')->filter()->values()->all(),
                'detail_url' => LoteResource::getUrl('view', ['record' => $lote]),
            ];

            return;
        }

        $this->selectedFeatureInfo = [
            'kind' => 'geometry',
            'name' => $geometry->properties['name'] ?? '(sin nombre)',
            'layer' => $geometry->layer->name,
            'source_folder' => $geometry->properties['source_folder'] ?? null,
        ];
    }

    public function highlightFeature(int $geometryId): void
    {
        $this->selectedGeometryId = $geometryId;
        $this->dispatch('map-focus', id: $geometryId);
    }

    public function saveLayers(): void
    {
        foreach ($this->layerForm as $layerId => $values) {
            $layer = MapLayer::query()
                ->where('etapa_id', $this->etapaId)
                ->whereKey($layerId)
                ->first();

            if ($layer !== null) {
                $layer->update([
                    'name' => $values['name'],
                    'color' => $values['color'],
                    'opacity' => (int) $values['opacity'],
                    'is_visible' => (bool) $values['is_visible'],
                    'is_public' => (bool) $values['is_public'],
                ]);
            }
        }

        $this->refreshLayerForm();
    }

    protected function getViewData(): array
    {
        $import = $this->importId !== null ? MapImport::query()->find($this->importId) : null;

        return [
            'etapas' => Etapa::query()->orderBy('numero')->get(['id', 'numero', 'nombre']),
            'lotes' => Lote::query()->where('etapa_id', $this->etapaId)->orderBy('codigo')->get(['id', 'codigo']),
            'import' => $import,
            'reviewRows' => $import !== null ? $this->reviewRows($import) : [],
            'layerList' => MapLayer::query()->where('etapa_id', $this->etapaId)->orderBy('sort_order')->get(),
            'history' => MapImport::query()->where('etapa_id', $this->etapaId)->latest('id')->take(20)->get(),
            'featureCollection' => $import !== null ? $this->previewGeoJson($import) : app(MapPersistenceService::class)->publishedGeoJson((int) $this->etapaId, true),
            'stats' => $import?->summary ?? [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reviewRows(MapImport $import): array
    {
        $layers = MapLayer::query()->where('etapa_id', $import->etapa_id)->orderBy('sort_order')->get();

        $rows = $import->geometries()
            ->with(['lote', 'layer'])
            ->orderBy('id')
            ->get()
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('association_status', $this->statusFilter))
            ->filter(fn ($g) => $this->statusFilter === 'all' || $g->association_status === $this->statusFilter)
            ->map(fn ($g): array => [
                'id' => $g->id,
                'name' => $g->properties['name'] ?? '(sin nombre)',
                'layer_id' => $g->map_layer_id,
                'layer_options' => $layers,
                'lote_id' => $g->lote_id,
                'proposed_lote_id' => $g->lote_id,
                'match' => $g->association_status,
                'confidence' => $g->match_confidence,
                'confirmed' => $this->decisions[$g->id]['confirm'] ?? $g->is_confirmed,
                'warnings' => $g->properties['warnings'] ?? [],
            ])
            ->values()
            ->all();

        return $rows;
    }

    private function previewGeoJson(MapImport $import): array
    {
        $layers = MapLayer::query()->where('etapa_id', $import->etapa_id)->get()->keyBy('id');
        $features = [];

        foreach ($import->geometries as $geometry) {
            /** @var MapLayer $layer */
            $layer = $layers[$geometry->map_layer_id];

            $features[] = [
                'type' => 'Feature',
                'id' => $geometry->id,
                'geometry' => $geometry->geometry,
                'properties' => [
                    'name' => $geometry->properties['name'] ?? null,
                    'layer_id' => $layer->id,
                    'layer_slug' => $layer->slug,
                    'layer_name' => $layer->name,
                    'color' => $geometry->association_status === 'exact' ? '#22c55e' : ($geometry->association_status === 'probable' ? '#f59e0b' : ($geometry->association_status === 'ambiguous' ? '#ef4444' : $layer->color)),
                    'opacity' => $layer->opacity / 100,
                    'lote_id' => $geometry->lote_id,
                    'lote_code' => $geometry->lote?->codigo,
                    'association_status' => $geometry->association_status,
                ],
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    private function refreshDecisions(): void
    {
        $this->decisions = [];

        if ($this->importId === null) {
            return;
        }

        $import = MapImport::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        foreach ($import->geometries as $geometry) {
            $this->decisions[$geometry->id] = [
                'confirm' => $geometry->is_confirmed,
                'lote_id' => $geometry->lote_id,
                'layer_id' => $geometry->map_layer_id,
            ];
        }
    }

    private function refreshLayerForm(): void
    {
        $this->layerForm = [];

        if ($this->etapaId === null) {
            return;
        }

        app(MapPersistenceService::class)->ensureDefaultLayers((int) $this->etapaId);

        foreach (MapLayer::query()->where('etapa_id', $this->etapaId)->orderBy('sort_order')->get() as $layer) {
            $this->layerForm[$layer->id] = [
                'name' => $layer->name,
                'color' => $layer->color,
                'opacity' => $layer->opacity,
                'is_visible' => $layer->is_visible,
                'is_public' => $layer->is_public,
            ];
        }
    }
}
