<?php

namespace App\Filament\Owner\Resources\Lotes\Pages;

use App\Filament\Owner\Resources\Lotes\LoteResource;
use App\Models\MapGeometry;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class ListMisLotes extends ListRecords
{
    protected static string $resource = LoteResource::class;

    protected string $view = 'filament.owner.pages.mis-lotes';

    public bool $showCards = false;

    /** @return array{count: int, hectares: float} */
    public function getOwnershipSummary(): array
    {
        $totals = LoteResource::getEloquentQuery()
            ->selectRaw('COUNT(*) as lot_count, COALESCE(SUM(metros_cuadrados), 0) as square_meters')
            ->first();

        return [
            'count' => (int) $totals->lot_count,
            'hectares' => (float) $totals->square_meters / 10000,
        ];
    }

    public function getSubheading(): ?string
    {
        $summary = $this->getOwnershipSummary();

        return $summary['count'].' lotes · '.number_format($summary['hectares'], 2, ',', '.').' ha totales · Asignaciones activas';
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getCardFeatures(EloquentCollection $records): Collection
    {
        $ownedIds = LoteResource::getEloquentQuery()->whereKey($records->modelKeys())->pluck('lotes.id');

        return MapGeometry::query()
            ->select(['map_geometries.id', 'map_geometries.lote_id', 'map_geometries.geometry'])
            ->join('map_layers', 'map_layers.id', '=', 'map_geometries.map_layer_id')
            ->join('maps', 'maps.published_import_id', '=', 'map_geometries.map_import_id')
            ->join('lotes', 'lotes.id', '=', 'map_geometries.lote_id')
            ->whereColumn('maps.etapa_id', 'lotes.etapa_id')
            ->whereColumn('map_layers.etapa_id', 'lotes.etapa_id')
            ->whereIn('map_geometries.lote_id', $ownedIds)
            ->where('map_layers.is_public', true)
            ->where('map_geometries.is_confirmed', true)
            ->get()
            ->groupBy('lote_id')
            ->filter(fn (Collection $matches): bool => $matches->count() === 1)
            ->map(fn (Collection $matches): array => [
                'type' => 'Feature',
                'id' => $matches->first()->id,
                'geometry' => $matches->first()->geometry,
                'properties' => ['lote_id' => (int) $matches->first()->lote_id],
            ]);
    }

    public function toggleLayout(): void
    {
        $this->showCards = ! $this->showCards;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleLayout')
                ->label(fn (): string => $this->showCards ? 'Ver listado' : 'Ver tarjetas')
                ->icon(fn (): string => $this->showCards ? 'heroicon-o-list-bullet' : 'heroicon-o-squares-2x2')
                ->action(fn () => $this->toggleLayout()),
        ];
    }
}
