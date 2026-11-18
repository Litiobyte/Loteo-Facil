<?php

namespace App\Filament\Owner\Resources\Lotes\Pages;

use App\Filament\Owner\Resources\Lotes\LoteResource;
use App\Models\MapLayer;
use Filament\Resources\Pages\ViewRecord;

class ViewLote extends ViewRecord
{
    protected static string $resource = LoteResource::class;

    protected string $view = 'filament.owner.pages.view-lote';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getViewData(): array
    {
        $lote = $this->getRecord()->load('etapa');

        return [
            'lote' => $lote,
            'layerList' => $lote->etapa_id === null
                ? collect()
                : MapLayer::query()->where('etapa_id', $lote->etapa_id)->where('is_public', true)->orderBy('sort_order')->get(),
            'adminView' => false,
            'showFinancial' => false,
        ];
    }
}
