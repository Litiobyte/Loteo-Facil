<?php

namespace App\Filament\Admin\Resources\ShareHoldingResource\Pages;

use App\Domain\Owners\Services\ShareHoldingService;
use App\Filament\Admin\Resources\ShareHoldingResource;
use App\Support\ChileanNumber;
use Filament\Resources\Pages\ManageRecords;

class ManageShareHoldings extends ManageRecords
{
    protected static string $resource = ShareHoldingResource::class;

    public function getSubheading(): ?string
    {
        $totals = app(ShareHoldingService::class)->totals();

        return "Emitidas: {$totals['issued']} — Vigentes: {$totals['active']} — Superficie equivalente vigente: ".ChileanNumber::format($totals['equivalent_square_meters']).' m²';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
