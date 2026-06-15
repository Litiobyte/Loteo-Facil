<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Collections\Enums\CollectionStatus;
use App\Filament\Admin\Resources\CollectionResource;
use App\Models\Collection;
use Filament\Widgets\Widget;

class LatestCollectionsTableWidget extends Widget
{
    protected static ?int $sort = 40;

    protected string $view = 'filament.admin.widgets.latest-collections-table-widget';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    protected function getViewData(): array
    {
        $rows = Collection::query()
            ->with('propietario')
            ->orderByDesc('collection_date')
            ->orderByDesc('id')
            ->limit(7)
            ->get()
            ->map(function (Collection $collection): array {
                return [
                    'id' => $collection->id,
                    'owner_name' => $collection->propietario?->nombre_completo ?? '-',
                    'collection_date' => $collection->collection_date?->format('d/m/Y') ?? '-',
                    'collection_method' => $collection->collection_method->getLabel(),
                    'amount' => round((float) $collection->amount, 2),
                    'status_label' => $this->statusLabel($collection->status),
                    'status_color' => $this->statusColor($collection->status),
                ];
            })
            ->all();

        return [
            'rows' => $rows,
            'collectionsUrl' => CollectionResource::getUrl('index', [], true, 'admin'),
        ];
    }

    private function statusLabel(CollectionStatus $status): string
    {
        return match ($status) {
            CollectionStatus::PendingApplication => 'Pendiente aplicación',
            CollectionStatus::PartiallyApplied => 'Parcialmente aplicado',
            CollectionStatus::FullyApplied => 'Totalmente aplicado',
            CollectionStatus::Cancelled => 'Cancelado',
        };
    }

    private function statusColor(CollectionStatus $status): string
    {
        return match ($status) {
            CollectionStatus::PendingApplication => 'gray',
            CollectionStatus::PartiallyApplied => 'warning',
            CollectionStatus::FullyApplied => 'success',
            CollectionStatus::Cancelled => 'danger',
        };
    }

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }
}
