<?php

namespace App\Filament\Resources\Lotes\Pages;

use App\Domain\Maps\Services\MapPersistenceService;
use App\Filament\Resources\Lotes\LoteResource;
use App\Models\Lote;
use App\Models\MapLayer;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\View\View;

class ManageLotes extends ManageRecords
{
    protected static string $resource = LoteResource::class;

    public bool $mapVisible = false;

    public ?int $selectedLoteId = null;

    public ?int $selectedEtapaId = null;

    public ?string $selectedStatus = null;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('vista_compartida')
                ->label(fn (): string => $this->mapVisible ? 'Solo lista' : 'Lista y mapa')
                ->icon(fn (): string => $this->mapVisible ? 'heroicon-o-list-bullet' : 'heroicon-o-map')
                ->action(fn () => $this->toggleMapa()),
        ];
    }

    public function toggleMapa(): void
    {
        $this->mapVisible = ! $this->mapVisible;
    }

    /**
     * Vista compartida: carga el mapa publicado de la etapa del lote seleccionado
     * y destaca su geometría. Solo lectura, no modifica datos.
     */
    public function verEnMapa(Lote $lote): void
    {
        $this->mapVisible = true;
        $this->selectedLoteId = $lote->id;
        $this->selectedEtapaId = $lote->etapa_id;
    }

    public function getFooter(): ?View
    {
        if (! $this->mapVisible || $this->selectedLoteId === null) {
            return null;
        }

        $service = app(MapPersistenceService::class);
        $result = $service->loteFeature((int) $this->selectedLoteId, true);
        $this->selectedStatus = $result['status'];

        $featureCollection = $result['etapa_id'] !== null
            ? $service->publishedGeoJson((int) $result['etapa_id'], true)
            : ['type' => 'FeatureCollection', 'features' => []];

        $layerList = MapLayer::query()
            ->where('etapa_id', $result['etapa_id'] ?? 0)
            ->orderBy('sort_order')
            ->get();

        return view('filament.maps.lote-map-panel', [
            'lote' => Lote::query()->find($this->selectedLoteId),
            'status' => $result['status'],
            'featureCollection' => $featureCollection,
            'layerList' => $layerList,
            'selectedEtapaId' => $result['etapa_id'],
            'selectedLoteId' => $this->selectedLoteId,
        ]);
    }
}
