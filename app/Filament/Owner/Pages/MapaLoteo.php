<?php

namespace App\Filament\Owner\Pages;

use App\Domain\Maps\Services\MapPersistenceService;
use App\Models\CommunitySetting;
use App\Models\Map;
use App\Models\MapGeometry;
use App\Models\MapLayer;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class MapaLoteo extends Page
{
    public static function shouldRegisterNavigation(): bool
    {
        return CommunitySetting::current()->usesLots() && static::canAccess();
    }

    protected static string|UnitEnum|null $navigationGroup = 'Mis Datos';

    protected static ?string $navigationLabel = 'Mapa del Loteo';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.owner.pages.mapa-loteo';

    public ?int $etapaId = null;

    public ?int $selectedGeometryId = null;

    public array $selectedFeatureInfo = [];

    public function mount(): void
    {
        $this->etapaId = Map::query()->whereNotNull('published_import_id')->orderBy('etapa_id')->value('etapa_id');
    }

    public function updatedEtapaId(): void
    {
        $this->selectedGeometryId = null;
        $this->selectedFeatureInfo = [];
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->propietario !== null;
    }

    /**
     * Info del lote seleccionada. Los permisos se aplican aqui (backend):
     * un propietario nunca recibe datos privados/financieros de terceros.
     */
    public function selectFeature(int $geometryId): void
    {
        $publishedImportIds = Map::query()->whereNotNull('published_import_id')->pluck('published_import_id');

        $geometry = MapGeometry::query()
            ->with(['lote.propietarios.user', 'layer'])
            ->whereIn('map_import_id', $publishedImportIds)
            ->where('is_confirmed', true)
            ->whereHas('layer', fn ($query) => $query->where('is_public', true))
            ->find($geometryId);

        $this->selectedGeometryId = $geometryId;

        if ($geometry === null) {
            $this->selectedFeatureInfo = [];

            return;
        }

        $lote = $geometry->lote;
        $propietario = Auth::user()?->propietario;

        if ($lote === null) {
            $this->selectedFeatureInfo = [
                'kind' => 'geometry',
                'name' => $geometry->properties['name'] ?? '(sin nombre)',
                'layer' => $geometry->layer->name,
            ];

            return;
        }

        $isOwn = $lote->propietarios()
            ->where('propietarios.id', $propietario?->id)
            ->wherePivot('status', 'active')
            ->exists();

        if ($isOwn) {
            $this->selectedFeatureInfo = [
                'kind' => 'own_lote',
                'codigo' => $lote->codigo,
                'metros_cuadrados' => $lote->metros_cuadrados,
                'estado' => $lote->estado,
                'detail_url' => EstadoDeCuenta::getUrl(),
            ];

            return;
        }

        // Informacion publica del loteo: identificador y ubicacion, sin datos privados
        $this->selectedFeatureInfo = [
            'kind' => 'lote',
            'codigo' => $lote->codigo,
            'metros_cuadrados' => $lote->metros_cuadrados,
        ];
    }

    public function highlightFeature(int $geometryId): void
    {
        $this->selectedGeometryId = $geometryId;
        $this->dispatch('map-focus', id: $geometryId);
    }

    protected function getViewData(): array
    {
        $etapasConMapa = Map::query()->whereNotNull('published_import_id')->orderBy('etapa_id')->pluck('etapa_id');

        $featureCollection = ['type' => 'FeatureCollection', 'features' => []];
        $layerList = collect();

        if ($this->etapaId !== null) {
            $featureCollection = app(MapPersistenceService::class)->publishedGeoJson((int) $this->etapaId, false);
            $layerList = MapLayer::query()->where('etapa_id', $this->etapaId)->where('is_public', true)->orderBy('sort_order')->get();
        }

        return [
            'etapas' => $etapasConMapa,
            'featureCollection' => $featureCollection,
            'layerList' => $layerList,
        ];
    }
}
