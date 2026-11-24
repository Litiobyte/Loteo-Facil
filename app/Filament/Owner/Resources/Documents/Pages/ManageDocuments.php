<?php

namespace App\Filament\Owner\Resources\Documents\Pages;

use App\Filament\Owner\Resources\Documents\DocumentResource;
use App\Models\Collection;
use App\Models\CommunitySetting;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\ShareHolding;
use App\Models\ShareMovement;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageDocuments extends ManageRecords
{
    protected static string $resource = DocumentResource::class;

    public static function canAccess(array $parameters = []): bool
    {
        return DocumentResource::canAccess();
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todos'),
            'community' => $this->category('Comunidad', [CommunitySetting::class]),
            'shares' => $this->category('Mis acciones', [Propietario::class, ShareHolding::class, ShareMovement::class]),
            'lots' => $this->category('Mis lotes', [Lote::class]),
            'financial' => $this->category('Comprobantes financieros', [Collection::class, PartnerCharge::class]),
        ];
    }

    private function category(string $label, array $types): Tab
    {
        return Tab::make($label)->modifyQueryUsing(
            fn (Builder $query): Builder => $query->whereHas('links', fn (Builder $links): Builder => $links->whereIn('documentable_type', $types))
        );
    }
}
